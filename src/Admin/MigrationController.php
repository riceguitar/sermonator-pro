<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\Verifier;
use Sermonator\Migration\Rollback;
use Sermonator\Migration\Finalizer;
use Sermonator\Migration\MigrationState;

/**
 * The admin-AJAX surface for the guided migration wizard (Plan C).
 *
 * THIN by design — the exact same discipline as the WP-CLI MigrationCommand. This class
 * contains NO migration logic: every action is a wrapper that (1) enforces the capability
 * gate, (2) enforces the nonce gate, then (3) delegates to the gated lifecycle services
 * (Orchestrator, Verifier, Rollback, Finalizer). All the data-safety protections — the
 * hard ordering gates, the single advisory lock, the legacy→target completeness oracle,
 * the verified+fresh-drift finalize gate, the non-destructive rollback, the write-once
 * manifest — live in those services and apply IDENTICALLY whether the migration is driven
 * from this wizard, the CLI, or a test. The UI therefore cannot lose data: the worst a UI
 * bug can do is mis-display state or fail to advance.
 *
 * The two DESTRUCTIVE actions (rollback, finalize) carry the same confirm-guard discipline
 * as the CLI: they require an explicit truthy `confirm` and acquire the SAME single
 * Orchestrator advisory lock, refusing (touching nothing) if a live migrate run holds it.
 * `finalize` passes confirmed=true to the Finalizer, which still re-checks phase==='verified'
 * + a fresh drift rescan — so a stray confirm can never finalize an unverified migration.
 *
 * The testable core is run_action(): it returns a structured array and performs every gate,
 * so the wp_ajax adapter (dispatch) is a trivial JSON shim that needs no separate coverage.
 */
final class MigrationController {
    /** Nonce action shared by the wizard page and every AJAX request. */
    public const NONCE_ACTION = 'sermonator_migration';

    /** The capability required to drive the migration (admin-only in the cap scheme). */
    public const CAPABILITY = 'manage_sermonator_settings';

    /** wp_ajax action slugs (prefixed) this controller answers. */
    private const ACTIONS = array( 'detect', 'run', 'verify', 'rollback', 'finalize', 'status' );

    private Orchestrator $orchestrator;
    private MigrationState $state;
    private Verifier $verifier;
    private Rollback $rollback;
    private Finalizer $finalizer;

    public function __construct(
        ?Orchestrator $orchestrator = null,
        ?MigrationState $state = null,
        ?Verifier $verifier = null,
        ?Rollback $rollback = null,
        ?Finalizer $finalizer = null
    ) {
        $this->state        = $state ?? new MigrationState();
        $this->orchestrator = $orchestrator ?? new Orchestrator( null, null, null, null, null, null, $this->state );
        $this->verifier     = $verifier ?? new Verifier( $this->state );
        $this->rollback     = $rollback ?? new Rollback( $this->state );
        $this->finalizer    = $finalizer ?? new Finalizer( $this->state, $this->verifier );
    }

    /**
     * Register the wp_ajax_* handlers (admin context only). Each maps to dispatch(), the
     * thin JSON shim over run_action().
     */
    public function hook(): void {
        foreach ( self::ACTIONS as $action ) {
            add_action(
                'wp_ajax_sermonator_migration_' . $action,
                function () use ( $action ): void {
                    $this->dispatch( $action );
                }
            );
        }
    }

    /**
     * wp_ajax adapter: read the request, run the gated action, emit JSON. Kept trivial so
     * the data logic lives in run_action() (which is what the tests drive directly).
     */
    private function dispatch( string $action ): void {
        // wp_unslash so the nonce compares cleanly; values are otherwise read narrowly.
        $request = is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- run_action verifies the nonce.
        $result  = $this->run_action( $action, (array) $request );

        if ( $result['ok'] ) {
            wp_send_json_success( $result['data'] );
        }
        wp_send_json_error( array( 'message' => $result['error'] ), (int) ( $result['code'] ?? 403 ) );
    }

    /**
     * The testable, gated core. Enforces capability + nonce, then delegates to the
     * service for $action. Returns a uniform shape:
     *   ['ok'=>bool, 'data'=>array, 'error'=>?string, 'code'=>?int]
     * On any failure NOTHING is mutated.
     *
     * @param array<string,mixed> $request
     * @return array{ok:bool, data?:array<string,mixed>, error?:string, code?:int}
     */
    public function run_action( string $action, array $request ): array {
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            return $this->err( 'Unknown migration action.', 400 );
        }

        // GATE A: capability. A user without the migration cap can do nothing.
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return $this->err( 'You do not have permission to run the migration.', 403 );
        }

        // GATE B: nonce. A missing/invalid nonce refuses with no state change.
        $nonce = isset( $request['nonce'] ) ? (string) $request['nonce'] : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return $this->err( 'Your session expired. Reload the page and try again.', 403 );
        }

        switch ( $action ) {
            case 'detect':
                return $this->doDetect();
            case 'run':
                return $this->doRun( $request );
            case 'verify':
                return $this->doVerify();
            case 'rollback':
                return $this->doRollback( $request );
            case 'finalize':
                return $this->doFinalize( $request );
            case 'status':
            default:
                return $this->ok( array( 'status' => $this->status() ) );
        }
    }

    // -------------------------------------------------------------------------
    // Per-action delegation (thin)
    // -------------------------------------------------------------------------

    private function doDetect(): array {
        $manifest = $this->orchestrator->detect();
        return $this->ok( array(
            'status' => $this->status(),
            'counts' => $manifest->counts(),
        ) );
    }

    /**
     * Advance ONE bounded chunk. The wizard JS loops this until phase==='migrated'.
     *
     * @param array<string,mixed> $request
     */
    private function doRun( array $request ): array {
        $batchSize = isset( $request['batch_size'] ) ? (int) $request['batch_size'] : 50;
        if ( $batchSize < 1 ) {
            $batchSize = 50;
        }
        $progress = $this->orchestrator->run( $batchSize );
        return $this->ok( array(
            'status'   => $this->status(),
            'progress' => $progress,
        ) );
    }

    private function doVerify(): array {
        $manifest = $this->state->manifest();
        if ( $manifest === null ) {
            return $this->err( 'Cannot verify: run Detect first.', 409 );
        }
        $report = $this->verifier->verify( $manifest );
        return $this->ok( array(
            'status' => $this->status(),
            'report' => array(
                'complete'  => $report->complete,
                'counts'    => $report->counts,
                'drift'     => count( $report->drift ),
                'missing'   => count( $report->missing ),
                'openFlags' => $report->openFlags,
            ),
        ) );
    }

    /**
     * DESTRUCTIVE (reversible-of-the-migration). Requires confirm. Acquires the same
     * advisory lock as the Orchestrator so a concurrent migrate cannot interleave.
     *
     * @param array<string,mixed> $request
     */
    private function doRollback( array $request ): array {
        if ( ! $this->confirmed( $request ) ) {
            return $this->err( 'Confirm the rollback to proceed (nothing was deleted).', 400 );
        }
        if ( ! $this->orchestrator->acquireLock() ) {
            return $this->err( 'A migration run is in progress (advisory lock held). Nothing was deleted; retry once it completes.', 423 );
        }
        try {
            $result = $this->rollback->run();
        } finally {
            $this->orchestrator->releaseLock();
        }
        return $this->ok( array(
            'status'   => $this->status(),
            'deleted'  => array(
                'posts'    => count( $result['deleted']['posts'] ),
                'terms'    => count( $result['deleted']['terms'] ),
                'comments' => count( $result['deleted']['comments'] ),
                'options'  => count( $result['deleted']['options'] ),
            ),
            'restored' => $result['restored'],
            'warnings' => $result['warnings'],
        ) );
    }

    /**
     * DESTRUCTIVE + IRREVERSIBLE — the point of no return. Requires confirm AND the
     * Finalizer's own gates (phase==='verified' + a fresh drift rescan). Acquires the
     * advisory lock. A stray confirm can never finalize an unverified migration: the
     * Finalizer re-checks every gate and returns a `refused` reason instead.
     *
     * @param array<string,mixed> $request
     */
    private function doFinalize( array $request ): array {
        if ( ! $this->confirmed( $request ) ) {
            return $this->err( 'Confirm the IRREVERSIBLE finalize to proceed (nothing was deleted).', 400 );
        }
        if ( ! $this->orchestrator->acquireLock() ) {
            return $this->err( 'A migration run is in progress (advisory lock held). Nothing was deleted; retry once it completes.', 423 );
        }
        try {
            $result = $this->finalizer->run( true );
        } finally {
            $this->orchestrator->releaseLock();
        }
        if ( $result['refused'] !== null ) {
            // A gated refusal is not an error — report it so the UI can surface it.
            return $this->ok( array(
                'status'  => $this->status(),
                'refused' => $result['refused'],
            ) );
        }
        return $this->ok( array(
            'status'  => $this->status(),
            'refused' => null,
            'deleted' => array(
                'posts'   => count( $result['deleted']['posts'] ),
                'options' => count( $result['deleted']['options'] ),
            ),
            'stripped' => (int) $result['stripped'],
        ) );
    }

    /**
     * The blast radius a finalize would delete (per verified counterpart) — for the
     * wizard's confirm screen. Read-only.
     *
     * @return array{posts:list<int>, options:list<string>}
     */
    public function finalizePreview(): array {
        return $this->finalizer->pendingDeletions();
    }

    /**
     * The pending rollback deletions — for the wizard's confirm screen. Read-only.
     *
     * @return array{posts:list<int>, terms:list<int>, comments:list<int>, options:list<string>}
     */
    public function rollbackPreview(): array {
        return $this->rollback->pendingDeletions();
    }

    /**
     * A status snapshot for the UI: phase + manifest counts + done/remaining + open flags.
     *
     * @return array<string,mixed>
     */
    public function status(): array {
        return $this->orchestrator->status();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $request */
    private function confirmed( array $request ): bool {
        if ( ! array_key_exists( 'confirm', $request ) ) {
            return false;
        }
        $value = $request['confirm'];
        // Accept the common truthy encodings a browser/fetch sends.
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes';
    }

    /**
     * @param array<string,mixed> $data
     * @return array{ok:true, data:array<string,mixed>}
     */
    private function ok( array $data ): array {
        return array( 'ok' => true, 'data' => $data );
    }

    /**
     * @return array{ok:false, error:string, code:int}
     */
    private function err( string $message, int $code ): array {
        return array( 'ok' => false, 'error' => $message, 'code' => $code );
    }
}
