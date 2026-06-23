<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * The migration lifecycle conductor: it assembles the per-record B2a writers
 * (TermWriter, ArtworkWriter, PodcastWriter, SermonWriter, OptionWriter) into a
 * resumable, lock-guarded, hard-gated, chunked state machine on top of the
 * durable MigrationState.
 *
 * GUARANTEES (each a closed adversarial hole — preserved as tests):
 *
 *  - HARD ORDERING GATES. Work advances terms → (sermons / artwork / podcasts) →
 *    options(default-podcast), never skipping. Sermons, artwork, and podcasts are
 *    REFUSED until the terms phase is complete (TermWriter::migrateAll ran with
 *    zero missing crosswalks). The default-podcast option pointer is REFUSED until
 *    every podcast is migrated (so it never resolves to a dangling legacy id).
 *
 *  - SINGLE ADVISORY LOCK. A TTL sentinel in an option serializes runs: a second
 *    concurrent run() (a cron run racing an admin run) cannot acquire the lock and
 *    refuses with a 'locked' flag, writing nothing — no two processes ever insert
 *    concurrently. The lock self-expires after a TTL so a crashed run cannot wedge
 *    the migration forever.
 *
 *  - CHUNKED / RESUMABLE. run(batchSize) advances at most ONE bounded chunk of
 *    work per call (up to $batchSize sermons / podcasts) and is re-callable until
 *    phase()==='migrated'. Because every writer is itself idempotent on its
 *    authoritative back-ref probe, a crash mid-batch then a fresh run() RESUMES:
 *    already-complete records are skipped (never duplicated) and partials are
 *    redone. Per-record state is recorded on MigrationState so a partial is
 *    distinguishable from a complete.
 *
 *  - MONOTONIC STATE. The phase advances detected → migrating → migrated and is
 *    set to 'migrated' ONLY when every phase reports complete. The Orchestrator
 *    NEVER sets 'verified' — that is the Verifier's sole responsibility.
 *
 *  - LEGACY READ-ONLY. Every writer reads legacy data read-only; the Orchestrator
 *    adds no legacy writes. Legacy rows are byte-for-byte unchanged until Finalize.
 */
final class Orchestrator {
    /** Option holding the advisory lock sentinel (autoload=no). */
    public const OPTION_LOCK = 'sermonator_migration_lock';

    /** Lock TTL in seconds: a crashed run's lock self-expires so it cannot wedge. */
    private const LOCK_TTL = 900;

    /**
     * This caller's per-acquire ownership token, set when WE win the lock and used to
     * make releaseLock() ownership-checked (so a slow holder that has already been
     * reclaimed away never deletes its successor's live lock). Null when we hold no lock.
     */
    private ?string $lockToken = null;

    /** Phase-keys recorded on MigrationState for the precondition gates. */
    private const PHASE_TERMS    = 'terms';
    private const PHASE_ARTWORK  = 'artwork';
    private const PHASE_PODCASTS = 'podcasts';
    private const PHASE_SERMONS  = 'sermons';
    private const PHASE_OPTIONS  = 'options';

    private Detector $detector;
    private TermWriter $termWriter;
    private ArtworkWriter $artworkWriter;
    private SermonWriter $sermonWriter;
    private PodcastWriter $podcastWriter;
    private OptionWriter $optionWriter;
    private MigrationState $state;

    public function __construct(
        ?Detector $detector = null,
        ?TermWriter $termWriter = null,
        ?ArtworkWriter $artworkWriter = null,
        ?SermonWriter $sermonWriter = null,
        ?PodcastWriter $podcastWriter = null,
        ?OptionWriter $optionWriter = null,
        ?MigrationState $state = null
    ) {
        $this->detector      = $detector ?? new Detector();
        $this->termWriter    = $termWriter ?? new TermWriter();
        $this->artworkWriter = $artworkWriter ?? new ArtworkWriter();
        $this->sermonWriter  = $sermonWriter ?? new SermonWriter();
        $this->podcastWriter = $podcastWriter ?? new PodcastWriter();
        $this->optionWriter  = $optionWriter ?? new OptionWriter();
        $this->state         = $state ?? new MigrationState();
    }

    /**
     * Run the read-only Detector, persist the manifest (the legacy-shape backup
     * oracle the Verifier/Finalizer compare against), and advance the phase to
     * 'detected'. Idempotent while still at none/detected: re-detecting refreshes the
     * manifest and re-sets the same phase (a no-op transition).
     *
     * ONCE MIGRATION HAS BEGUN (phase advanced past 'detected') re-detect is a SAFE
     * NO-OP that returns the EXISTING detect-time manifest WITHOUT re-baselining it.
     * Re-pinning the manifest checksums to current (possibly post-edit) legacy values
     * would silently defeat the source-fixity drift oracle and let a drifted legacy
     * record verify clean and be finalized — irreversible loss of the church's true
     * source. The stored manifest is the immutable fixity oracle; a deliberate
     * re-detect requires first retreating to 'detected' via Rollback.
     */
    public function detect(): Manifest {
        // Legacy reads must work with the legacy plugin DEACTIVATED.
        LegacySchemaRegistrar::ensureRegistered();

        $phase = $this->state->phase();
        if ( ! in_array( $phase, array( 'none', 'detected' ), true ) ) {
            $existing = $this->state->manifest();
            if ( $existing !== null ) {
                // Do NOT re-baseline — return the immutable detect-time manifest.
                return $existing;
            }
            // Defensive only: an advanced phase with no stored manifest cannot poison
            // an oracle that does not exist, so fall through to a fresh detect.
        }

        $manifest = $this->detector->detect();
        $this->state->setManifest( $manifest );

        // none → detected. Re-detect from 'detected' is an idempotent no-op.
        if ( $phase === 'none' ) {
            $this->state->set( 'detected' );
        }

        return $manifest;
    }

    /**
     * Advance at most ONE bounded chunk of work and return progress. Re-callable
     * until phase()==='migrated'.
     *
     * The whole call is serialized by the single advisory lock: a concurrent run
     * that cannot acquire it refuses immediately (flags: ['locked']) and writes
     * nothing. The lock is always released in a finally so a normal return never
     * wedges the next call.
     *
     * Work order (hard-gated, never skipped):
     *   1. terms      — TermWriter::migrateAll (idempotent, resumable); on success
     *                   marks the 'terms' phase complete (the gate for everything
     *                   below).
     *   2. artwork    — only after terms complete; ArtworkWriter::migrate once.
     *   3. podcasts    — only after terms complete; up to $batchSize per call.
     *   4. sermons    — only after terms complete; up to $batchSize per call.
     *   5. options    — only after podcasts complete (so the default-podcast
     *                   pointer resolves to a real new id); OptionWriter::migrate.
     * Each call advances the earliest incomplete phase and returns — so a caller
     * loops run() to completion. 'migrated' is set only when EVERY phase completes.
     *
     * @return array{phase:string, done:int, remaining:int, flags:list<string>}
     */
    public function run( int $batchSize = 50 ): array {
        if ( $batchSize < 1 ) {
            $batchSize = 1;
        }

        if ( ! $this->acquireLock() ) {
            return $this->progress( array( 'locked' ) );
        }

        try {
            // Nothing to do before detect, and nothing after migrated (the Verifier
            // owns the verified transition — the Orchestrator never advances past
            // migrated).
            $phase = $this->state->phase();
            if ( $phase === 'none' ) {
                return $this->progress( array( 'not_detected' ) );
            }
            if ( in_array( $phase, array( 'migrated', 'verified', 'finalized' ), true ) ) {
                return $this->progress( array() );
            }

            // Enter the migrating phase on the first chunk of work.
            if ( $phase === 'detected' ) {
                $this->state->set( 'migrating' );
            }

            $flags = array();

            // (1) Terms — the gate for everything else.
            if ( ! $this->state->phaseComplete( self::PHASE_TERMS ) ) {
                $flags = $this->runTermPhase();
                return $this->progress( $flags );
            }

            // (2) Artwork — gated on terms complete.
            if ( ! $this->state->phaseComplete( self::PHASE_ARTWORK ) ) {
                $flags = $this->runArtworkPhase();
                return $this->progress( $flags );
            }

            // (3) Podcasts — gated on terms complete; chunked.
            if ( ! $this->state->phaseComplete( self::PHASE_PODCASTS ) ) {
                $flags = $this->runPodcastBatchInternal( $batchSize );
                return $this->progress( $flags );
            }

            // (4) Sermons — gated on terms complete; chunked.
            if ( ! $this->state->phaseComplete( self::PHASE_SERMONS ) ) {
                $flags = $this->runSermonBatchInternal( $batchSize );
                return $this->progress( $flags );
            }

            // (5) Options — gated on podcasts complete (so default-podcast resolves).
            if ( ! $this->state->phaseComplete( self::PHASE_OPTIONS ) ) {
                $flags = $this->runOptionPhase();
                return $this->progress( $flags );
            }

            // Every phase complete → migrated (monotonic, one step from migrating).
            $this->state->set( 'migrated' );
            return $this->progress( $flags );
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Public sermon-batch entry used by tests (and a CLI fast-path) to drive ONLY
     * the sermon phase. HARD-GATED: refuses (returns false) unless the terms phase
     * is complete, so no sermon is ever written before its term crosswalks exist.
     *
     * @return bool True if the batch ran; false if refused by the terms gate.
     */
    public function runSermonBatch( int $batchSize = 50 ): bool {
        if ( ! $this->state->phaseComplete( self::PHASE_TERMS ) ) {
            return false;
        }
        $this->runSermonBatchInternal( max( 1, $batchSize ) );
        return true;
    }

    /**
     * Public podcast-batch entry, symmetric with runSermonBatch. HARD-GATED on the
     * terms phase.
     *
     * @return bool True if the batch ran; false if refused by the terms gate.
     */
    public function runPodcastBatch( int $batchSize = 50 ): bool {
        if ( ! $this->state->phaseComplete( self::PHASE_TERMS ) ) {
            return false;
        }
        $this->runPodcastBatchInternal( max( 1, $batchSize ) );
        return true;
    }

    // ---------------------------------------------------------------------------
    // Phase implementations
    // ---------------------------------------------------------------------------

    /**
     * Terms: TermWriter::migrateAll is itself idempotent and resumable, so we run
     * it to completion in one phase call. It migrates all 5 taxonomies (orphans
     * included).
     *
     * ZERO-MISSING-CROSSWALK GATE (the contract the sermon/artwork/podcast gates
     * depend on). The plan's must_handle requires the terms phase be marked
     * complete ONLY when "TermWriter::migrateAll completed with zero missing
     * crosswalks for sermon-referenced taxonomies" — not merely "the terms phase
     * ran once". We therefore enforce that contract here as an explicit condition
     * rather than trusting that migrateAll() always throws on every failure mode:
     *
     *  (1) migrateAll() THROWS on a WP_Error legacy-taxonomy read and on a >1
     *      divergent mapping. We CATCH that throw, surface it as an open
     *      legacy_terms_failed flag, and leave the terms phase INCOMPLETE so the
     *      sermon/artwork/podcast gates stay closed (no partial-terms migration is
     *      ever allowed to unlock the records that reference those terms).
     *  (2) After a non-throwing run we INDEPENDENTLY re-verify, via the back-ref
     *      crosswalk, that every legacy term in every sermon-referenced taxonomy
     *      resolved to exactly one migrated counterpart. Any legacy term without a
     *      counterpart is a missing crosswalk: we surface
     *      missing_term_crosswalk:<taxonomy>:<legacyTermId> and REFUSE
     *      markPhaseComplete('terms'). Re-callable: a later run() retries the term
     *      phase and, once the gap closes, marks it complete.
     *
     * This makes the gate "terms migrated with zero missing crosswalks", and is
     * proven by tests that drive a taxonomy-read failure (terms NOT complete,
     * sermons stay gated) and a clean run (terms complete).
     *
     * @return list<string> Flags surfaced by the term writer (plus any gate flags).
     */
    private function runTermPhase(): array {
        // Legacy reads (here and inside migrateAll) must work with the legacy
        // plugin DEACTIVATED — re-register the legacy taxonomies first.
        LegacySchemaRegistrar::ensureRegistered();

        try {
            $result = $this->termWriter->migrateAll();
        } catch ( \Throwable $e ) {
            // A genuine failure (WP_Error read, >1 divergent mapping). Surface it
            // and leave terms INCOMPLETE so nothing that references terms unlocks.
            return array( 'legacy_terms_failed:' . $e->getMessage() );
        }

        $flags = array_values( (array) ( $result['flags'] ?? array() ) );

        // Independent zero-missing-crosswalk gate over EVERY sermon-referenced
        // taxonomy. Only when it is fully satisfied do we open the gate.
        $missing = $this->missingTermCrosswalks();
        if ( $missing !== array() ) {
            return array_values( array_merge( $flags, $missing ) );
        }

        $this->state->markPhaseComplete( self::PHASE_TERMS );
        return $flags;
    }

    /**
     * Enumerate sermon-referenced legacy taxonomies and assert every legacy term
     * resolved to a migrated counterpart via the back-ref crosswalk. Returns one
     * missing_term_crosswalk:<taxonomy>:<legacyTermId> flag per gap (empty when the
     * terms migration is genuinely complete). Read-only.
     *
     * @return list<string>
     */
    private function missingTermCrosswalks(): array {
        LegacySchemaRegistrar::ensureRegistered();

        $missing = array();
        foreach ( LegacyIdentifiers::sermonTaxonomies() as $legacyTaxonomy ) {
            $targetTaxonomy = MappingContract::taxonomyMap()[ $legacyTaxonomy ] ?? null;
            if ( $targetTaxonomy === null ) {
                continue;
            }

            $terms = get_terms( array(
                'taxonomy'   => $legacyTaxonomy,
                'hide_empty' => false,
            ) );

            if ( is_wp_error( $terms ) ) {
                // A read failure here is the same class of gap migrateAll throws on;
                // treat it as a hard miss so the gate stays closed.
                $missing[] = 'legacy_taxonomy_unreadable:' . $legacyTaxonomy;
                continue;
            }

            foreach ( (array) $terms as $legacyTerm ) {
                $legacyTermId = (int) $legacyTerm->term_id;
                if ( Crosswalk::findNewTermByLegacyId( $legacyTermId, $targetTaxonomy ) === null ) {
                    $missing[] = 'missing_term_crosswalk:' . $legacyTaxonomy . ':' . $legacyTermId;
                }
            }
        }

        return array_values( $missing );
    }

    /**
     * Artwork: a single bounded option-remap pass (ArtworkWriter::migrate), gated
     * on terms-complete (its tt_id crosswalk needs the migrated terms). Idempotent.
     *
     * @return list<string>
     */
    private function runArtworkPhase(): array {
        $result = $this->artworkWriter->migrate( new TermCrosswalk() );
        $this->state->markPhaseComplete( self::PHASE_ARTWORK );

        $flags = array();
        foreach ( array_map( 'intval', (array) ( $result['dropped'] ?? array() ) ) as $tt ) {
            $flags[] = 'artwork_dropped:' . $tt;
        }
        foreach ( array_map( 'intval', (array) ( $result['conflicts'] ?? array() ) ) as $tt ) {
            $flags[] = 'artwork_conflict:' . $tt;
        }
        return $flags;
    }

    /**
     * Podcasts: migrate up to $batchSize not-yet-complete legacy podcasts per call,
     * recording per-record state. Marks the podcasts phase complete when every
     * legacy podcast is migrated.
     *
     * @return list<string>
     */
    private function runPodcastBatchInternal( int $batchSize ): array {
        $flags = $this->runPostBatch(
            LegacyIdentifiers::POST_TYPE_PODCAST,
            Identifiers::POST_TYPE_PODCAST,
            $batchSize,
            fn( int $legacyId ): WriteResult => $this->podcastWriter->write( $legacyId )
        );

        if ( $this->allPostsComplete( LegacyIdentifiers::POST_TYPE_PODCAST, Identifiers::POST_TYPE_PODCAST ) ) {
            $this->state->markPhaseComplete( self::PHASE_PODCASTS );
        }

        return $flags;
    }

    /**
     * Sermons: migrate up to $batchSize not-yet-complete legacy sermons per call,
     * recording per-record state. Marks the sermons phase complete when every
     * legacy sermon is migrated.
     *
     * @return list<string>
     */
    private function runSermonBatchInternal( int $batchSize ): array {
        $flags = $this->runPostBatch(
            LegacyIdentifiers::POST_TYPE_SERMON,
            Identifiers::POST_TYPE_SERMON,
            $batchSize,
            fn( int $legacyId ): WriteResult => $this->sermonWriter->write( $legacyId )
        );

        if ( $this->allPostsComplete( LegacyIdentifiers::POST_TYPE_SERMON, Identifiers::POST_TYPE_SERMON ) ) {
            $this->state->markPhaseComplete( self::PHASE_SERMONS );
        }

        return $flags;
    }

    /**
     * Options: OptionWriter::migrate writes the sermonmanager_* → sermonator_*
     * options plus the default-podcast pointer (which resolves only because we run
     * this AFTER the podcasts phase is complete). Idempotent.
     *
     * @return list<string>
     */
    private function runOptionPhase(): array {
        $this->optionWriter->migrate();
        $this->state->markPhaseComplete( self::PHASE_OPTIONS );
        return array();
    }

    /**
     * Shared post-batch driver for sermons and podcasts. Enumerates legacy ids in
     * ascending id order, skips ids already recorded complete (resume-safe), and
     * writes up to $batchSize not-yet-complete records this call. Each write's
     * outcome is recorded on MigrationState so a partial is distinguishable from a
     * complete — the spine that makes a crash/resume duplicate-free.
     *
     * @param callable(int):WriteResult $write
     * @return list<string>
     */
    private function runPostBatch( string $legacyType, string $newType, int $batchSize, callable $write ): array {
        LegacySchemaRegistrar::ensureRegistered();

        $legacyIds = $this->legacyPostIds( $legacyType );
        $flags     = array();
        $written   = 0;

        foreach ( $legacyIds as $legacyId ) {
            if ( $written >= $batchSize ) {
                break;
            }

            // Resume-safe skip: a record already recorded complete is not touched.
            $rec = $this->state->record( $legacyId );
            if ( $rec !== null && $rec['state'] === 'complete' ) {
                continue;
            }

            // Mark in_progress BEFORE the write so a crash mid-write leaves a
            // detectable partial (distinct from complete). The writer itself is
            // idempotent on its back-ref probe, so re-entry never duplicates.
            $this->state->recordRecord( $legacyId, 'in_progress', null, array() );

            $result = $write( $legacyId );

            $recordState = $this->writeResultIsComplete( $newType, $result ) ? 'complete' : 'in_progress';
            $this->state->recordRecord( $legacyId, $recordState, $result->newId ?: null, $result->flags );

            foreach ( $result->flags as $flag ) {
                $flags[] = (string) $flag;
            }

            $written++;
        }

        return $flags;
    }

    /**
     * A write counts as complete (for per-record bookkeeping) when it produced a
     * real new id AND the new record carries the MIGRATION_COMPLETE stamp the
     * writer writes LAST. We re-read the authoritative stamp rather than trust the
     * WriteResult shape so a stamped-but-partial resume is correctly recorded as
     * still in_progress.
     */
    private function writeResultIsComplete( string $newType, WriteResult $result ): bool {
        if ( $result->newId <= 0 ) {
            return false;
        }
        $complete = get_post_meta( $result->newId, Crosswalk::MIGRATION_COMPLETE, true );
        return ! empty( $complete );
    }

    /**
     * Whether every legacy post of a type has a complete migrated counterpart.
     * Source of truth is the authoritative MIGRATION_COMPLETE stamp on the new
     * post (resolved via the back-ref), so a stamped-but-partial record does NOT
     * count the phase complete.
     */
    private function allPostsComplete( string $legacyType, string $newType ): bool {
        foreach ( $this->legacyPostIds( $legacyType ) as $legacyId ) {
            $newId = Crosswalk::findNewByLegacyId( $legacyId, $newType );
            if ( $newId === null ) {
                return false;
            }
            if ( empty( get_post_meta( $newId, Crosswalk::MIGRATION_COMPLETE, true ) ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * All legacy post ids of a type, ascending, status-agnostic. Read-only.
     *
     * @return list<int>
     */
    private function legacyPostIds( string $legacyType ): array {
        LegacySchemaRegistrar::ensureRegistered();

        $ids = get_posts( array(
            'post_type'      => $legacyType,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ) );

        return array_values( array_map( 'intval', (array) $ids ) );
    }

    // ---------------------------------------------------------------------------
    // Advisory lock
    // ---------------------------------------------------------------------------

    /**
     * Acquire the single advisory lock. Returns true if this caller now holds it,
     * false if another live (non-expired) holder has it.
     *
     * The lock VALUE is "<timestamp>|<per-acquire-token>": the timestamp drives TTL
     * expiry, the token makes ownership provable. Acquisition is atomic on BOTH paths:
     *  - FRESH: add_option is an atomic INSERT, so exactly one of N racing callers
     *    wins a brand-new lock.
     *  - EXPIRED RECLAIM (a crashed/overrun holder, older than LOCK_TTL): a
     *    COMPARE-AND-SWAP — a conditional UPDATE that only matches the exact stale
     *    value — so exactly ONE concurrent reclaimer wins (affected-rows === 1) and
     *    every other reclaimer's WHERE no longer matches and loses. A plain
     *    update_option would let two reclaimers both "win" and insert concurrently;
     *    the CAS closes that race.
     * Direct $wpdb reads/writes (uncached) are used for the held value, the CAS, and
     * the ownership-checked release so a concurrent holder in another process is
     * always observed; the option cache is busted after each direct write.
     */
    public function acquireLock(): bool {
        global $wpdb;

        $now   = time();
        $value = $now . '|' . $this->newLockToken();

        // Fast path: a fresh, atomic INSERT. add_option returns false if the row
        // already exists, so exactly one of N racing callers wins a brand-new lock.
        if ( add_option( self::OPTION_LOCK, $value, '', 'no' ) ) {
            $this->lockToken = $value;
            return true;
        }

        // The lock row exists. Read the CURRENT raw value DIRECTLY (uncached) so a
        // concurrent holder in another process is observed, and decide expiry.
        $heldRaw = $this->readLockRaw();
        $heldTs  = $this->lockTimestamp( $heldRaw );
        if ( $heldRaw !== null && $heldTs > 0 && ( $now - $heldTs ) < self::LOCK_TTL ) {
            return false; // A live holder owns it.
        }

        // Expired (or corrupt). Reclaim via COMPARE-AND-SWAP: only the single racer
        // whose UPDATE still matches the stale value wins.
        $reclaimed = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $value,
                self::OPTION_LOCK,
                (string) $heldRaw
            )
        );
        wp_cache_delete( self::OPTION_LOCK, 'options' );

        if ( $reclaimed === 1 ) {
            $this->lockToken = $value;
            return true;
        }
        return false; // Lost the reclaim race to a concurrent reclaimer.
    }

    /**
     * Release the advisory lock — OWNERSHIP-CHECKED. Deletes the sentinel ONLY if it
     * still carries OUR token, so a slow/overrun holder whose lock was already
     * reclaimed by a successor no-ops here instead of tearing down the successor's
     * live lock (which would re-open the concurrency window). Safe to call when not
     * held (no token → no-op).
     */
    public function releaseLock(): void {
        global $wpdb;

        if ( $this->lockToken === null ) {
            return; // We never acquired it — never delete another run's lock.
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                self::OPTION_LOCK,
                $this->lockToken
            )
        );
        wp_cache_delete( self::OPTION_LOCK, 'options' );
        $this->lockToken = null;
    }

    /** Read the raw lock option value DIRECTLY from the DB (uncached), or null. */
    private function readLockRaw(): ?string {
        global $wpdb;
        $val = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK )
        );
        return $val === null ? null : (string) $val;
    }

    /** The leading "<timestamp>" component of a "<timestamp>|<token>" lock value. */
    private function lockTimestamp( ?string $raw ): int {
        if ( $raw === null || $raw === '' ) {
            return 0;
        }
        $parts = explode( '|', $raw, 2 );
        return (int) $parts[0];
    }

    /** A per-process, per-acquire ownership token (more_entropy so two near-simultaneous acquires differ). */
    private function newLockToken(): string {
        return uniqid( 'sermonator_lock_', true );
    }

    // ---------------------------------------------------------------------------
    // Status / progress
    // ---------------------------------------------------------------------------

    /**
     * A lightweight status snapshot for the CLI / admin: the current phase, the
     * detect-time manifest counts, and any open per-record flags.
     *
     * @return array{phase:string, counts:array<string,int>, done:int, remaining:int, flags:list<string>}
     */
    public function status(): array {
        $manifest = $this->state->manifest();
        $counts   = $manifest !== null ? $manifest->counts() : array();
        $progress = $this->progress( $this->openRecordFlags() );

        return array(
            'phase'     => $this->state->phase(),
            'counts'    => $counts,
            'done'      => $progress['done'],
            'remaining' => $progress['remaining'],
            'flags'     => $progress['flags'],
        );
    }

    /**
     * Build the progress return shape. done/remaining count migratable RECORDS
     * (sermons + podcasts) by their complete counterpart, so a caller sees forward
     * movement across run() calls.
     *
     * @param list<string> $flags
     * @return array{phase:string, done:int, remaining:int, flags:list<string>}
     */
    private function progress( array $flags ): array {
        $sermonTotal  = count( $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_SERMON ) );
        $podcastTotal = count( $this->legacyPostIds( LegacyIdentifiers::POST_TYPE_PODCAST ) );
        $total        = $sermonTotal + $podcastTotal;

        $done = count( Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) )
            + count( Crosswalk::migratedPostIds( Identifiers::POST_TYPE_PODCAST ) );
        $done = min( $done, $total );

        return array(
            'phase'     => $this->state->phase(),
            'done'      => $done,
            'remaining' => max( 0, $total - $done ),
            'flags'     => array_values( $flags ),
        );
    }

    /**
     * Collect open per-record flags recorded on the state (for status reporting).
     *
     * @return list<string>
     */
    private function openRecordFlags(): array {
        $flags = array();
        foreach ( array( LegacyIdentifiers::POST_TYPE_SERMON, LegacyIdentifiers::POST_TYPE_PODCAST ) as $type ) {
            foreach ( $this->legacyPostIds( $type ) as $legacyId ) {
                $rec = $this->state->record( $legacyId );
                if ( $rec !== null ) {
                    foreach ( $rec['flags'] as $flag ) {
                        $flags[] = (string) $flag;
                    }
                }
            }
        }
        return array_values( array_unique( $flags ) );
    }
}
