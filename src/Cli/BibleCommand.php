<?php

declare(strict_types=1);

namespace Sermonator\Cli;

use Sermonator\Migration\BibleRefsBackfill;
use Sermonator\Schema\Identifiers as ID;

/**
 * WP-CLI: maintain the structured bible-reference layer. Thin wrapper over the gated
 * services (which hold the data-preservation guardrails) — this class carries NO logic
 * of its own, exactly like {@see AudioCommand} and {@see MigrationCommand}.
 *
 *   wp sermonator bible backfill [--write] [--rollback] [--limit=<n>]
 *   wp sermonator bible flush
 *
 * Phase 3a is LINK-MODE only. The Phase 3b inline-text subcommands
 * (`bible vendor` / `bible warm`) are intentionally NOT registered here yet — see the
 * TODO below.
 */
final class BibleCommand {
    /**
     * Backfill the structured {@see ID::META_BIBLE_REFS} envelope (and the
     * {@see ID::TAX_BOOK} dual-write) for legacy sermons from the preserved free-text
     * passage label. Delegates to {@see BibleRefsBackfill}, which owns every guardrail:
     * native-only, fill-missing, never-overwrite-authoring, exactly reversible, idempotent
     * and migration-gated. The legacy passage label is NEVER mutated (#1 data preservation).
     *
     * Default-safe: with no flags this is a DRY RUN — it parses and counts, writing
     * nothing. A real write requires the explicit `--write` flag.
     *
     * ## OPTIONS
     *
     * [--write]
     * : Persist the structured refs (otherwise dry-run: report counts only).
     *
     * [--rollback]
     * : Delete exactly the refs/sentinels this command previously wrote and detach the
     *   terms it added (exact reverse), then clear the log.
     *
     * [--limit=<n>]
     * : Process at most N sermons this run (re-run to drain the rest; idempotent).
     *
     * ## EXAMPLES
     *
     *     wp sermonator bible backfill
     *     wp sermonator bible backfill --write --limit=100
     *     wp sermonator bible backfill --rollback
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function backfill( array $args, array $assoc_args ): void {
        $service = new BibleRefsBackfill();

        if ( ! empty( $assoc_args['rollback'] ) ) {
            $result = $service->reverse();
            if ( $result['gated'] ) {
                \WP_CLI::warning( 'A migration is in progress — rollback is gated. Finalize or roll back the migration first.' );
                return;
            }
            \WP_CLI::success( sprintf( 'Reversed %d backfilled sermon(s).', $result['reversed'] ) );
            return;
        }

        // Mirror BibleRefsBackfill::run(): dry-run is the DEFAULT; a real write is opt-in
        // via --write. So the service runs in dry-run mode unless --write is passed.
        $dryRun = empty( $assoc_args['write'] );
        $limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

        $result = $service->run( $limit, $dryRun );

        if ( $result['gated'] ) {
            \WP_CLI::warning( 'A migration is in progress — writes are gated. Finalize or roll back the migration first (dry-run remains available).' );
            return;
        }

        \WP_CLI::log( sprintf(
            '%d candidate(s); %d with structured refs; %d unparseable (non-empty passage, zero valid refs).',
            $result['candidates'],
            $result['written'],
            $result['unparseable']
        ) );
        \WP_CLI::success(
            $dryRun
                ? 'Dry run complete — no writes made (pass --write to persist).'
                : sprintf( 'Backfilled %d sermon(s).', $result['written'] )
        );
    }

    /**
     * Invalidate the warmed/normalized chapter cache by bumping the integer cache-buster
     * {@see ID::OPTION_BIBLE_CACHE_GEN} (mirrors the bump the settings save performs when
     * a bible axis changes). Cheap and always safe: nothing is deleted — readers simply
     * compute a new cache key on the next access.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function flush( array $args, array $assoc_args ): void {
        $current = (int) get_option( ID::OPTION_BIBLE_CACHE_GEN, 0 );
        $next    = $current + 1;
        update_option( ID::OPTION_BIBLE_CACHE_GEN, $next );

        \WP_CLI::success( sprintf( 'Flushed bible chapter cache (generation %d -> %d).', $current, $next ) );
    }

    // TODO(Phase 3b): `vendor` (vendor ENGWEBP per-chapter PD text) and `warm` (prime the
    // chapter cache from disk/live) subcommands land with the inline-text layer — see spec
    // Tasks 7 + 11. Phase 3a is LINK-MODE only: NO vendoring, NO live fetch, NO inline text.
}
