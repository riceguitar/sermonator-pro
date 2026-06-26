<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\Verifier;
use Sermonator\Migration\Finalizer;
use Sermonator\Migration\Rollback;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 5: Finalizer — the sole gated destructive step (design-notes item 19).
 *
 * Finalize is the ONLY operation that deletes legacy data, and it is hard-gated:
 *   - MigrationState::phase() === 'verified', AND
 *   - a FRESH Verifier-style drift rescan still matches the manifest (no drift since
 *     verification), AND
 *   - $confirmed === true.
 * Any unmet gate returns a `refused` reason and deletes NOTHING.
 *
 * On success it deletes legacy data PER VERIFIED COUNTERPART ONLY (never on a bare
 * cardinality equality): for each legacy id whose counterpart was field-by-field
 * verified, it deletes the legacy post (wp_delete_post true) and the migrated
 * legacy options/artwork option. It strips ONLY Crosswalk::strippableBackRefs() from
 * the migrated records — NEVER LEGACY_POST_CONTENT (the preserved divergent body) and
 * never a MIGRATION_FLAGS row that still carries an unresolved divergence flag. After
 * success, state → 'finalized' and Rollback refuses. Irreversible — the point of no
 * return.
 */
final class FinalizerTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        delete_option( Identifiers::OPTION_LEGACY_PODCAST_MAP );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        delete_option( Identifiers::OPTION_LEGACY_PODCAST_MAP );
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Seeding + driving
    // -------------------------------------------------------------------------

    /**
     * Seed a realistic legacy dataset: terms (incl. a native shared category on a
     * sermon to exercise the native-recount path), sermons with a comment, a podcast
     * + default-podcast pointer, and a sermonmanager_* option.
     *
     * @return array{sermons:list<int>, podcast:int, preacher:int, series:int, comment:int, nativeCatTtId:int}
     */
    private function seedDataset(): array {
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $series   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );

        $nativeCatTermId = (int) self::factory()->category->create( array( 'name' => 'Shared Church Category' ) );
        $nativeCatTtId   = (int) get_term_field( 'term_taxonomy_id', $nativeCatTermId, 'category' );

        $sermons = array();
        for ( $i = 0; $i < 2; $i++ ) {
            $sid = $this->fixture->createSermon();
            wp_set_object_terms( $sid, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
            wp_set_object_terms( $sid, array( $series ), LegacyIdentifiers::TAX_SERIES );
            $sermons[] = $sid;
        }
        wp_set_object_terms( $sermons[0], array( $nativeCatTermId ), 'category' );

        $comment = $this->fixture->createComment( $sermons[0], '1' );

        $podcast = $this->fixture->createPodcast( 'Sunday Feed' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );

        // A plain sermonmanager_* option (verbatim prefix swap) so the option-delete
        // path has a legacy option to remove at Finalize.
        $this->fixture->setOption( 'sermonmanager_general', array( 'archive_slug' => 'sermons' ) );

        return array(
            'sermons'       => $sermons,
            'podcast'       => $podcast,
            'preacher'      => $preacher,
            'series'        => $series,
            'comment'       => $comment,
            'nativeCatTtId' => $nativeCatTtId,
        );
    }

    private function migrateToMigrated(): void {
        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 100 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(), 'Setup must reach migrated.' );
    }

    /** Drive detect → migrate → verify; assert state is 'verified'. */
    private function migrateAndVerify(): void {
        $this->migrateToMigrated();
        $report = ( new Verifier() )->verify( ( new MigrationState() )->manifest() );
        $this->assertTrue( $report->complete, 'Setup must verify complete.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase(), 'Setup must reach verified.' );
    }

    private function legacyPostIds( string $legacyType ): array {
        return get_posts( array(
            'post_type'      => $legacyType,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) );
    }

    /** New comment ids that still carry the LEGACY_COMMENT_ID back-ref, ascending. */
    private function commentsWithLegacyBackRef(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s ORDER BY comment_id ASC",
                Crosswalk::LEGACY_COMMENT_ID
            )
        );

        return array_values( array_map( 'intval', (array) $ids ) );
    }

    // -------------------------------------------------------------------------
    // Gating: refuses unless verified + fresh + confirmed
    // -------------------------------------------------------------------------

    public function test_refuses_when_state_not_verified(): void {
        $this->seedDataset();
        $this->migrateToMigrated(); // state = migrated, NOT verified

        $legacyBefore = $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_SERMON );

        $result = ( new Finalizer() )->run( true );

        $this->assertIsString( $result['refused'], 'Must refuse when state is not verified.' );
        $this->assertSame( array(), $result['deleted']['posts'] ?? array(), 'Nothing deleted on refusal.' );
        // Legacy untouched.
        $this->assertSame( $legacyBefore, $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_SERMON ) );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );
    }

    public function test_refuses_without_confirmed(): void {
        $this->seedDataset();
        $this->migrateAndVerify();

        $legacyBefore = $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_SERMON );

        $result = ( new Finalizer() )->run( false );

        $this->assertIsString( $result['refused'], 'Must refuse without confirmed=true.' );
        $this->assertSame( $legacyBefore, $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_SERMON ) );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_refuses_when_fresh_rescan_shows_drift(): void {
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        $legacyBefore = $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_SERMON );

        // Edit a legacy meta value AFTER verification → the fresh drift rescan must
        // catch it and refuse, even though state is 'verified' and confirmed=true.
        update_post_meta( $data['sermons'][0], LegacyIdentifiers::META_BIBLE_PASSAGE, 'Romans 8:28 (edited)' );

        $result = ( new Finalizer() )->run( true );

        $this->assertIsString( $result['refused'], 'Must refuse when a fresh rescan shows drift.' );
        $this->assertSame( $legacyBefore, $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_SERMON ),
            'No legacy post deleted on drift refusal.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // Allowlist
    // -------------------------------------------------------------------------

    public function test_strip_allowlist_matches_crosswalk(): void {
        $this->assertSame( Crosswalk::strippableBackRefs(), Finalizer::stripAllowlist() );
        // Sanity: the allowlist must NOT contain the preserved-content keys.
        $this->assertNotContains( Crosswalk::LEGACY_POST_CONTENT, Finalizer::stripAllowlist() );
        $this->assertNotContains( Crosswalk::MIGRATION_FLAGS, Finalizer::stripAllowlist() );
    }

    // -------------------------------------------------------------------------
    // Successful finalize
    // -------------------------------------------------------------------------

    public function test_successful_finalize_deletes_verified_legacy_and_strips_allowlist(): void {
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        // Capture the migrated sermon ids BEFORE finalize (their back-refs resolve now).
        $migratedSermons = Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON );
        $this->assertNotEmpty( $migratedSermons );

        $result = ( new Finalizer() )->run( true );

        $this->assertNull( $result['refused'], 'A verified, fresh, confirmed finalize must not be refused.' );

        // The verified legacy sermons are gone.
        foreach ( $data['sermons'] as $legacyId ) {
            $this->assertNull( get_post( $legacyId ), "Verified legacy sermon {$legacyId} must be deleted." );
        }
        // The legacy podcast is gone too (verified counterpart).
        $this->assertNull( get_post( $data['podcast'] ), 'Verified legacy podcast must be deleted.' );

        // The migrated sermons SURVIVE (they are the church's new records).
        foreach ( $migratedSermons as $newId ) {
            $this->assertInstanceOf( \WP_Post::class, get_post( $newId ),
                "Migrated sermon {$newId} must survive finalize." );

            // Allowlist back-refs STRIPPED.
            $this->assertSame( '', (string) get_post_meta( $newId, Crosswalk::LEGACY_POST_ID, true ),
                'LEGACY_POST_ID must be stripped.' );
            $this->assertSame( '', (string) get_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, true ),
                'MIGRATION_COMPLETE must be stripped.' );

            // Preserved-content key SURVIVES if present (LEGACY_POST_CONTENT is never stripped).
            // (Not all sermons have a backup body; we only assert it is not in the allowlist.)
        }

        // The legacy sermonmanager_* option is gone (migrated counterpart exists).
        $this->assertFalse( get_option( 'sermonmanager_general', false ),
            'Migrated legacy option must be deleted at finalize.' );

        // stripped count > 0 reported.
        $this->assertGreaterThan( 0, $result['stripped'] );

        // State → finalized.
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
    }

    public function test_legacy_post_content_survives_finalize(): void {
        // A sermon whose body lives only in post_content_temp forces the reconciler
        // to back up a divergent body into LEGACY_POST_CONTENT — which must SURVIVE
        // the allowlist strip.
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $sermon   = $this->fixture->createSermon( array(
            'sermon_description' => array( '' ),
            LegacyIdentifiers::META_POST_CONTENT_TEMP => array( '<p>Body only in temp.</p>' ),
        ) );
        wp_set_object_terms( $sermon, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );

        $this->migrateAndVerify();

        $newId = Crosswalk::findNewByLegacyId( $sermon, Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newId );
        $preservedBefore = (string) get_post_meta( $newId, Crosswalk::LEGACY_POST_CONTENT, true );
        $this->assertNotSame( '', $preservedBefore, 'Setup: a divergent body must be preserved.' );

        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'] );

        // LEGACY_POST_CONTENT survives byte-equal; LEGACY_POST_ID stripped.
        $this->assertSame( $preservedBefore, (string) get_post_meta( $newId, Crosswalk::LEGACY_POST_CONTENT, true ),
            '_sermonator_legacy_post_content must survive finalize.' );
        $this->assertSame( '', (string) get_post_meta( $newId, Crosswalk::LEGACY_POST_ID, true ) );
    }

    // -------------------------------------------------------------------------
    // Comment back-refs: LEGACY_COMMENT_ID lives in COMMENT meta, not post meta —
    // the allowlist strip must reach it (design-notes item 19 / B2b review).
    // -------------------------------------------------------------------------

    public function test_successful_finalize_strips_comment_legacy_back_ref(): void {
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        // The seeded legacy comment was copied onto the migrated sermon and stamped
        // with LEGACY_COMMENT_ID. Before finalize that back-ref exists (and points at
        // the legacy comment id about to be orphaned by the legacy-post delete).
        $newSermon = Crosswalk::findNewByLegacyId( $data['sermons'][0], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newSermon );

        $migratedComments = get_comments( array(
            'post_id' => $newSermon,
            'status'  => 'any',
            'fields'  => 'ids',
            'number'  => 0,
        ) );
        $this->assertNotEmpty( $migratedComments, 'Setup: the migrated sermon must carry a copied comment.' );

        $taggedBefore = array();
        foreach ( $migratedComments as $cid ) {
            if ( '' !== (string) get_comment_meta( (int) $cid, Crosswalk::LEGACY_COMMENT_ID, true ) ) {
                $taggedBefore[] = (int) $cid;
            }
        }
        $this->assertNotEmpty( $taggedBefore, 'Setup: a migrated comment must carry LEGACY_COMMENT_ID.' );

        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'] );

        // The migrated comments SURVIVE (the church's records) but carry NO
        // _sermonator_legacy_comment_id (it pointed at a now-deleted legacy comment).
        foreach ( $migratedComments as $cid ) {
            $this->assertInstanceOf( \WP_Comment::class, get_comment( (int) $cid ),
                "Migrated comment {$cid} must survive finalize." );
            $this->assertSame( '', (string) get_comment_meta( (int) $cid, Crosswalk::LEGACY_COMMENT_ID, true ),
                "Migrated comment {$cid} must NOT retain _sermonator_legacy_comment_id after finalize." );
        }

        // Zero comment back-refs remain anywhere.
        $this->assertSame( array(), $this->commentsWithLegacyBackRef(),
            'No comment may retain LEGACY_COMMENT_ID after a successful finalize.' );

        // The stripped count includes the comment back-ref rows (not just post meta).
        $this->assertGreaterThanOrEqual( count( $taggedBefore ) + 1, $result['stripped'],
            'stripped must count comment back-refs in addition to post-meta back-refs.' );
    }

    // -------------------------------------------------------------------------
    // Per-counterpart only — never on cardinality equality
    // -------------------------------------------------------------------------

    public function test_never_migrated_legacy_id_is_not_deleted(): void {
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        // Inject a brand-new legacy sermon AFTER verification that has NO migrated
        // counterpart. It is not in the manifest's verified set, so Finalize must NOT
        // delete it. (The fresh rescan would actually catch the new live id as a
        // completeness gap and REFUSE — proving the per-counterpart conservatism: a
        // legacy id without a clean counterpart is never destroyed.)
        $orphanLegacy = $this->fixture->createSermon();

        $result = ( new Finalizer() )->run( true );

        // Either way, the never-migrated legacy id MUST still exist.
        $this->assertInstanceOf( \WP_Post::class, get_post( $orphanLegacy ),
            'A legacy id with no verified counterpart must never be deleted.' );

        // Because the new live id broke completeness, finalize refuses and deletes nothing.
        $this->assertIsString( $result['refused'] );
        foreach ( $data['sermons'] as $legacyId ) {
            $this->assertInstanceOf( \WP_Post::class, get_post( $legacyId ),
                'On refusal, no legacy post is deleted.' );
        }
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // Unresolved divergence flag blocks stripping (refuses the run)
    // -------------------------------------------------------------------------

    public function test_unresolved_divergence_flag_blocks_finalize(): void {
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        // Stamp an unresolved post_content_divergence flag onto a migrated sermon AFTER
        // verification (a human-review divergence surfaced post-verify). The fresh
        // rescan must treat it as an OPEN failure flag → refuse, so the flag row is
        // never stripped and the legacy source is never deleted.
        $newId = Crosswalk::findNewByLegacyId( $data['sermons'][0], Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newId );
        update_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, array( 'post_content_divergence' ) );

        $result = ( new Finalizer() )->run( true );

        $this->assertIsString( $result['refused'], 'An unresolved divergence flag must block finalize.' );

        // The MIGRATION_FLAGS row is intact (never stripped).
        $this->assertSame( array( 'post_content_divergence' ),
            get_post_meta( $newId, Crosswalk::MIGRATION_FLAGS, true ),
            'The unresolved divergence flag row must survive.' );

        // The legacy source survives.
        $this->assertInstanceOf( \WP_Post::class, get_post( $data['sermons'][0] ) );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // After finalize, Rollback refuses
    // -------------------------------------------------------------------------

    public function test_after_finalize_rollback_refuses(): void {
        $this->seedDataset();
        $this->migrateAndVerify();

        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'] );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );

        // Rollback must refuse outright on the finalized terminal phase.
        $rollback = ( new Rollback() )->run();
        $this->assertNotEmpty( $rollback['warnings'] );
        $this->assertSame( array(), $rollback['deleted']['posts'] );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // Native shared-taxonomy recount at finalize (B2a forward constraint)
    // -------------------------------------------------------------------------

    public function test_native_shared_count_settled_after_finalize(): void {
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        // The migrated sermon mirrored the native category via a direct $wpdb insert
        // WITHOUT a count bump (the bump was deferred to native_term_recount_tt_ids).
        // At finalize the deferred native tt_ids are recounted exactly ONCE via
        // wp_update_term_count_now, so the stored shared count settles to its TRUE,
        // authoritative value — exactly what a fresh recount yields, never stale.
        global $wpdb;
        $ttId = $data['nativeCatTtId'];

        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'] );

        $stored = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId )
        );

        // The authoritative value: recompute the count the canonical WP way. If the
        // Finalizer settled the count correctly, the stored value already equals this
        // fresh recompute (the recount is idempotent — re-running changes nothing).
        wp_update_term_count_now( array( $ttId ), 'category' );
        $authoritative = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId )
        );

        $this->assertSame( $authoritative, $stored,
            'Native shared count must be recounted to its true authoritative value at finalize (never left stale).' );
    }

    // -------------------------------------------------------------------------
    // Crash/resume on the DESTRUCTIVE step (B2b review): finalize is non-atomic;
    // an abort mid legacy-post-delete loop must NOT wedge the migration. A resume
    // must complete to 'finalized' with no double-delete and a correct final state.
    // -------------------------------------------------------------------------

    public function test_finalize_resumes_after_abort_mid_legacy_delete_loop(): void {
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        // Snapshot the migrated records that must survive (church's authoritative set).
        $migratedSermons = Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON );
        $this->assertNotEmpty( $migratedSermons );

        // Inject a REAL abort: throw on the FIRST legacy-post force-delete.
        // wp_delete_post fires before_delete_post before removing the row, so the
        // throw aborts run() with at least one counterpart already MARKED finalized
        // (mark precedes delete) and the loop interrupted partway.
        $thrown = false;
        $boom   = static function ( $postId ) {
            throw new \RuntimeException( 'injected abort mid finalize delete loop @ ' . $postId );
        };
        add_action( 'before_delete_post', $boom, 10, 1 );
        try {
            ( new Finalizer() )->run( true );
        } catch ( \RuntimeException $e ) {
            $thrown = true;
        } finally {
            remove_action( 'before_delete_post', $boom, 10 );
        }
        $this->assertTrue( $thrown, 'The injected mid-finalize abort must have fired.' );

        // Committed state: the gates passed, so the phase advanced to 'finalized' BEFORE
        // the destructive drain (the commit point). A crash mid-drain therefore leaves
        // the phase at 'finalized'; the resume re-enters the idempotent drain directly.
        $this->assertSame( 'finalized', ( new MigrationState() )->phase(),
            'Phase must be finalized after the commit point, even on a mid-drain abort.' );

        // Resume: a fresh run() must COMPLETE — not refuse forever — even though the
        // first legacy id was already marked finalized (and its back-ref counterpart
        // may already be partially stripped). This is the anti-wedge guarantee.
        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'],
            'A resumed finalize must complete, not refuse forever, after a partial destructive abort.' );

        // Every verified legacy post is gone (no leak: the marked-but-not-yet-deleted
        // id from the abort is re-deleted idempotently on resume).
        foreach ( $data['sermons'] as $legacyId ) {
            $this->assertNull( get_post( $legacyId ), "Legacy sermon {$legacyId} must be deleted after resume." );
        }
        $this->assertNull( get_post( $data['podcast'] ), 'Legacy podcast must be deleted after resume.' );

        // No double-delete of the migrated records: they survive intact.
        foreach ( $migratedSermons as $newId ) {
            $this->assertInstanceOf( \WP_Post::class, get_post( $newId ),
                "Migrated sermon {$newId} must survive the aborted+resumed finalize." );
            $this->assertSame( '', (string) get_post_meta( $newId, Crosswalk::LEGACY_POST_ID, true ),
                'LEGACY_POST_ID stripped after resume.' );
        }

        // All back-refs gone (post + comment) — the full happy-path postcondition.
        $this->assertSame( array(), $this->commentsWithLegacyBackRef(),
            'No comment back-ref may survive the aborted+resumed finalize.' );
        $this->assertFalse( get_option( 'sermonmanager_general', false ),
            'Migrated legacy option deleted after resume.' );

        // Final state correct.
        $this->assertSame( 'finalized', ( new MigrationState() )->phase(),
            'A resumed finalize must reach the finalized terminal phase.' );
    }

    public function test_finalize_resumes_after_abort_mid_OPTION_delete_loop(): void {
        // B2b review (critical finalize-correctness-0 / data-loss-0): the option-delete
        // loop + native recount run AFTER the legacy posts are deleted but BEFORE
        // state→finalized. An abort there must NOT wedge the migration into a permanent
        // "options shortfall" refusal, and the deferred native recount must still settle.
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        $nativeCountBefore = $this->sharedCategoryCountFresh( $data['nativeCatTtId'] );
        $migratedSermons   = Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON );
        $this->assertNotEmpty( $migratedSermons );

        // Inject a REAL abort during the OPTION-delete loop: throw when the first legacy
        // option is being deleted. The `delete_option` action fires inside delete_option
        // BEFORE the row is removed, so the throw lands after step-1 (posts) is fully done
        // and the option was MARKED finalized (mark precedes delete) but not yet removed.
        $thrown = false;
        $boom   = static function ( $optionName ) {
            if ( $optionName === 'sermonmanager_general' ) {
                throw new \RuntimeException( 'injected abort mid finalize OPTION loop @ ' . $optionName );
            }
        };
        add_action( 'delete_option', $boom, 10, 1 );
        try {
            ( new Finalizer() )->run( true );
        } catch ( \RuntimeException $e ) {
            $thrown = true;
        } finally {
            remove_action( 'delete_option', $boom, 10 );
        }
        $this->assertTrue( $thrown, 'The injected mid-option-loop abort must have fired.' );

        // Committed: phase advanced to 'finalized' at the commit point (before the
        // drain), so a crash in the option loop leaves it 'finalized' and the resume
        // re-enters the idempotent drain directly (no GATE-2 re-run on a half-deleted DB).
        $this->assertSame( 'finalized', ( new MigrationState() )->phase(),
            'Phase must be finalized after the commit point, even on a mid-option-loop abort.' );

        // The anti-wedge guarantee: a resumed finalize must COMPLETE, not refuse forever
        // — even though some legacy options were already deleted and the option-count
        // guard would otherwise see a shortfall against the un-reduced manifest.
        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'],
            'A resumed finalize must complete, not refuse forever, after a partial OPTION-loop abort.' );

        // All verified legacy posts + the migrated legacy option are gone.
        foreach ( $data['sermons'] as $legacyId ) {
            $this->assertNull( get_post( $legacyId ), "Legacy sermon {$legacyId} must be deleted after resume." );
        }
        $this->assertFalse( get_option( 'sermonmanager_general', false ),
            'The migrated legacy option must be deleted after the resumed finalize.' );

        // The deferred native shared count must STILL have been recounted (step 3 runs on
        // the resumed completion, not skipped because the rescan wedged).
        $stored = $this->sharedCategoryCountFresh( $data['nativeCatTtId'] );
        wp_update_term_count_now( array( $data['nativeCatTtId'] ), 'category' );
        $authoritative = $this->sharedCategoryCountFresh( $data['nativeCatTtId'] );
        $this->assertSame( $authoritative, $stored,
            'Native shared count must be settled to its authoritative value on the resumed finalize (recount not skipped).' );
        $this->assertSame( $nativeCountBefore, $authoritative,
            'The shared native count must equal its true pre-finalize value (legacy post deletion does not change a category count over a non-countable post type).' );

        $this->assertSame( 'finalized', ( new MigrationState() )->phase(),
            'A resumed finalize must reach the finalized terminal phase.' );
    }

    public function test_pending_deletions_equals_what_finalize_actually_deletes(): void {
        // B2b round-2 review (finalize-restructure-1): the CLI blast-radius preview
        // (pendingDeletions) must enumerate from the SAME immutable manifest the drain
        // deletes from, so the previewed set is provably what finalize actually deletes.
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        $preview = ( new Finalizer() )->pendingDeletions();
        $this->assertNotEmpty( $preview['posts'] );

        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'] );

        $previewPosts = $preview['posts'];
        $actualPosts  = $result['deleted']['posts'];
        sort( $previewPosts );
        sort( $actualPosts );
        $this->assertSame( $previewPosts, $actualPosts,
            'The finalize blast-radius preview must equal the legacy posts finalize actually deletes.' );

        $previewOpts = $preview['options'];
        $actualOpts  = $result['deleted']['options'];
        sort( $previewOpts );
        sort( $actualOpts );
        $this->assertSame( $previewOpts, $actualOpts,
            'The previewed option set must equal what finalize actually deletes.' );
    }

    private function sharedCategoryCountFresh( int $ttId ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId )
        );
    }

    public function test_finalize_is_idempotent_on_resume_no_double_delete(): void {
        $data = $this->seedDataset();
        $this->migrateAndVerify();

        $first = ( new Finalizer() )->run( true );
        $this->assertNull( $first['refused'] );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
        $deletedPosts = $first['deleted']['posts'];
        $this->assertNotEmpty( $deletedPosts );

        // A second run on the finalized phase re-enters the IDEMPOTENT drain (not a
        // refusal): everything is already deleted, so it is a harmless no-op that
        // deletes nothing more and leaves the terminal phase intact.
        $second = ( new Finalizer() )->run( true );
        $this->assertNull( $second['refused'], 'A second finalize on the finalized phase is an idempotent no-op drain.' );
        $this->assertSame( array(), $second['deleted']['posts'], 'Nothing more is deleted on the idempotent re-run.' );
        $this->assertSame( array(), $second['deleted']['options'] );
        $this->assertSame( 0, $second['stripped'] );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // Durable legacy podcast map: populated at migrate time, survives Finalize,
    // and hard-guards a multi-podcast finalize against silent mapping loss.
    // -------------------------------------------------------------------------

    public function test_durable_podcast_map_populated_at_migrate_and_survives_finalize(): void {
        $data    = $this->seedDataset();
        $legacy  = (int) $data['podcast'];
        $this->migrateAndVerify();

        $newPodcast = Crosswalk::findNewByLegacyId( $legacy, Identifiers::POST_TYPE_PODCAST );
        $this->assertIsInt( $newPodcast );

        // Map is populated BEFORE Finalize, while the back-ref still exists.
        $mapBefore = get_option( Identifiers::OPTION_LEGACY_PODCAST_MAP );
        $this->assertIsArray( $mapBefore );
        $this->assertSame( $newPodcast, (int) ( $mapBefore[ $legacy ] ?? 0 ),
            'PodcastWriter must record legacy->new podcast id in the durable map at migrate time.' );

        $result = ( new Finalizer() )->run( true );
        $this->assertNull( $result['refused'] );

        // The Crosswalk back-ref is stripped, but the durable map SURVIVES Finalize.
        $this->assertSame( '', (string) get_post_meta( $newPodcast, Crosswalk::LEGACY_POST_ID, true ),
            'Finalize must strip the Crosswalk back-ref.' );
        $mapAfter = get_option( Identifiers::OPTION_LEGACY_PODCAST_MAP );
        $this->assertIsArray( $mapAfter );
        $this->assertSame( $newPodcast, (int) ( $mapAfter[ $legacy ] ?? 0 ),
            'The durable podcast map must survive Finalize so legacy feed URLs keep resolving.' );
    }

    public function test_refuses_multi_podcast_finalize_when_durable_map_incomplete(): void {
        // Seed TWO legacy podcasts so a fall-through to the default would be wrong.
        $this->fixture->createSermon();
        $podcastA = $this->fixture->createPodcast( 'Podcast A' );
        $podcastB = $this->fixture->createPodcast( 'Podcast B' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcastA );

        $this->migrateAndVerify();

        // Simulate the broken pre-fix state: the durable map was never populated.
        delete_option( Identifiers::OPTION_LEGACY_PODCAST_MAP );

        $legacyBefore = $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_PODCAST );

        $result = ( new Finalizer() )->run( true );

        $this->assertIsString( $result['refused'],
            'Finalize must REFUSE a multi-podcast site whose durable map is incomplete.' );
        $this->assertStringContainsString( 'podcast', strtolower( (string) $result['refused'] ) );
        // Nothing deleted; phase stays verified for a corrected retry.
        $this->assertSame( array(), $result['deleted']['posts'] );
        $this->assertSame( $legacyBefore, $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_PODCAST ) );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }
}
