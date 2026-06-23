<?php

declare(strict_types=1);

namespace Sermonator\Cli;

use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\Verifier;
use Sermonator\Migration\Rollback;
use Sermonator\Migration\Finalizer;
use Sermonator\Migration\MigrationState;

/**
 * WP-CLI surface for the Sermonator migration lifecycle (design-notes item 20).
 *
 *   wp sermonator migration detect
 *   wp sermonator migration migrate [--batch-size=<n>]
 *   wp sermonator migration verify
 *   wp sermonator migration rollback --yes
 *   wp sermonator migration finalize --yes
 *   wp sermonator migration status
 *
 * THIN by design. This class contains NO migration logic of its own: every subcommand
 * is a wrapper that delegates to the gated lifecycle services (Orchestrator, Verifier,
 * Rollback, Finalizer). All the adversarial protections — the hard ordering gates, the
 * single advisory lock, the legacy→target completeness oracle, the verified+fresh-drift
 * Finalize gate, the non-destructive Rollback — live in those services and apply
 * IDENTICALLY whether the migration is driven from this CLI, from an admin action, or
 * from a test. Reproducing any of that logic here would be a place for the two paths to
 * drift, so we never do.
 *
 * The two DESTRUCTIVE commands (rollback, finalize) carry the confirm-guard discipline:
 * they PRINT the exact id set / blast radius FIRST and refuse to act unless the operator
 * passed --yes (the services re-check their own gates regardless, so a stray --yes can
 * never finalize an unverified migration — confirmation is necessary, not sufficient).
 * They also acquire the SAME single Orchestrator advisory lock that `migrate` takes
 * per-chunk (design-notes item 20: "destructive commands ... same advisory lock"): a
 * destructive reversal/finalize and a concurrent `migrate` run in a second process must
 * never interleave, so if a live run already holds the lock the destructive command
 * refuses and touches nothing, leaving the lock intact for its owner.
 *
 * Output is routed through \WP_CLI:: (guarded by class_exists so the class also loads /
 * runs cleanly under a plain phpunit process where WP_CLI is undefined). The command is
 * registered onto WP_CLI only by Plugin::boot() when defined('WP_CLI') && WP_CLI.
 */
final class MigrationCommand {
    private Orchestrator $orchestrator;
    private MigrationState $state;
    private Verifier $verifier;
    private Rollback $rollback;
    private Finalizer $finalizer;

    /**
     * Dependencies are injectable for testing; in production every default is a real
     * service sharing one MigrationState so the phase/gates are consistent across the
     * subcommands of a single invocation.
     */
    public function __construct(
        ?Orchestrator $orchestrator = null,
        ?MigrationState $state = null,
        ?Verifier $verifier = null,
        ?Rollback $rollback = null,
        ?Finalizer $finalizer = null
    ) {
        $this->state        = $state ?? new MigrationState();
        $this->orchestrator = $orchestrator ?? new Orchestrator(
            null, null, null, null, null, null, $this->state
        );
        $this->verifier  = $verifier ?? new Verifier( $this->state );
        $this->rollback  = $rollback ?? new Rollback( $this->state );
        $this->finalizer = $finalizer ?? new Finalizer( $this->state, $this->verifier );
    }

    /**
     * Run the read-only Detector, store the manifest, and advance to 'detected'.
     *
     * ## OPTIONS
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function detect( array $args, array $assoc_args ): void {
        $manifest = $this->orchestrator->detect();

        $this->log( 'Detected legacy data:' );
        foreach ( $manifest->counts() as $key => $count ) {
            $this->log( sprintf( '  %-16s %d', $key, (int) $count ) );
        }
        $this->success( sprintf( 'Migration state: %s', $this->state->phase() ) );
    }

    /**
     * Run the migration to completion, one bounded chunk at a time.
     *
     * Honors --batch-size and LOOPS Orchestrator::run until the phase reaches
     * 'migrated' (or the run stops making progress), printing per-chunk progress. The
     * Orchestrator's advisory lock + hard gates apply to every chunk.
     *
     * ## OPTIONS
     *
     * [--batch-size=<n>]
     * : Records migrated per chunk (default 50).
     *
     * @when after_wp_load
     *
     * @param array<int,string>         $args
     * @param array<string,string|int>  $assoc_args
     */
    public function migrate( array $args, array $assoc_args ): void {
        $batchSize = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 50;
        if ( $batchSize < 1 ) {
            $batchSize = 1;
        }

        // Loop run() to completion. The Orchestrator returns a terminal phase
        // ('migrated' once every phase reports complete) and advances each chunk under
        // its own lock + gates. We stop on a terminal phase, on a hard non-advancing
        // FLAG (a held lock or an undetected state — the only genuinely stuck signals,
        // because the done/remaining counts legitimately stay flat across the
        // non-record phases like terms/artwork/options), or at a bounded guard so a
        // bug can never spin forever.
        $guard    = 0;
        $maxLoops = 100000;

        do {
            $progress = $this->orchestrator->run( $batchSize );
            $phase    = $progress['phase'];

            $this->log( sprintf(
                'phase=%s done=%d remaining=%d%s',
                $phase,
                $progress['done'],
                $progress['remaining'],
                $progress['flags'] !== array() ? ' flags=' . implode( ',', $progress['flags'] ) : ''
            ) );

            if ( in_array( $phase, array( 'migrated', 'verified', 'finalized' ), true ) ) {
                break;
            }

            // The only non-advancing terminal signals: the lock is held by another run,
            // or detect() has not run. Stop rather than spin.
            $stuck = array_intersect( array( 'locked', 'not_detected' ), $progress['flags'] );
            if ( $stuck !== array() ) {
                $this->warning( sprintf(
                    'Migration is not advancing (phase=%s); stopping. Flags: %s',
                    $phase,
                    implode( ',', $progress['flags'] )
                ) );
                break;
            }
            $guard++;
        } while ( $guard < $maxLoops );

        if ( $guard >= $maxLoops ) {
            $this->warning( 'Migration loop hit its iteration cap before completing.' );
        }

        $this->success( sprintf( 'Migration phase: %s', $this->state->phase() ) );
    }

    /**
     * Prove legacy→target completeness + source fixity; advance to 'verified' when
     * clean. Read-only — delegates wholly to the Verifier.
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function verify( array $args, array $assoc_args ): void {
        $manifest = $this->state->manifest();
        if ( $manifest === null ) {
            $this->warning( 'Cannot verify: no detect-time manifest (run `detect` first).' );
            return;
        }

        $report = $this->verifier->verify( $manifest );

        $this->log( 'Verification counts:' );
        foreach ( $report->counts as $key => $count ) {
            $this->log( sprintf( '  %-16s %d', $key, (int) $count ) );
        }

        if ( $report->complete ) {
            $this->success( sprintf( 'Verification PASSED — state: %s', $this->state->phase() ) );
            return;
        }

        $this->warning( sprintf(
            'Verification INCOMPLETE: drift=%d missing=%d openFlags=%d',
            count( $report->drift ),
            count( $report->missing ),
            count( $report->openFlags )
        ) );
        foreach ( $report->openFlags as $flag ) {
            $this->log( '  open flag: ' . $flag );
        }
    }

    /**
     * Print the current phase + open flags. Read-only.
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function status( array $args, array $assoc_args ): void {
        $status = $this->orchestrator->status();

        $this->log( sprintf( 'Phase: %s', $status['phase'] ) );
        $this->log( sprintf( 'Migrated records: %d done, %d remaining', $status['done'], $status['remaining'] ) );

        if ( $status['counts'] !== array() ) {
            $this->log( 'Detected counts:' );
            foreach ( $status['counts'] as $key => $count ) {
                $this->log( sprintf( '  %-16s %d', $key, (int) $count ) );
            }
        }

        if ( $status['flags'] !== array() ) {
            $this->log( 'Open flags:' );
            foreach ( $status['flags'] as $flag ) {
                $this->log( '  ' . $flag );
            }
        } else {
            $this->log( 'Open flags: (none)' );
        }
    }

    /**
     * DESTRUCTIVE (reversible-of-the-migration). Reverse the migration: delete only
     * migration-made records, restore backed-up options, leave legacy byte-equal.
     *
     * Prints the EXACT pending-deletion id set FIRST, then refuses to act unless --yes
     * is passed. The Rollback service re-checks its own gates (it refuses outright when
     * phase()==='finalized') regardless of --yes. Acquires the Orchestrator advisory
     * lock before acting and aborts (deleting nothing) if a live migration run holds
     * it, so a concurrent `migrate` can never race this reversal.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Confirm the rollback (required — without it the command prints the blast radius
     *   and aborts without deleting anything).
     *
     * @when after_wp_load
     *
     * @param array<int,string>         $args
     * @param array<string,string|bool> $assoc_args
     */
    public function rollback( array $args, array $assoc_args ): void {
        $pending = $this->rollback->pendingDeletions();

        $this->log( 'Rollback would delete:' );
        $this->log( '  posts:    ' . $this->idList( $pending['posts'] ) );
        $this->log( '  terms:    ' . $this->idList( $pending['terms'] ) );
        $this->log( '  comments: ' . $this->idList( $pending['comments'] ) );
        $this->log( '  options:  ' . ( $pending['options'] !== array() ? implode( ', ', $pending['options'] ) : '(none)' ) );

        if ( ! $this->confirmed( $assoc_args ) ) {
            $this->warning( 'Rollback aborted: pass --yes to confirm. Nothing was deleted.' );
            return;
        }

        // Acquire the SAME single advisory lock the Orchestrator uses per-chunk, so a
        // concurrent `migrate` run in a second process can never interleave with this
        // destructive reversal (force-deleting migration posts + stripping native
        // term_relationships). If a live run holds the lock we refuse and touch
        // NOTHING — the lock is left intact for its true owner. Release only on the
        // path where WE acquired it (in finally), never another run's lock.
        if ( ! $this->orchestrator->acquireLock() ) {
            $this->warning( 'Rollback aborted: a migration run is in progress (advisory lock held). Nothing was deleted. Retry once it completes.' );
            return;
        }

        try {
            $result = $this->rollback->run();

            $this->log( sprintf(
                'Deleted: %d posts, %d terms, %d comments, %d options.',
                count( $result['deleted']['posts'] ),
                count( $result['deleted']['terms'] ),
                count( $result['deleted']['comments'] ),
                count( $result['deleted']['options'] )
            ) );
            foreach ( $result['restored'] as $opt ) {
                $this->log( '  restored option: ' . $opt );
            }
            foreach ( $result['warnings'] as $warning ) {
                $this->warning( $warning );
            }
            $this->success( sprintf( 'Rollback complete — state: %s', $this->state->phase() ) );
        } finally {
            $this->orchestrator->releaseLock();
        }
    }

    /**
     * DESTRUCTIVE + IRREVERSIBLE — the point of no return. Delete legacy data per
     * verified counterpart only.
     *
     * Refuses to act unless --yes is passed AND the Finalizer's own gates hold
     * (phase()==='verified' + a fresh drift rescan clean). Without --yes it prints the
     * blast radius and aborts. With --yes it passes confirmed=true to the Finalizer,
     * which still enforces every gate — so a stray --yes on an unverified migration is
     * refused by the service, not finalized. Acquires the Orchestrator advisory lock
     * before acting and aborts (deleting nothing) if a live migration run holds it, so
     * a concurrent `migrate` can never race this irreversible delete.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Confirm the IRREVERSIBLE finalize (required — without it nothing is deleted).
     *
     * @when after_wp_load
     *
     * @param array<int,string>         $args
     * @param array<string,string|bool> $assoc_args
     */
    public function finalize( array $args, array $assoc_args ): void {
        $this->log( 'Finalize is IRREVERSIBLE — it deletes legacy data per verified counterpart.' );
        $this->log( sprintf( 'Current state: %s', $this->state->phase() ) );

        if ( ! $this->confirmed( $assoc_args ) ) {
            $this->warning( 'Finalize aborted: pass --yes to confirm. Nothing was deleted.' );
            return;
        }

        // Acquire the SAME single advisory lock the Orchestrator uses per-chunk, so a
        // concurrent `migrate` run in a second process can never interleave with this
        // — the ONLY destructive step (wp_delete_post on verified legacy counterparts).
        // If a live run holds the lock we refuse and touch NOTHING; the lock is left
        // intact for its owner. Release only on the path where WE acquired it.
        if ( ! $this->orchestrator->acquireLock() ) {
            $this->warning( 'Finalize aborted: a migration run is in progress (advisory lock held). Nothing was deleted. Retry once it completes.' );
            return;
        }

        try {
            $result = $this->finalizer->run( true );

            if ( $result['refused'] !== null ) {
                // A gated refusal is NOT a fatal CLI error — report it and leave state intact.
                $this->warning( $result['refused'] );
                return;
            }

            $this->log( sprintf(
                'Deleted %d legacy posts, %d legacy options; stripped %d back-ref rows.',
                count( $result['deleted']['posts'] ),
                count( $result['deleted']['options'] ),
                (int) $result['stripped']
            ) );
            $this->success( sprintf( 'Finalize complete — state: %s (point of no return).', $this->state->phase() ) );
        } finally {
            $this->orchestrator->releaseLock();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Whether the operator confirmed a destructive command. True when --yes is present
     * (WP-CLI passes it as a truthy assoc arg). Interactive confirmation, when WP_CLI is
     * available, is also honored via WP_CLI::confirm — but a missing --yes under a
     * non-interactive run (or under phpunit) deterministically aborts.
     *
     * @param array<string,string|bool|int> $assoc_args
     */
    private function confirmed( array $assoc_args ): bool {
        if ( array_key_exists( 'yes', $assoc_args ) && $assoc_args['yes'] ) {
            return true;
        }
        return false;
    }

    /**
     * @param list<int> $ids
     */
    private function idList( array $ids ): string {
        return $ids !== array() ? implode( ', ', array_map( 'strval', $ids ) ) : '(none)';
    }

    private function log( string $message ): void {
        if ( class_exists( '\\WP_CLI' ) ) {
            \WP_CLI::log( $message );
        }
    }

    private function success( string $message ): void {
        if ( class_exists( '\\WP_CLI' ) ) {
            \WP_CLI::success( $message );
        }
    }

    private function warning( string $message ): void {
        if ( class_exists( '\\WP_CLI' ) ) {
            \WP_CLI::warning( $message );
        }
    }
}
