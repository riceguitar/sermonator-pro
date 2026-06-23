<?php

declare(strict_types=1);

namespace Sermonator\Cli;

use Sermonator\Frontend\Feed\AudioSizeBackfill;
use Sermonator\Migration\MigrationState;

/**
 * WP-CLI: maintain sermon audio metadata for the podcast feed. Thin wrapper over
 * {@see AudioSizeBackfill} (which holds the data-preservation guardrails).
 */
final class AudioCommand {
    /**
     * Backfill persisted audio byte sizes so the podcast feed can emit correct enclosure
     * lengths without a network call at render time.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would change without writing anything.
     *
     * [--rollback]
     * : Delete the sizes this command previously wrote (exact reverse) and clear the log.
     *
     * [--limit=<n>]
     * : Process at most N sermons this run (re-run to drain the rest; idempotent).
     *
     * ## EXAMPLES
     *
     *     wp sermonator audio backfill --dry-run
     *     wp sermonator audio backfill --limit=100
     *     wp sermonator audio backfill --rollback
     *
     * @param array<int,string>     $args
     * @param array<string,string>  $assoc_args
     */
    public function backfill( array $args, array $assoc_args ): void {
        $service = new AudioSizeBackfill();

        if ( ! empty( $assoc_args['rollback'] ) ) {
            $result = $service->rollback();
            \WP_CLI::success( sprintf( 'Rolled back %d enclosure size(s).', $result['removed'] ) );
            return;
        }

        // Do not write the native size meta while a migration owns that key (it maps legacy
        // _wpfc_sermon_size → _sermonator_audio_size and re-writes it on resume). Allow only
        // when there is no migration in flight or it is fully finalized.
        $phase = ( new MigrationState() )->phase();
        if ( ! in_array( $phase, array( 'none', 'finalized' ), true ) ) {
            \WP_CLI::error( sprintf(
                'A migration is in progress (phase: %s). Finalize or roll back the migration before backfilling audio sizes.',
                $phase
            ) );
            return;
        }

        $dryRun = ! empty( $assoc_args['dry-run'] );
        $limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

        $result = $service->run( $limit, $dryRun );

        \WP_CLI::log( sprintf(
            '%d candidate(s); %d resolved; %d unresolved (no size available).',
            $result['candidates'],
            $result['written'],
            $result['failed']
        ) );
        \WP_CLI::success(
            $dryRun
                ? 'Dry run complete — no writes made.'
                : sprintf( 'Backfilled %d audio size(s).', $result['written'] )
        );
    }
}
