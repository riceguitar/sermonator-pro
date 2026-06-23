<?php

declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Durable, option-backed state machine + per-record progress for the migration
 * lifecycle.
 *
 * EVERYTHING is persisted in a single option (Identifiers::OPTION_MIGRATION_STATE,
 * autoload=no) so it survives a process restart: every mutator reloads, mutates,
 * and writes back, and every accessor reloads — so two MigrationState objects (an
 * admin request and a cron run, or a fresh object after a crash) observe the same
 * truth. There are no custom DB tables.
 *
 * The lifecycle PHASE is monotonic:
 *   none → detected → migrating → migrated → verified → finalized
 * advancing exactly one step at a time, never skipping nor moving backward — with
 * the single exception of the Rollback-flagged retreat to detected from either
 * migrating (a mid-batch crash) or migrated (a complete-but-unverified run), so a
 * verified run cannot be silently un-done and a finalized run can never retreat.
 * Re-setting the current phase is an idempotent no-op (safe under resume).
 *
 * PER-RECORD progress (keyed by legacy id) carries state ∈
 * pending|in_progress|complete|failed, the new id, and any migration flags. The
 * separate in_progress marker is what makes a stamped-but-partial record
 * detectable as distinct from complete, so a resumed run redoes partials and
 * never duplicates completes.
 *
 * The MANIFEST is captured at detect time (the legacy-shape backup oracle) for the
 * Verifier and Finalizer.
 */
final class MigrationState {
    /** Ordered lifecycle phases. The index in this list IS the monotonic rank. */
    private const PHASES = array( 'none', 'detected', 'migrating', 'migrated', 'verified', 'finalized' );

    /** Legal per-record states. */
    private const RECORD_STATES = array( 'pending', 'in_progress', 'complete', 'failed' );

    /**
     * Read the current lifecycle phase.
     */
    public function phase(): string {
        $data = $this->load();
        return $data['phase'];
    }

    /**
     * Advance the lifecycle phase, enforcing monotonicity.
     *
     * Allowed:
     *  - the immediate next phase (one step forward);
     *  - the same phase (idempotent no-op);
     *  - migrated → detected ONLY when $rollback === true (the Rollback retreat);
     *  - migrating → detected ONLY when $rollback === true (the Rollback retreat
     *    after a real mid-batch crash that left the phase at 'migrating');
     *  - verified → detected ONLY when $rollback === true (the Rollback retreat from
     *    the normal pre-finalize review state — legacy is still byte-intact at
     *    'verified', so a review-then-reject rollback is fully reversible and must
     *    return the lifecycle to 'detected' for a corrected re-migration).
     *
     * Any other transition (a multi-step skip such as detected → finalized, an
     * unflagged backward move, or a flagged retreat from anything other than
     * migrating/migrated/verified — notably the irreversible finalized) throws and
     * leaves the persisted phase unchanged.
     *
     * @throws \InvalidArgumentException on an unknown or illegal transition.
     */
    public function set( string $phase, bool $rollback = false ): void {
        if ( ! in_array( $phase, self::PHASES, true ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Unknown migration phase "%s".', $phase )
            );
        }

        $data    = $this->load();
        $current = $data['phase'];

        if ( $phase === $current ) {
            return; // Idempotent: re-setting the current phase is a safe no-op.
        }

        if ( $rollback ) {
            // The ONLY sanctioned retreats, all to 'detected' so a corrected
            // migration can re-run:
            //  - migrated → detected:  a completed-but-unverified migration;
            //  - migrating → detected: a migration that crashed mid-batch (the phase
            //    never reached 'migrated'). Rollback still cleans up the partial
            //    records + un-stamped orphans and must return the lifecycle here;
            //  - verified → detected:  a review-then-reject before the point of no
            //    return. Legacy is still byte-intact at 'verified' (Finalize has not
            //    run), so this is fully reversible and Rollback must NOT leave the
            //    phase stuck at 'verified' over now-deleted migrated data.
            // Never from finalized (irreversible — Finalize already deleted legacy).
            if ( in_array( $current, array( 'migrated', 'migrating', 'verified' ), true ) && $phase === 'detected' ) {
                $this->persistPhase( $data, $phase );
                return;
            }
            throw new \InvalidArgumentException(
                sprintf( 'Illegal rollback transition "%s" → "%s" (only migrating/migrated/verified → detected is allowed).', $current, $phase )
            );
        }

        $currentRank = (int) array_search( $current, self::PHASES, true );
        $nextRank    = (int) array_search( $phase, self::PHASES, true );

        if ( $nextRank !== $currentRank + 1 ) {
            throw new \InvalidArgumentException(
                sprintf( 'Illegal migration transition "%s" → "%s" (phases advance one step at a time).', $current, $phase )
            );
        }

        $this->persistPhase( $data, $phase );
    }

    /**
     * Record (or overwrite) the progress of a single legacy record.
     *
     * @param int          $legacyId Legacy source id.
     * @param string       $state    One of pending|in_progress|complete|failed.
     * @param int|null     $newId    The migrated record's new id, if known.
     * @param list<string> $flags    Migration flags surfaced for this record.
     *
     * @throws \InvalidArgumentException on an unknown $state.
     */
    public function recordRecord( int $legacyId, string $state, ?int $newId, array $flags ): void {
        if ( ! in_array( $state, self::RECORD_STATES, true ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Unknown record state "%s".', $state )
            );
        }

        $data = $this->load();
        $data['records'][ (string) $legacyId ] = array(
            'state' => $state,
            'newId' => $newId,
            'flags' => array_values( $flags ),
        );
        $this->save( $data );
    }

    /**
     * Read a single legacy record's progress, or null if never recorded.
     *
     * @return array{state: string, newId: int|null, flags: list<string>}|null
     */
    public function record( int $legacyId ): ?array {
        $data = $this->load();
        $rec  = $data['records'][ (string) $legacyId ] ?? null;
        if ( $rec === null ) {
            return null;
        }
        return array(
            'state' => (string) $rec['state'],
            'newId' => isset( $rec['newId'] ) ? ( $rec['newId'] === null ? null : (int) $rec['newId'] ) : null,
            'flags' => array_values( array_map( 'strval', (array) ( $rec['flags'] ?? array() ) ) ),
        );
    }

    /**
     * Clear ALL per-record progress (and the phase-complete markers) so a Rollback
     * leaves no stale complete/in_progress markers pointing at now-deleted posts and a
     * re-migration starts from pristine bookkeeping. The lifecycle phase and the
     * detect-time manifest are intentionally LEFT intact (Rollback retreats the phase
     * to 'detected' separately, and the manifest is still the legacy-shape oracle).
     */
    public function resetRecords(): void {
        $data                  = $this->load();
        $data['records']       = array();
        $data['phaseComplete'] = array();
        $this->save( $data );
    }

    /**
     * Capture the detect-time manifest (the legacy-shape oracle).
     *
     * WRITE-ONCE w.r.t. lifecycle, in the precise sense of NO OVERWRITE. The manifest
     * is the IMMUTABLE detect-time fixity snapshot the drift oracle compares the live
     * legacy source against. Once a manifest EXISTS, overwriting it after work has begun
     * would re-pin checksums to current (possibly post-edit) values, silently destroying
     * the drift oracle and letting a drifted legacy record verify clean and be finalized
     * (irreversible loss of the church's true source) — so an OVERWRITE is refused in any
     * phase past 'detected'. A FIRST write (no manifest yet stored) is always permitted,
     * even at an advanced phase: there is no oracle to poison, and this is the sanctioned
     * defensive recovery for a corrupted/partial state row (Orchestrator::detect's
     * advanced-phase-with-no-manifest fall-through). A deliberate re-detect/re-baseline
     * requires first retreating to 'detected' via Rollback.
     *
     * @throws \InvalidArgumentException when OVERWRITING an existing manifest past 'detected'.
     */
    public function setManifest( Manifest $m ): void {
        $data               = $this->load();
        $alreadyHasManifest = isset( $data['manifest'] ) && is_array( $data['manifest'] );
        if ( $alreadyHasManifest && ! in_array( $data['phase'], array( 'none', 'detected' ), true ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Refusing to OVERWRITE the detect-time manifest in phase "%s" — it is the immutable fixity oracle (re-detect only after a rollback to "detected").',
                    $data['phase']
                )
            );
        }
        $data['manifest'] = $m->toArray();
        $this->save( $data );
    }

    /**
     * The detect-time manifest, or null if detect has not run.
     */
    public function manifest(): ?Manifest {
        $data = $this->load();
        if ( ! isset( $data['manifest'] ) || ! is_array( $data['manifest'] ) ) {
            return null;
        }
        return Manifest::fromArray( $data['manifest'] );
    }

    /**
     * Whether a named migration phase-key (e.g. 'terms', 'sermons') is complete.
     */
    public function phaseComplete( string $phaseKey ): bool {
        $data = $this->load();
        return ! empty( $data['phaseComplete'][ $phaseKey ] );
    }

    /**
     * Mark a named migration phase-key complete (the Orchestrator's precondition
     * gate reads this).
     */
    public function markPhaseComplete( string $phaseKey ): void {
        $data = $this->load();
        $data['phaseComplete'][ $phaseKey ] = true;
        $this->save( $data );
    }

    /**
     * Persist a phase onto an already-loaded $data array.
     *
     * @param array<string,mixed> $data
     */
    private function persistPhase( array $data, string $phase ): void {
        $data['phase'] = $phase;
        $this->save( $data );
    }

    /**
     * Load the durable state from the option, normalized to a full shape so every
     * caller can rely on the keys existing.
     *
     * @return array{
     *   phase: string,
     *   records: array<string,array{state:string,newId:int|null,flags:list<string>}>,
     *   phaseComplete: array<string,bool>,
     *   manifest?: array{counts: array<string,int>, checksums: array<int,string>, podcastChecksums?: array<int,string>}
     * }
     */
    private function load(): array {
        $raw = get_option( Identifiers::OPTION_MIGRATION_STATE, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }

        $phase = isset( $raw['phase'] ) && in_array( $raw['phase'], self::PHASES, true )
            ? (string) $raw['phase']
            : 'none';

        $data = array(
            'phase'         => $phase,
            'records'       => is_array( $raw['records'] ?? null ) ? $raw['records'] : array(),
            'phaseComplete' => is_array( $raw['phaseComplete'] ?? null ) ? $raw['phaseComplete'] : array(),
        );

        if ( isset( $raw['manifest'] ) && is_array( $raw['manifest'] ) ) {
            $data['manifest'] = $raw['manifest'];
        }

        return $data;
    }

    /**
     * Persist the full state. Always autoload=no — the migration state is touched
     * only during migration, never on a normal page load.
     *
     * @param array<string,mixed> $data
     */
    private function save( array $data ): void {
        update_option( Identifiers::OPTION_MIGRATION_STATE, $data, false );
    }
}
