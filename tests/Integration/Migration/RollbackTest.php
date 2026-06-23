<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\Rollback;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 4: Rollback — exact, ordered, idempotent, non-destructive.
 *
 * Rollback reverses a migration that has NOT been finalized, deleting ONLY the
 * records the migration made and restoring backed-up options, leaving legacy data
 * byte-for-byte unchanged. The HARD CONSTRAINT (B2a fix-10): the native
 * (category/post_tag/custom) term_relationships that SermonWriter::mirrorNativeTaxonomies
 * inserted DIRECTLY via $wpdb (without bumping the shared wp_term_taxonomy.count)
 * MUST be deleted DIRECTLY via $wpdb + recounted once — NEVER via wp_delete_post /
 * wp_set_object_terms, which would decrement the church's own shared term counts
 * below their true value.
 *
 * After run():
 *  - migration-made sermons/podcasts/terms/comments are gone;
 *  - un-stamped partial-orphan sermonator posts are swept;
 *  - orphaned comments carrying LEGACY_COMMENT_ID are removed;
 *  - migration-created sermonator_* options are deleted; backed-up native options
 *    are restored;
 *  - state retreats migrated → detected;
 *  - ZERO records carry any LEGACY_POST_ID / LEGACY_TERM_ID / LEGACY_COMMENT_ID;
 *  - the church's SHARED native term counts are byte-equal to pre-migration;
 *  - legacy posts/meta/terms/relationships/comments/options are byte-equal.
 *  - refuses when state==='finalized';
 *  - admin-edited migrated posts surface in warnings;
 *  - a native post carrying a STRAY back-ref but NO live legacy source is NOT deleted;
 *  - idempotent: an interrupted re-run completes cleanly.
 */
final class RollbackTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Seeding + driving a full migration
    // -------------------------------------------------------------------------

    /**
     * Seed a legacy dataset with a NATIVE (non-sermonator) category assigned to a
     * legacy sermon so the mirrorNativeTaxonomies direct-insert path runs, plus a
     * comment, podcast, default-podcast pointer, and a pre-existing native
     * sermonator_* option (so the migration backs it up and rollback restores it).
     *
     * @return array{sermons:list<int>, podcast:int, preacher:int, series:int, comment:int, nativeCatTtId:int, nativeCatTermId:int}
     */
    private function seedDataset(): array {
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $series   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );

        // A NATIVE shared term (the church's own category) assigned to a legacy
        // sermon. The migration mirrors it via a DIRECT $wpdb relationship insert
        // onto the new sermon without bumping the shared count — the rollback
        // constraint hinges on this.
        $nativeCatTermId = (int) self::factory()->category->create( array( 'name' => 'Shared Church Category' ) );
        $nativeCatTtId   = (int) get_term_field( 'term_taxonomy_id', $nativeCatTermId, 'category' );

        $sermons = array();
        for ( $i = 0; $i < 2; $i++ ) {
            $sid = $this->fixture->createSermon();
            wp_set_object_terms( $sid, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
            wp_set_object_terms( $sid, array( $series ), LegacyIdentifiers::TAX_SERIES );
            $sermons[] = $sid;
        }

        // Assign the native category to the FIRST legacy sermon.
        wp_set_object_terms( $sermons[0], array( $nativeCatTermId ), 'category' );

        // A comment on the first legacy sermon (gets copied with a LEGACY_COMMENT_ID).
        $comment = $this->fixture->createComment( $sermons[0], '1' );

        $podcast = $this->fixture->createPodcast( 'Sunday Feed' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );

        // A pre-existing NATIVE sermonator_* option (a church that already had the
        // new plugin's option set). The migration must back it up so rollback can
        // restore the original native value.
        update_option( Identifiers::OPTION_TERM_IMAGES, array( 'native' => 'preexisting' ) );
        $this->fixture->seedArtwork( array(
            (int) get_term_field( 'term_taxonomy_id', $preacher, LegacyIdentifiers::TAX_PREACHER ) => 555,
        ) );

        return array(
            'sermons'         => $sermons,
            'podcast'         => $podcast,
            'preacher'        => $preacher,
            'series'          => $series,
            'comment'         => $comment,
            'nativeCatTtId'   => $nativeCatTtId,
            'nativeCatTermId' => $nativeCatTermId,
        );
    }

    private function migrateToCompletion(): void {
        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 100 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(), 'Setup must reach migrated.' );
    }

    /**
     * Snapshot every legacy row that the migration (and rollback) must leave
     * byte-for-byte unchanged: legacy posts (+meta), legacy terms (+relationships),
     * legacy comments, and the legacy options.
     *
     * @return array<string,mixed>
     */
    private function legacySnapshot(): array {
        global $wpdb;

        $legacyPostTypes = array( LegacyIdentifiers::POST_TYPE_SERMON, LegacyIdentifiers::POST_TYPE_PODCAST );
        $placeholders    = implode( ',', array_fill( 0, count( $legacyPostTypes ), '%s' ) );

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->posts} WHERE post_type IN ( {$placeholders} ) ORDER BY ID ASC",
                ...$legacyPostTypes
            ),
            ARRAY_A
        );
        $postIds = array_map( static fn( $r ) => (int) $r['ID'], $posts );

        $postMeta = array();
        foreach ( $postIds as $pid ) {
            $postMeta[ $pid ] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
                    $pid
                ),
                ARRAY_A
            );
        }

        $legacyTaxonomies = LegacyIdentifiers::sermonTaxonomies();
        $taxPlaceholders  = implode( ',', array_fill( 0, count( $legacyTaxonomies ), '%s' ) );
        $termTax = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, tt.parent, tt.count, t.name, t.slug"
                . " FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
                . " WHERE tt.taxonomy IN ( {$taxPlaceholders} ) ORDER BY tt.term_taxonomy_id ASC",
                ...$legacyTaxonomies
            ),
            ARRAY_A
        );

        $relationships = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.object_id, tr.term_taxonomy_id, tr.term_order FROM {$wpdb->term_relationships} tr"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                . " WHERE tt.taxonomy IN ( {$taxPlaceholders} ) ORDER BY tr.object_id ASC, tr.term_taxonomy_id ASC",
                ...$legacyTaxonomies
            ),
            ARRAY_A
        );

        // Legacy comments on the legacy posts.
        $comments = array();
        if ( $postIds !== array() ) {
            $idPlaceholders = implode( ',', array_fill( 0, count( $postIds ), '%d' ) );
            $comments       = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID IN ( {$idPlaceholders} ) ORDER BY comment_ID ASC",
                    ...$postIds
                ),
                ARRAY_A
            );
        }

        return array(
            'posts'         => $posts,
            'postMeta'      => $postMeta,
            'termTax'       => $termTax,
            'relationships' => $relationships,
            'comments'      => $comments,
            'options'       => array(
                'default_podcast' => get_option( LegacyIdentifiers::OPTION_DEFAULT_PODCAST ),
                'term_images'     => get_option( LegacyIdentifiers::OPTION_TERM_IMAGES ),
            ),
        );
    }

    /** Count rows in postmeta with a given back-ref meta key. */
    private function countBackRef( string $metaKey ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $metaKey )
        );
    }

    private function countTermBackRef( string $metaKey ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s", $metaKey )
        );
    }

    private function countCommentBackRef( string $metaKey ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = %s", $metaKey )
        );
    }

    private function sharedCategoryCount( int $ttId ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId )
        );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_rollback_removes_migrated_records_and_restores_options(): void {
        $data   = $this->seedDataset();
        $before = $this->legacySnapshot();
        $nativeCountBefore = $this->sharedCategoryCount( $data['nativeCatTtId'] );

        $this->migrateToCompletion();

        // Sanity: there ARE migrated records before rollback.
        $this->assertNotEmpty( Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) );
        $this->assertNotEmpty( Crosswalk::migratedPostIds( Identifiers::POST_TYPE_PODCAST ) );
        $this->assertGreaterThan( 0, $this->countBackRef( Crosswalk::LEGACY_POST_ID ) );
        $this->assertGreaterThan( 0, $this->countTermBackRef( Crosswalk::LEGACY_TERM_ID ) );
        $this->assertGreaterThan( 0, $this->countCommentBackRef( Crosswalk::LEGACY_COMMENT_ID ) );

        // The pre-existing native sermonator_term_images was overwritten + backed up.
        $backup = get_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        $this->assertIsArray( $backup );
        $this->assertArrayHasKey( Identifiers::OPTION_TERM_IMAGES, $backup );

        $rollback = new Rollback();

        // pendingDeletions enumerates the id sets BEFORE acting.
        $pending = $rollback->pendingDeletions();
        $this->assertArrayHasKey( 'posts', $pending );
        $this->assertArrayHasKey( 'terms', $pending );
        $this->assertArrayHasKey( 'comments', $pending );
        $this->assertArrayHasKey( 'options', $pending );
        $this->assertNotEmpty( $pending['posts'] );
        $this->assertNotEmpty( $pending['terms'] );

        $result = $rollback->run();
        $this->assertArrayHasKey( 'deleted', $result );
        $this->assertArrayHasKey( 'restored', $result );
        $this->assertArrayHasKey( 'warnings', $result );

        // ZERO back-refs remain anywhere.
        $this->assertSame( 0, $this->countBackRef( Crosswalk::LEGACY_POST_ID ), 'No post back-refs after rollback.' );
        $this->assertSame( 0, $this->countTermBackRef( Crosswalk::LEGACY_TERM_ID ), 'No term back-refs after rollback.' );
        $this->assertSame( 0, $this->countCommentBackRef( Crosswalk::LEGACY_COMMENT_ID ), 'No comment back-refs after rollback.' );
        $this->assertSame( 0, $this->countBackRef( Crosswalk::MIGRATION_COMPLETE ), 'No completion stamps remain.' );

        // Migrated posts gone.
        $this->assertSame( array(), Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) );
        $this->assertSame( array(), Crosswalk::migratedPostIds( Identifiers::POST_TYPE_PODCAST ) );

        // Migration-created sermonator_default_podcast option removed.
        $this->assertFalse( get_option( Identifiers::OPTION_DEFAULT_PODCAST, false ),
            'Migration-created default-podcast option must be deleted.' );

        // The backed-up native term_images option restored to its native value.
        $this->assertSame( array( 'native' => 'preexisting' ), get_option( Identifiers::OPTION_TERM_IMAGES ),
            'Backed-up native option must be restored.' );
        $this->assertContains( Identifiers::OPTION_TERM_IMAGES, $result['restored'] );

        // The HARD CONSTRAINT: the church's SHARED native category count is unchanged
        // (rollback deleted the directly-inserted native relationship rows via $wpdb
        // and recounted to the true value — never decrementing below the church's own).
        $this->assertSame( $nativeCountBefore, $this->sharedCategoryCount( $data['nativeCatTtId'] ),
            'Shared native category count must be restored to its TRUE pre-migration value.' );

        // State retreated migrated → detected.
        $this->assertSame( 'detected', ( new MigrationState() )->phase() );

        // INVARIANT: legacy byte-equal throughout.
        $this->assertEquals( $before, $this->legacySnapshot(), 'Legacy data must be byte-equal after rollback.' );
    }

    public function test_rollback_sweeps_unstamped_partial_orphan_posts(): void {
        $this->seedDataset();
        $this->migrateToCompletion();

        // Inject an un-stamped partial-migration residue: a sermonator_sermon post
        // with NO back-ref (a crash between insert and the back-ref stamp).
        $orphan = $this->fixture->injectBackRefLessPostOrphan( Identifiers::POST_TYPE_SERMON, array(
            'post_title' => 'Partial orphan',
        ) );
        $this->assertSame( Identifiers::POST_TYPE_SERMON, get_post_type( $orphan ) );

        $rollback = new Rollback();
        $pending  = $rollback->pendingDeletions();
        $this->assertContains( $orphan, $pending['posts'], 'Un-stamped sermonator post must be a pending deletion.' );

        $rollback->run();

        $this->assertNull( get_post( $orphan ), 'Partial-orphan sermonator post must be swept.' );
    }

    public function test_rollback_removes_orphaned_comment_via_back_ref(): void {
        $data = $this->seedDataset();
        $this->migrateToCompletion();

        // Find a migrated comment carrying LEGACY_COMMENT_ID, detach it from its
        // parent post (simulate a cascade that missed it), so only the back-ref
        // sweep can remove it.
        global $wpdb;
        $migratedCommentId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s LIMIT 1",
                Crosswalk::LEGACY_COMMENT_ID
            )
        );
        $this->assertGreaterThan( 0, $migratedCommentId, 'A migrated comment must exist.' );

        // Orphan it: point it at a non-existent post so wp_delete_post cascade can't reach it.
        $wpdb->update( $wpdb->comments, array( 'comment_post_ID' => 99999999 ), array( 'comment_ID' => $migratedCommentId ) );
        clean_comment_cache( $migratedCommentId );

        $rollback = new Rollback();
        $pending  = $rollback->pendingDeletions();
        $this->assertContains( $migratedCommentId, $pending['comments'],
            'Orphaned migrated comment must be enumerated via its back-ref.' );

        $rollback->run();

        $this->assertNull( get_comment( $migratedCommentId ), 'Orphaned migrated comment must be removed.' );
        $this->assertSame( 0, $this->countCommentBackRef( Crosswalk::LEGACY_COMMENT_ID ) );
    }

    public function test_rollback_is_idempotent_on_re_run(): void {
        $this->seedDataset();
        $this->migrateToCompletion();

        $rollback = new Rollback();
        $rollback->run();

        // A second run is a clean no-op (no errors, nothing left to delete).
        $second = $rollback->run();
        $this->assertSame( array(), $second['warnings'] );
        $this->assertSame( 0, $this->countBackRef( Crosswalk::LEGACY_POST_ID ) );
        $this->assertSame( 'detected', ( new MigrationState() )->phase() );
    }

    public function test_rollback_warns_on_admin_edited_migrated_post(): void {
        $this->seedDataset();
        $this->migrateToCompletion();

        // Admin-edit a migrated sermon AFTER creation (bump post_modified well past
        // post_date so the edit-guard detects divergence).
        $migrated = Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON )[0];
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            array(
                'post_content'      => 'ADMIN EDITED BODY',
                'post_modified'     => '2099-01-01 00:00:00',
                'post_modified_gmt' => '2099-01-01 00:00:00',
            ),
            array( 'ID' => $migrated )
        );
        clean_post_cache( $migrated );

        $rollback = new Rollback();
        $result   = $rollback->run();

        $this->assertNotEmpty( $result['warnings'], 'An admin-edited migrated post must surface a warning.' );
        $joined = implode( ' ', $result['warnings'] );
        $this->assertStringContainsString( (string) $migrated, $joined,
            'The warning must reference the edited migrated post id.' );
    }

    public function test_rollback_does_not_delete_native_post_with_stray_backref_and_no_live_legacy_source(): void {
        $this->seedDataset();
        $this->migrateToCompletion();

        // A NATIVE (church-authored) sermonator_sermon carrying a STRAY back-ref to a
        // legacy id that does NOT exist as a live legacy post. Rollback must NOT
        // delete it (no live legacy source → it is not migration residue we can
        // confidently reverse).
        $native = (int) wp_insert_post( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_title'  => 'Church-authored sermon',
            'post_status' => 'publish',
        ) );
        add_post_meta( $native, Crosswalk::LEGACY_POST_ID, 99999999, true ); // no such legacy post

        $rollback = new Rollback();
        $result   = $rollback->run();

        $this->assertNotNull( get_post( $native ),
            'A native post with a stray back-ref but no LIVE legacy source must NOT be deleted.' );
        $joined = implode( ' ', $result['warnings'] );
        $this->assertStringContainsString( (string) $native, $joined,
            'The skipped native post must be surfaced in warnings.' );
    }

    public function test_rollback_refuses_when_finalized(): void {
        $this->seedDataset();
        $this->migrateToCompletion();

        // Force the state to finalized (the only irreversible terminal phase).
        $state = new MigrationState();
        $state->set( 'verified' );
        $state->set( 'finalized' );

        $rollback = new Rollback();
        $result   = $rollback->run();

        $this->assertNotEmpty( $result['warnings'], 'Rollback must refuse (warn) when finalized.' );
        // Migrated records are untouched (rollback refused).
        $this->assertNotEmpty( Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ),
            'Rollback must delete NOTHING when finalized.' );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
    }
}
