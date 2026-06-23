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
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
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
}
