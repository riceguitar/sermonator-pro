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

    public function test_expired_lock_reclaim_is_a_single_winner_cas(): void {
        // B2b review (state-concurrency-0): the expired-lock reclaim must be a
        // compare-and-swap, NOT an unconditional overwrite — otherwise two concurrent
        // reclaimers of a crashed holder's lock both "win" and insert concurrently.
        global $wpdb;
        $this->seedDataset();

        // Plant an EXPIRED lock (ancient timestamp) as if a prior holder crashed.
        $stale = '100|sermonator_lock_crashed';
        add_option( Orchestrator::OPTION_LOCK, $stale, '', 'no' );

        // A reclaims via CAS (UPDATE ... WHERE option_value = stale → exactly 1 row).
        $a = new Orchestrator();
        $this->assertTrue( $a->acquireLock(), 'An expired lock must be reclaimable.' );

        // The stored value has changed, so a CONCURRENT reclaimer still holding the
        // stale value loses the CAS (0 rows affected) — proving the single-winner CAS.
        $reclaimedByStale = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                '200|sermonator_lock_loser',
                Orchestrator::OPTION_LOCK,
                $stale
            )
        );
        $this->assertSame( 0, $reclaimedByStale,
            'A second reclaimer holding the stale value must lose the CAS (0 rows) — single winner.' );

        // A fresh acquire by another instance sees A's now-live lock and refuses.
        $b = new Orchestrator();
        $this->assertFalse( $b->acquireLock(), 'A concurrent acquire after a fresh reclaim must refuse.' );

        $a->releaseLock();
    }

    public function test_release_is_ownership_checked_and_does_not_delete_a_successors_lock(): void {
        // B2b review (state-concurrency-1): releaseLock must be OWNERSHIP-CHECKED — a
        // slow/overrun holder whose lock was reclaimed by a successor must NOT delete
        // the successor's live lock (which would re-open the concurrency window).
        global $wpdb;
        $this->seedDataset();

        $a = new Orchestrator();
        $this->assertTrue( $a->acquireLock() );

        // Simulate A overrunning past the TTL: back-date the stored lock timestamp so a
        // successor sees it as expired. (A's in-memory token is unchanged — A still
        // believes it owns the lock.)
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                '100|sermonator_lock_overrun',
                Orchestrator::OPTION_LOCK
            )
        );
        wp_cache_delete( Orchestrator::OPTION_LOCK, 'options' );

        // Successor B reclaims the (now-expired) lock.
        $b = new Orchestrator();
        $this->assertTrue( $b->acquireLock(), 'B must reclaim the expired (overrun) lock.' );
        $bRaw = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Orchestrator::OPTION_LOCK )
        );

        // A finishes and releases. Its ownership-checked release must NOT delete B's
        // lock (A's token no longer matches the stored value).
        $a->releaseLock();
        $afterRelease = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Orchestrator::OPTION_LOCK )
        );
        $this->assertSame( $bRaw, $afterRelease,
            "A's release must NOT tear down B's freshly-reclaimed lock." );

        // A third runner must still see B's live lock and refuse — window NOT re-opened.
        $c = new Orchestrator();
        $this->assertFalse( $c->acquireLock(), 'A third runner must not enter while B holds the lock.' );

        // B's ownership-checked release removes its OWN lock cleanly.
        $b->releaseLock();
        $this->assertNull(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Orchestrator::OPTION_LOCK )
            ),
            "B's ownership-checked release removes its own lock."
        );
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

    /**
     * The genuinely-risky resume path: a REAL mid-write abort that leaves a
     * stamped-but-PARTIAL post (back-ref present, MIGRATION_COMPLETE ABSENT —
     * WriteResult case 3), which is the entire reason the in_progress spine exists.
     *
     * The prior crash test only ran a sermon to CLEAN completion then re-ran over
     * the already-COMPLETE record (case 2), never exercising the partial path. Here
     * we inject the abort directly: after terms complete, we seed for legacy sermon
     * #1 exactly the post the SermonWriter would leave if it died after inserting
     * the post + atomic back-ref but before stamping MIGRATION_COMPLETE, and record
     * it in_progress on MigrationState (as Orchestrator::runPostBatch does before
     * calling write()). Then a FRESH Orchestrator with the REAL writer resumes.
     *
     * Asserts: (a) the aborted legacy id is recorded in_progress (not complete)
     * after the abort; (b) the resumed run completes it WITHOUT creating a duplicate
     * — still exactly 3 migrated, one per legacy id, MIGRATION_COMPLETE now present
     * on every counterpart; (c) the legacy snapshot is byte-equal across the
     * abort+resume.
     */
    public function test_real_mid_write_abort_resumes_without_duplicate_sermons(): void {
        $data   = $this->seedDataset();
        $before = $this->legacySnapshot();

        $orch = new Orchestrator();
        $orch->detect();

        // Complete the terms phase (its bounded loop) so the sermon gate is open.
        $guard = 0;
        while ( ! ( new MigrationState() )->phaseComplete( 'terms' ) && $guard < 20 ) {
            $orch->run( 1 );
            $guard++;
        }
        $this->assertTrue( ( new MigrationState() )->phaseComplete( 'terms' ) );

        // --- Inject the REAL mid-write abort for legacy sermon #1. ---
        $abortedLegacyId = $data['sermons'][0];
        $legacy          = get_post( $abortedLegacyId );
        $this->assertInstanceOf( \WP_Post::class, $legacy );

        // Insert the new post with the SAME atomic back-ref the real writer writes
        // in its insert (meta_input => [LEGACY_POST_ID => legacyId]) but WITHHOLD
        // MIGRATION_COMPLETE — the exact stamped-but-partial residue of a crash
        // after insert but before the LAST step.
        $partialNewId = (int) wp_insert_post( array(
            'post_type'    => Identifiers::POST_TYPE_SERMON,
            'post_title'   => $legacy->post_title,
            'post_status'  => $legacy->post_status,
            'post_name'    => $legacy->post_name,
            'post_content' => $legacy->post_content,
            'meta_input'   => array( Crosswalk::LEGACY_POST_ID => $abortedLegacyId ),
        ) );
        $this->assertGreaterThan( 0, $partialNewId );
        // The partial post carries the back-ref but NOT the completion stamp.
        $this->assertSame(
            (string) $abortedLegacyId,
            (string) get_post_meta( $partialNewId, Crosswalk::LEGACY_POST_ID, true )
        );
        $this->assertEmpty(
            get_post_meta( $partialNewId, Crosswalk::MIGRATION_COMPLETE, true ),
            'The injected abort must leave MIGRATION_COMPLETE absent (partial).'
        );

        // Record it in_progress exactly as runPostBatch does BEFORE the write that
        // never finished — so the durable state reflects the abort.
        ( new MigrationState() )->recordRecord( $abortedLegacyId, 'in_progress', null, array() );

        // (a) The aborted id is recorded in_progress (distinct from complete).
        $rec = ( new MigrationState() )->record( $abortedLegacyId );
        $this->assertNotNull( $rec );
        $this->assertSame( 'in_progress', $rec['state'], 'Aborted record must be in_progress after the abort.' );

        // --- Resume with a FRESH Orchestrator (real writer). ---
        delete_option( Orchestrator::OPTION_LOCK ); // the aborted process never released it.
        $resumed = new Orchestrator();

        $guard = 0;
        do {
            $progress = $resumed->run( 1 );
            $guard++;
        } while ( $progress['phase'] !== 'migrated' && $guard < 100 );

        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        // (b) No duplicate: exactly 3 migrated sermons, one per legacy id, and the
        // previously-partial counterpart is the SAME post id (resumed, not re-inserted).
        $this->assertCount( 3, Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ),
            'Resume must complete the partial in place — never insert a duplicate.' );
        foreach ( $data['sermons'] as $legacyId ) {
            $newId = Crosswalk::findNewByLegacyId( $legacyId, Identifiers::POST_TYPE_SERMON );
            $this->assertNotNull( $newId, 'Every legacy sermon has a migrated counterpart.' );
            $this->assertNotEmpty(
                get_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, true ),
                'Every counterpart carries MIGRATION_COMPLETE after resume.'
            );
        }
        $this->assertSame(
            $partialNewId,
            Crosswalk::findNewByLegacyId( $abortedLegacyId, Identifiers::POST_TYPE_SERMON ),
            'The resumed counterpart is the SAME post the abort left partial (no second insert).'
        );
        $resumedRec = ( new MigrationState() )->record( $abortedLegacyId );
        $this->assertNotNull( $resumedRec );
        $this->assertSame( 'complete', $resumedRec['state'], 'The aborted record is recorded complete after resume.' );

        // (c) INVARIANT: legacy byte-equal across the abort + resume.
        $this->assertEquals( $before, $this->legacySnapshot(), 'Legacy byte-equal across abort/resume.' );
    }

    /**
     * The zero-missing-crosswalk precondition gate, enforced as a CONDITION (not
     * merely "the terms phase ran once"). When a sermon-referenced legacy taxonomy
     * read FAILS (a WP_Error — the class of failure TermWriter::migrateAll throws
     * on), the terms phase must NOT be marked complete, the failure must surface as
     * an open flag, and the sermon gate must stay CLOSED (no sermon written).
     *
     * We inject the read failure deterministically via the terms_pre_query filter
     * (returning a WP_Error for one sermon taxonomy) so migrateAll() throws; the
     * Orchestrator catches it, leaves terms incomplete, and surfaces the failure.
     */
    public function test_terms_gate_stays_closed_on_missing_crosswalk_taxonomy_read_failure(): void {
        $data   = $this->seedDataset();
        $before = $this->legacySnapshot();

        $orch = new Orchestrator();
        $orch->detect();

        // Inject a legacy-taxonomy READ FAILURE for one sermon-referenced taxonomy.
        $failingTaxonomy = LegacyIdentifiers::TAX_PREACHER;
        $injector = static function ( $terms, $query ) use ( $failingTaxonomy ) {
            $queried = (array) ( $query->query_vars['taxonomy'] ?? array() );
            if ( in_array( $failingTaxonomy, $queried, true ) ) {
                return new \WP_Error( 'sermonator_test_injected', 'injected legacy taxonomy read failure' );
            }
            return $terms;
        };
        add_filter( 'terms_pre_query', $injector, 10, 2 );

        try {
            $progress = $orch->run( 50 );
        } finally {
            remove_filter( 'terms_pre_query', $injector, 10 );
        }

        // The terms phase must NOT be complete (the gate stays closed).
        $this->assertFalse(
            ( new MigrationState() )->phaseComplete( 'terms' ),
            'Terms must NOT be marked complete when a sermon-referenced taxonomy read fails.'
        );

        // The failure is surfaced as an open flag (not swallowed).
        $this->assertNotEmpty( $progress['flags'], 'A terms read failure must surface a flag.' );
        $surfaced = implode( '|', $progress['flags'] );
        $this->assertStringContainsString( 'legacy_terms_failed', $surfaced,
            'The terms read failure must surface as a legacy_terms_failed flag.' );

        // Sermons stay GATED: the public sermon batch refuses while terms incomplete.
        $this->assertFalse( $orch->runSermonBatch( 50 ),
            'Sermon batch must be refused while the terms gate is closed.' );

        // And nothing was migrated (no sermon, no podcast, no option write).
        $this->assertCount( 0, Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ),
            'No sermon may be written while the terms gate is closed.' );
        $this->assertCount( 0, Crosswalk::migratedPostIds( Identifiers::POST_TYPE_PODCAST ),
            'No podcast may be written while the terms gate is closed.' );

        // INVARIANT: legacy byte-equal (read-only) even on the failure path.
        $this->assertEquals( $before, $this->legacySnapshot(), 'Legacy byte-equal on the gated-failure path.' );

        // RECOVERY: once the injected failure is gone, a re-run completes the terms
        // phase (the gate reopens) — proving the gate is a re-evaluated condition,
        // not a one-shot latch.
        $guard = 0;
        while ( ! ( new MigrationState() )->phaseComplete( 'terms' ) && $guard < 20 ) {
            $orch->run( 50 );
            $guard++;
        }
        $this->assertTrue( ( new MigrationState() )->phaseComplete( 'terms' ),
            'After the read failure clears, the terms phase completes on a re-run.' );
        $this->assertTrue( $orch->runSermonBatch( 50 ),
            'Once terms complete, the sermon gate opens.' );
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
