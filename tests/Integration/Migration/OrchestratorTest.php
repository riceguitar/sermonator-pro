<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\Manifest;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 2: Orchestrator — sequence, gate, lock, chunked/resumable.
 *
 * The Orchestrator assembles the per-record B2a writers into a resumable,
 * lock-guarded, state-machined lifecycle. It:
 *  - detect(): runs the Detector, stores the manifest, sets phase 'detected';
 *  - run(batchSize): advances ONE bounded chunk of work and returns progress,
 *    re-callable until 'migrated'. Ordering is HARD-GATED:
 *    terms → (sermons/artwork/podcasts) → options(default-podcast);
 *  - refuses to write sermons/artwork/podcasts until TermWriter::migrateAll
 *    completed with zero missing crosswalks (phaseComplete('terms'));
 *  - refuses the default-podcast option write until podcasts complete;
 *  - serializes concurrent runs via a single advisory lock (a second concurrent
 *    run() refuses);
 *  - is resumable: a crash mid-batch then a fresh run() resumes with NO duplicate
 *    sermons (per-record state distinguishes complete from partial);
 *  - sets 'migrated' only when every phase reports complete; never sets 'verified'
 *    (the Verifier does);
 *  - reads legacy READ-ONLY throughout (legacy snapshot byte-equal).
 */
final class OrchestratorTest extends WP_UnitTestCase {
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
     * Seed a small but realistic legacy dataset: terms (referenced + orphan),
     * sermons referencing terms, a podcast, the default-podcast option, and
     * artwork. Returns the legacy ids for snapshot/assertion.
     *
     * @return array{sermons:list<int>, podcast:int, preacher:int, series:int, ttPreacher:int}
     */
    private function seedDataset(): array {
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $series   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        // Orphan term (attached to no sermon) — must still migrate.
        $this->fixture->createTerm( LegacyIdentifiers::TAX_TOPIC, 'Orphan Topic' );

        $ttPreacher = (int) get_term_field( 'term_taxonomy_id', $preacher, LegacyIdentifiers::TAX_PREACHER );

        $sermons = array();
        for ( $i = 0; $i < 3; $i++ ) {
            $sid = $this->fixture->createSermon();
            wp_set_object_terms( $sid, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
            wp_set_object_terms( $sid, array( $series ), LegacyIdentifiers::TAX_SERIES );
            $sermons[] = $sid;
        }

        $podcast = $this->fixture->createPodcast( 'Sunday Feed' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );

        // Artwork: legacy tt_id → attachment id (attachment id is a shared global).
        $this->fixture->seedArtwork( array( $ttPreacher => 555 ) );

        return array(
            'sermons'    => $sermons,
            'podcast'    => $podcast,
            'preacher'   => $preacher,
            'series'     => $series,
            'ttPreacher' => $ttPreacher,
        );
    }

    /**
     * Snapshot every legacy row that the migration must leave byte-for-byte
     * unchanged: legacy posts (+meta), terms (+termmeta +relationships), and the
     * sermonmanager_* / artwork options.
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

        return array(
            'posts'         => $posts,
            'postMeta'      => $postMeta,
            'termTax'       => $termTax,
            'relationships' => $relationships,
            'options'       => array(
                'default_podcast' => get_option( LegacyIdentifiers::OPTION_DEFAULT_PODCAST ),
                'term_images'     => get_option( LegacyIdentifiers::OPTION_TERM_IMAGES ),
            ),
        );
    }

    public function test_detect_stores_manifest_and_sets_phase(): void {
        $data = $this->seedDataset();

        $orch     = new Orchestrator();
        $manifest = $orch->detect();

        $this->assertInstanceOf( Manifest::class, $manifest );
        $this->assertSame( 3, $manifest->count( 'sermons' ) );
        $this->assertSame( 1, $manifest->count( 'podcasts' ) );

        $state = new MigrationState();
        $this->assertSame( 'detected', $state->phase() );
        $this->assertNotNull( $state->manifest(), 'detect() must persist the manifest for the Verifier/Finalizer.' );
        $this->assertSame( 3, $state->manifest()->count( 'sermons' ) );
    }

    public function test_run_repeatedly_migrates_everything_terms_first_then_reaches_migrated(): void {
        $data   = $this->seedDataset();
        $before = $this->legacySnapshot();

        $orch = new Orchestrator();
        $orch->detect();

        // Drive with batchSize=1 so the chunking/ordering is exercised. Bounded
        // loop guard so a logic bug cannot hang the suite.
        $guard = 0;
        do {
            $progress = $orch->run( 1 );
            $this->assertArrayHasKey( 'phase', $progress );
            $this->assertArrayHasKey( 'done', $progress );
            $this->assertArrayHasKey( 'remaining', $progress );
            $this->assertArrayHasKey( 'flags', $progress );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 100 );

        $state = new MigrationState();
        $this->assertSame( 'migrated', $state->phase(), 'Orchestrator must reach migrated after enough run() calls.' );

        // Terms migrated first: every legacy term has a new counterpart.
        $this->assertNotNull(
            Crosswalk::findNewTermByLegacyId( $data['preacher'], Identifiers::TAX_PREACHER ),
            'Preacher term must be migrated.'
        );

        // All sermons migrated exactly once (no duplicates).
        $this->assertCount( 3, Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) );
        foreach ( $data['sermons'] as $legacyId ) {
            $this->assertNotNull( Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_SERMON ) );
        }

        // Podcast migrated.
        $this->assertNotNull( Crosswalk::findNewByLegacyId( $data['podcast'], Identifiers::POST_TYPE_PODCAST ) );

        // Default-podcast option written (gated on podcasts-complete) and points at
        // the NEW podcast id, never the legacy id.
        $newPodcast = Crosswalk::findNewByLegacyId( $data['podcast'], Identifiers::POST_TYPE_PODCAST );
        $this->assertSame( (int) $newPodcast, (int) get_option( Identifiers::OPTION_DEFAULT_PODCAST ) );

        // Artwork migrated (terms-complete gate satisfied): new tt_id → attachment.
        $newTermImages = get_option( Identifiers::OPTION_TERM_IMAGES );
        $this->assertIsArray( $newTermImages );
        $this->assertContains( 555, array_map( 'intval', array_values( $newTermImages ) ) );

        // INVARIANT: legacy byte-equal throughout (read-only until Finalize).
        $this->assertEquals( $before, $this->legacySnapshot(), 'Legacy data must be byte-equal after migrate.' );
    }

    public function test_sermons_are_gated_until_terms_complete(): void {
        $data = $this->seedDataset();

        $orch = new Orchestrator();
        $orch->detect();

        // Force the terms phase to look INCOMPLETE: mark it explicitly not-complete
        // by leaving phaseComplete('terms') false and asking the orchestrator to run
        // the sermon phase directly. The orchestrator must refuse to migrate any
        // sermon while terms are incomplete.
        $state = new MigrationState();
        $this->assertFalse( $state->phaseComplete( 'terms' ) );

        $gated = $orch->runSermonBatch( 50 );
        $this->assertFalse( $gated, 'Sermon batch must be REFUSED while terms are incomplete.' );
        $this->assertCount( 0, Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ),
            'No sermon may be written before terms complete.' );
    }

    public function test_second_concurrent_run_refuses_while_lock_held(): void {
        $this->seedDataset();

        $orch = new Orchestrator();
        $orch->detect();

        // Hold the lock as if another process (cron/admin) were mid-run.
        $this->assertTrue( $orch->acquireLock(), 'First acquireLock must succeed.' );

        $other = new Orchestrator();
        $this->assertFalse( $other->acquireLock(), 'A second concurrent acquireLock must refuse.' );

        // A run() that cannot acquire the lock refuses with a 'locked' flag and
        // writes nothing.
        $progress = $other->run( 50 );
        $this->assertContains( 'locked', $progress['flags'], 'A concurrent run() must report the lock refusal.' );
        $this->assertCount( 0, Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) );

        $orch->releaseLock();
        $this->assertTrue( $other->acquireLock(), 'After release, the lock is acquirable again.' );
        $other->releaseLock();
    }

    public function test_crash_mid_batch_then_resume_creates_no_duplicate_sermons(): void {
        $data   = $this->seedDataset();
        $before = $this->legacySnapshot();

        $orch = new Orchestrator();
        $orch->detect();

        // Complete the terms phase first (its own bounded loop).
        $guard = 0;
        while ( ! ( new MigrationState() )->phaseComplete( 'terms' ) && $guard < 20 ) {
            $orch->run( 1 );
            $guard++;
        }
        $this->assertTrue( ( new MigrationState() )->phaseComplete( 'terms' ) );

        // Migrate exactly ONE sermon, then "crash": throw away the orchestrator and
        // its lock as a process abort would. The lock must be releasable / not wedge
        // a fresh run.
        $orch->runSermonBatch( 1 );
        $migratedAfterOne = count( Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) );
        $this->assertSame( 1, $migratedAfterOne, 'Exactly one sermon after a batchSize=1 sermon batch.' );

        // Simulate the crash: forcibly clear the lock (the aborted process never
        // released it) and start a brand-new orchestrator object.
        delete_option( Orchestrator::OPTION_LOCK );
        $resumed = new Orchestrator();

        $guard = 0;
        do {
            $progress = $resumed->run( 1 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 100 );

        // No duplicates: still exactly 3 migrated sermons, one per legacy id.
        $this->assertCount( 3, Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ),
            'Resume must not duplicate the already-migrated sermon.' );
        foreach ( $data['sermons'] as $legacyId ) {
            $this->assertNotNull( Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_SERMON ) );
        }

        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );
        $this->assertEquals( $before, $this->legacySnapshot(), 'Legacy byte-equal across crash/resume.' );
    }

    public function test_run_never_sets_verified_itself(): void {
        $this->seedDataset();

        $orch = new Orchestrator();
        $orch->detect();

        $guard = 0;
        do {
            $progress = $orch->run( 50 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 50 );

        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );
        // The Verifier owns the verified transition; the Orchestrator must stop at
        // migrated and never advance on its own.
        $extra = $orch->run( 50 );
        $this->assertSame( 'migrated', $extra['phase'], 'Orchestrator must not advance past migrated.' );
        $this->assertNotSame( 'verified', ( new MigrationState() )->phase() );
    }

    public function test_status_reports_phase_and_counts(): void {
        $this->seedDataset();

        $orch = new Orchestrator();
        $orch->detect();

        $status = $orch->status();
        $this->assertSame( 'detected', $status['phase'] );
        $this->assertArrayHasKey( 'counts', $status );
        $this->assertSame( 3, $status['counts']['sermons'] );
    }
}
