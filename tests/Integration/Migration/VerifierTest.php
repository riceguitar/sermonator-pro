<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\Verifier;
use Sermonator\Migration\VerifyReport;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 3: Verifier — legacy→target completeness + drift oracle.
 *
 * The Verifier proves the migrated result against the detect-time manifest:
 *  - DRIFT (gating-3): recompute LegacyChecksum::forPost per legacy id and compare
 *    to the manifest checksum — a source edited between detect and migrate lands in
 *    drift[]. Extended to terms (name+slug+description) and options.
 *  - COMPLETENESS (legacy→target direction): EVERY legacy id in the manifest must
 *    resolve to exactly one migrated counterpart via Crosswalk::findNewByLegacyId
 *    AND that counterpart's MIGRATION_FLAGS failure set must be empty — so an
 *    offsetting skip+duplicate cannot satisfy a bare count match. Any legacy id
 *    without a clean counterpart lands in missing[].
 *  - SLUG-COLLISION: a disambiguated term is verified against its LEGACY_SLUG
 *    (exact), never legacy==new.
 *  - complete = drift==[] && missing==[] && openFlags==[]; on complete, state→verified.
 *  - READ-ONLY: legacy byte-equal throughout.
 */
final class VerifierTest extends WP_UnitTestCase {
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

    /**
     * Seed a small realistic legacy dataset and run a FULL migrate to 'migrated'.
     *
     * @return array{sermons:list<int>, podcast:int, preacher:int, series:int}
     */
    private function seedAndMigrate(): array {
        $data = $this->seedDataset();

        $orch = new Orchestrator();
        $orch->detect();

        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 100 );

        $this->assertSame( 'migrated', ( new MigrationState() )->phase(), 'Setup must reach migrated.' );

        return $data;
    }

    /**
     * @return array{sermons:list<int>, podcast:int, preacher:int, series:int}
     */
    private function seedDataset(): array {
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $series   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Orphan Topic' );

        $sermons = array();
        for ( $i = 0; $i < 3; $i++ ) {
            $sid = $this->fixture->createSermon();
            wp_set_object_terms( $sid, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
            wp_set_object_terms( $sid, array( $series ), LegacyIdentifiers::TAX_SERIES );
            $sermons[] = $sid;
        }

        $podcast = $this->fixture->createPodcast( 'Sunday Feed' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );

        return array(
            'sermons'  => $sermons,
            'podcast'  => $podcast,
            'preacher' => $preacher,
            'series'   => $series,
        );
    }

    /** @return array<string,mixed> */
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

        return array(
            'posts'    => $posts,
            'postMeta' => $postMeta,
            'termTax'  => $termTax,
            'options'  => array(
                'default_podcast' => get_option( LegacyIdentifiers::OPTION_DEFAULT_PODCAST ),
            ),
        );
    }

    public function test_clean_migrate_verifies_complete_and_sets_state_verified(): void {
        $this->seedAndMigrate();
        $before = $this->legacySnapshot();

        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertInstanceOf( VerifyReport::class, $report );
        $this->assertSame( array(), $report->drift, 'No drift on a clean migrate.' );
        $this->assertSame( array(), $report->missing, 'No missing counterpart on a clean migrate.' );
        $this->assertSame( array(), $report->openFlags, 'No open failure flags on a clean migrate.' );
        $this->assertTrue( $report->complete, 'A clean migrate must verify complete.' );

        $this->assertSame( 'verified', ( new MigrationState() )->phase(), 'A complete verify must set state verified.' );

        // INVARIANT: the Verifier is read-only — legacy byte-equal.
        $this->assertEquals( $before, $this->legacySnapshot(), 'Verifier must not mutate legacy data.' );
    }

    public function test_post_detect_legacy_meta_edit_lands_in_drift_not_complete(): void {
        $data = $this->seedAndMigrate();
        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        // Edit a legacy meta value AFTER detect (and after migrate) — the source
        // changed out from under the manifest checksum. The drift oracle must catch it.
        $edited = $data['sermons'][0];
        update_post_meta( $edited, LegacyIdentifiers::META_BIBLE_PASSAGE, 'Genesis 1:1 (edited)' );

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertContains( $edited, $report->drift, 'An edited legacy source must land in drift.' );
        $this->assertFalse( $report->complete, 'Drift makes the report incomplete.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase(), 'A drifted verify must NOT set verified.' );
    }

    public function test_deleted_counterpart_is_missing_even_when_counts_balance(): void {
        $data = $this->seedAndMigrate();
        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        // Delete ONE migrated sermon counterpart (the offsetting-skip scenario:
        // legacy id present in the manifest, but no clean counterpart now).
        $victim = $data['sermons'][1];
        $newId  = Crosswalk::findNewByLegacyId( $victim, Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newId );

        // Re-balance the COUNT by minting a duplicate migrated sermon for ANOTHER
        // legacy id, so a bare count comparison would coincidentally pass.
        $balancer = $data['sermons'][2];
        $dupNewId = (int) wp_insert_post( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_title'  => 'Offsetting duplicate',
            'post_status' => 'publish',
            'meta_input'  => array(
                Crosswalk::LEGACY_POST_ID    => $balancer,
                Crosswalk::MIGRATION_COMPLETE => '1',
            ),
        ) );
        $this->assertGreaterThan( 0, $dupNewId );

        // Now remove the victim's counterpart.
        wp_delete_post( (int) $newId, true );

        // Count of migrated sermons is back to 3 (one deleted, one duplicate added),
        // so a naive count match would say "complete". The legacy→target direction
        // must still flag the victim as missing (no clean counterpart for it).
        $this->assertCount( 3, Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ),
            'Sanity: the duplicate re-balanced the migrated count.' );

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertContains( $victim, $report->missing,
            'A legacy id with no clean counterpart must be missing even when counts balance.' );
        $this->assertFalse( $report->complete, 'A missing counterpart makes the report incomplete.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_disambiguated_slug_term_verifies_via_legacy_slug(): void {
        // A native church term already owns the SLUG (but with a DIFFERENT name —
        // a pure slug collision, not a name+slug adoption). The legacy term must
        // migrate to a disambiguated slug, flagged slug_collision, with LEGACY_SLUG
        // preserving the original. The Verifier must compare the new slug against
        // LEGACY_SLUG (exact), NOT legacy==new — so no false mismatch.
        $collidingSlug = 'grace';

        // Native (church) term in the TARGET taxonomy, owning the slug under a
        // different name — so the legacy term cannot adopt it (name differs) and is
        // forced down the deterministic-suffix collision branch.
        $native = wp_insert_term( 'Native Grace', Identifiers::TAX_PREACHER, array( 'slug' => $collidingSlug ) );
        $this->assertIsArray( $native );

        // Legacy term sharing the SLUG (different name) in the legacy taxonomy.
        $legacyPreacher = $this->fixture->createTermRaw( LegacyIdentifiers::TAX_PREACHER, 'Legacy Grace', '', $collidingSlug );

        $sid = $this->fixture->createSermon();
        wp_set_object_terms( $sid, array( $legacyPreacher ), LegacyIdentifiers::TAX_PREACHER );
        $podcast = $this->fixture->createPodcast( 'Feed' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );

        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 100 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        // The migrated counterpart of the legacy term carries a DISAMBIGUATED slug
        // (legacy != new) but its LEGACY_SLUG records the original — exactly the
        // case where legacy==new would be a false mismatch.
        $newTermId = Crosswalk::findNewTermByLegacyId( $legacyPreacher, Identifiers::TAX_PREACHER );
        $this->assertNotNull( $newTermId );
        $newSlug = (string) get_term( (int) $newTermId, Identifiers::TAX_PREACHER )->slug;
        $this->assertNotSame( $collidingSlug, $newSlug, 'The disambiguated term must NOT keep the colliding slug.' );
        $this->assertSame( $collidingSlug, (string) get_term_meta( (int) $newTermId, Crosswalk::LEGACY_SLUG, true ),
            'LEGACY_SLUG must preserve the original legacy slug.' );

        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        $report = ( new Verifier() )->verify( $manifest );

        // The slug_collision term must NOT produce a false term mismatch — the
        // Verifier compares new slug against LEGACY_SLUG, so terms verify clean.
        $this->assertSame( array(), $report->drift,
            'A disambiguated-slug term must verify via LEGACY_SLUG (no false drift).' );
        $this->assertSame( array(), $report->missing );
        $this->assertTrue( $report->complete,
            'A disambiguated-slug term must not break completeness.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_open_failure_flag_makes_report_incomplete(): void {
        $data = $this->seedAndMigrate();
        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        // Inject an OPEN per-record failure flag on a migrated counterpart (an
        // unresolved divergence that needs human review). The Verifier must surface
        // it in openFlags and refuse completeness — the counterpart is not clean.
        $legacyId = $data['sermons'][0];
        $newId    = Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newId );
        update_post_meta( (int) $newId, Crosswalk::MIGRATION_FLAGS, array( 'post_content_divergence' ) );

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertNotEmpty( $report->openFlags, 'An open failure flag must surface in openFlags.' );
        $this->assertFalse( $report->complete, 'An open failure flag makes the report incomplete.' );
        // A record carrying a failure flag is NOT a clean counterpart → also missing.
        $this->assertContains( $legacyId, $report->missing,
            'A counterpart with an open failure flag is not a clean counterpart.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_default_podcast_remap_is_explicitly_verified(): void {
        // The clean dataset seeds wpfc_sm_default_podcast and migrates it to
        // sermonator_default_podcast (legacy podcast id → new podcast post id). The
        // Verifier must PROVE that remap explicitly — it carries no sermonmanager_
        // prefix, so it lives outside the option LIKE scan.
        $data = $this->seedAndMigrate();

        // Sanity: the remap actually landed on the live migrated podcast.
        $newPodcastId = Crosswalk::findNewByLegacyId( $data['podcast'], Identifiers::POST_TYPE_PODCAST );
        $this->assertNotNull( $newPodcastId );
        $this->assertSame( (int) $newPodcastId, (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST ),
            'Sanity: default-podcast option holds the new podcast post id.' );

        $manifest = ( new MigrationState() )->manifest();
        $report   = ( new Verifier() )->verify( $manifest );

        $this->assertTrue( $report->complete, 'A correct default-podcast remap must verify complete.' );
        $this->assertSame( 1, $report->counts['default_podcast'],
            'The verified default-podcast remap must be counted.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_default_podcast_wrong_target_lands_in_drift_not_complete(): void {
        // Negative: corrupt the remapped target option AFTER migrate so it no longer
        // points at the live migrated podcast. The Verifier must catch the broken
        // pointer (drift + missing) and refuse completeness — otherwise Finalize
        // would delete the legacy option and lose the pointer.
        $this->seedAndMigrate();
        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        update_option( Identifiers::OPTION_DEFAULT_PODCAST, 999999 ); // not a live podcast

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertFalse( $report->complete,
            'A default-podcast pointer to a non-podcast must make the report incomplete.' );
        $this->assertNotEmpty( $report->drift, 'A broken default-podcast remap must land in drift.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_orphan_counterpart_without_offsetting_skip_is_not_complete(): void {
        // A pure SURPLUS: every legacy id keeps its clean counterpart (no skip), yet
        // an EXTRA migrated sermon is minted with NO offsetting legacy source. The
        // counts-based completeness check must catch the surplus — otherwise an
        // over-migrated target verifies clean and authorizes the destructive Finalize.
        $this->seedAndMigrate();
        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        // Orphan: a migrated sermon whose legacy back-ref is NOT in the manifest.
        $orphan = (int) wp_insert_post( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_title'  => 'Orphan surplus sermon',
            'post_status' => 'publish',
            'meta_input'  => array(
                Crosswalk::LEGACY_POST_ID     => 8675309, // a legacy id that never existed
                Crosswalk::MIGRATION_COMPLETE => '1',
            ),
        ) );
        $this->assertGreaterThan( 0, $orphan );

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertFalse( $report->complete,
            'An orphan/surplus migrated record with no offsetting skip must NOT verify complete.' );
        $this->assertNotEmpty( $report->missing, 'The surplus counterpart must surface in missing.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_duplicate_counterpart_for_one_legacy_id_is_not_complete(): void {
        // EXACTLY-ONE-counterpart: a SECOND migrated sermon stamped with an EXISTING
        // legacy id (a duplicate) — Crosswalk::findNewByLegacyId silently collapses
        // >1 to the lowest id, so the duplicate would otherwise pass. The Verifier
        // must detect the surplus via the true cardinality.
        $data = $this->seedAndMigrate();
        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        $dup = (int) wp_insert_post( array(
            'post_type'   => Identifiers::POST_TYPE_SERMON,
            'post_title'  => 'Duplicate counterpart',
            'post_status' => 'publish',
            'meta_input'  => array(
                Crosswalk::LEGACY_POST_ID     => $data['sermons'][0],
                Crosswalk::MIGRATION_COMPLETE => '1',
            ),
        ) );
        $this->assertGreaterThan( 0, $dup );
        $this->assertSame( 2, Crosswalk::countNewByLegacyId( $data['sermons'][0], Identifiers::POST_TYPE_SERMON ),
            'Sanity: the legacy id now has two counterparts.' );

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertFalse( $report->complete,
            'A duplicate counterpart for one legacy id must NOT verify complete.' );
        $this->assertContains( $data['sermons'][0], $report->missing,
            'A legacy id with >1 counterpart is not cleanly migrated.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_legacy_post_deleted_after_detect_is_flagged_missing(): void {
        // Manifest-driven enumeration: a legacy sermon DELETED after detect is no
        // longer live, but it is still in the manifest's checksummed ids. The
        // Verifier must enumerate it from the MANIFEST (not the live DB) and flag it
        // missing — otherwise verification would gate Finalize against a manifest
        // whose source has vanished.
        $data = $this->seedAndMigrate();
        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        $vanished = $data['sermons'][0];
        $this->assertContains( $vanished, $manifest->checksummedLegacyIds(),
            'Sanity: the legacy id is in the manifest before deletion.' );

        // Delete the migrated counterpart AND the legacy source (post-detect deletion).
        $newId = Crosswalk::findNewByLegacyId( $vanished, Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newId );
        wp_delete_post( (int) $newId, true );
        wp_delete_post( $vanished, true );

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertContains( $vanished, $report->missing,
            'A legacy id present in the manifest but deleted after detect must be missing.' );
        $this->assertFalse( $report->complete );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_sermonmanager_option_round_trips_and_verifies(): void {
        // The options-verification path: seed a real sermonmanager_* option BEFORE
        // detect so it migrates to its sermonator_* counterpart, then assert the
        // Verifier proves the value-equality round-trip (this path was previously
        // unexercised — no test ever seeded a sermonmanager_* option).
        $this->fixture->setOption( 'sermonmanager_player', array( 'theme' => 'dark', 'autoplay' => 0 ) );

        $this->seedAndMigrate();

        // Sanity: the option migrated to the sermonator_ namespace, value-equal.
        $this->assertEquals(
            array( 'theme' => 'dark', 'autoplay' => 0 ),
            get_option( 'sermonator_player' ),
            'Sanity: the sermonmanager_ option migrated value-equal.'
        );

        $manifest = ( new MigrationState() )->manifest();
        $report   = ( new Verifier() )->verify( $manifest );

        $this->assertTrue( $report->complete, 'A migrated sermonmanager_ option must verify complete.' );
        $this->assertGreaterThanOrEqual( 1, $report->counts['options'],
            'The verified option must be counted.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_cleared_target_option_lands_in_drift_not_complete(): void {
        // Negative options path: clear the migrated target option AFTER migrate so it
        // no longer carries the legacy value. The Verifier must surface an option
        // drift sentinel and refuse completeness.
        $this->fixture->setOption( 'sermonmanager_player', array( 'theme' => 'dark' ) );
        $this->seedAndMigrate();
        $manifest = ( new MigrationState() )->manifest();
        $this->assertNotNull( $manifest );

        delete_option( 'sermonator_player' ); // target option cleared post-migrate

        $report = ( new Verifier() )->verify( $manifest );

        $this->assertFalse( $report->complete,
            'A cleared migrated option must make the report incomplete.' );
        $this->assertNotEmpty( $report->drift, 'A cleared/absent target option must land in drift.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_advisory_flags_do_not_block_completeness(): void {
        // legacy_nonnumeric_date (raw preserved + normalized companion) and
        // post_content_preserved (old body preserved + flagged) are ADVISORY — no
        // data loss — so they must NOT block verification.
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $sid = $this->fixture->createSermon( array(
            'sermon_date' => array( 'Easter Sunday 2021' ), // non-numeric → advisory flag
        ) );
        wp_set_object_terms( $sid, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
        $podcast = $this->fixture->createPodcast( 'Feed' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );

        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 100 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        // Confirm the advisory flag really is present on the counterpart.
        $newId = Crosswalk::findNewByLegacyId( $sid, Identifiers::POST_TYPE_SERMON );
        $this->assertNotNull( $newId );
        $flags = get_post_meta( (int) $newId, Crosswalk::MIGRATION_FLAGS, true );
        $this->assertIsArray( $flags );
        $this->assertContains( 'legacy_nonnumeric_date', $flags,
            'Sanity: the advisory non-numeric-date flag is present.' );

        $manifest = ( new MigrationState() )->manifest();
        $report   = ( new Verifier() )->verify( $manifest );

        $this->assertSame( array(), $report->openFlags,
            'Advisory flags must NOT surface as open failure flags.' );
        $this->assertSame( array(), $report->missing );
        $this->assertTrue( $report->complete, 'Advisory flags must not block completeness.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }
}
