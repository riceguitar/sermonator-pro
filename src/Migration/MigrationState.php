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
 * the single exception of the Rollback-flagged retreat migrated → detected (so a
 * verified run cannot be silently un-done, and a finalized run can never retreat).
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
     *  - migrated → detected ONLY when $rollback === true (the Rollback retreat).
     *
     * Any other transition (a multi-step skip such as detected → finalized, an
     * unflagged backward move, or a flagged retreat from anything other than
     * migrated) throws and leaves the persisted phase unchanged.
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
            // The ONLY sanctioned retreat: a completed-but-unverified migration is
            // rolled back to detected so it can be re-run. Never from verified
            // (would silently undo verification) nor from finalized (irreversible).
            if ( $current === 'migrated' && $phase === 'detected' ) {
                $this->persistPhase( $data, $phase );
                return;
            }
            throw new \InvalidArgumentException(
                sprintf( 'Illegal rollback transition "%s" → "%s" (only migrated → detected is allowed).', $current, $phase )
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
     * Capture the detect-time manifest (the legacy-shape oracle).
     */
    public function setManifest( Manifest $m ): void {
        $data             = $this->load();
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
     *   manifest?: array{counts: array<string,int>, checksums: array<int,string>}
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
