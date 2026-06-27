<?php

declare(strict_types=1);

namespace Sermonator\Cli;

use Sermonator\Migration\BibleChapterVendor;
use Sermonator\Migration\BibleRefsBackfill;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers as ID;

/**
 * WP-CLI: maintain the structured bible-reference layer. Thin wrapper over the gated
 * services (which hold the data-preservation guardrails) — this class carries NO logic
 * of its own, exactly like {@see AudioCommand} and {@see MigrationCommand}.
 *
 *   wp sermonator bible backfill [--write] [--rollback] [--limit=<n>]
 *   wp sermonator bible flush
 *   wp sermonator bible vendor [--translation=<id>] [--write] [--force] [--limit=<n>] [--rollback]
 *
 * Phase 3a was LINK-MODE only. Phase 3b Task 8 adds the `vendor` subcommand (vendor the
 * public-domain ENGWEBP per-chapter text to the uploads snapshot, reversibly). The `warm`
 * subcommand (prime the transient chapter cache) lands with Task 9 — see the TODO below.
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

    /**
     * Vendor the public-domain ENGWEBP per-chapter text to the uploads snapshot
     * (`wp-content/uploads/sermonator-bible/<TRANSLATION>/<BOOK>/<chapter>.json`), the
     * off-render-path source the front end reads with ZERO network I/O. Delegates to
     * {@see BibleChapterVendor}, which owns every guardrail: ENGWEBP-only (refuses a
     * non-PD / inline-ineligible translation), fill-missing + idempotent, migration-gated,
     * structurally reversible (rollback deletes the snapshot dir), and never-fail-wrong
     * (a failed chapter is simply left un-vendored → it stays a 3a link).
     *
     * Default-safe: with no flags this is a DRY RUN — it counts what WOULD be fetched,
     * touching neither the network nor the disk. A real fetch+write requires `--write`.
     * After any run it also prints the OFFLINE count-diff audit (candidate divergent-zone
     * additions for human review — never auto-committed).
     *
     * ## OPTIONS
     *
     * [--translation=<id>]
     * : Inline target translation id (default ENGWEBP). Must be public-domain AND an
     *   audited inline target, else the command refuses.
     *
     * [--write]
     * : Persist the snapshot (otherwise dry-run: report counts only).
     *
     * [--force]
     * : Re-vendor chapters already present on disk (otherwise fill-missing only).
     *
     * [--limit=<n>]
     * : Process at most N missing chapters this run (re-run to drain the rest; idempotent).
     *
     * [--rollback]
     * : Delete the entire snapshot directory for the translation (exact reverse of vendoring).
     *
     * ## EXAMPLES
     *
     *     wp sermonator bible vendor
     *     wp sermonator bible vendor --write
     *     wp sermonator bible vendor --write --limit=200
     *     wp sermonator bible vendor --rollback
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function vendor( array $args, array $assoc_args ): void {
        $translation = isset( $assoc_args['translation'] ) && '' !== (string) $assoc_args['translation']
            ? (string) $assoc_args['translation']
            : BibleTranslations::DEFAULT_INLINE;

        $service = new BibleChapterVendor();

        if ( ! empty( $assoc_args['rollback'] ) ) {
            $result = $service->rollback( $translation );
            if ( $result['gated'] ) {
                \WP_CLI::warning( 'A migration is in progress — rollback is gated. Finalize or roll back the migration first.' );
                return;
            }
            if ( null !== $result['error'] ) {
                \WP_CLI::warning( $result['error'] );
                return;
            }
            \WP_CLI::success( sprintf( 'Removed %d vendored chapter file(s) for %s.', $result['removed'], $translation ) );
            return;
        }

        // Mirror BibleRefsBackfill: dry-run is the DEFAULT; a real write is opt-in via --write.
        $dryRun = empty( $assoc_args['write'] );
        $force  = ! empty( $assoc_args['force'] );
        $limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

        $result = $service->vendor( $translation, $dryRun, $force, $limit );

        if ( null !== $result['refused'] ) {
            \WP_CLI::warning( $result['refused'] );
            return;
        }
        if ( $result['gated'] ) {
            \WP_CLI::warning( 'A migration is in progress — vendoring is gated. Finalize or roll back the migration first (dry-run remains available).' );
            return;
        }
        if ( null !== $result['error'] ) {
            \WP_CLI::warning( $result['error'] );
            return;
        }

        $status = $result['status'];
        \WP_CLI::log( sprintf(
            'Snapshot %s: %d/%d chapters present%s. This run: %d processed, %d written, %d failed, %d already-present.',
            $translation,
            $status['present'],
            $status['total'],
            $status['complete'] ? ' (COMPLETE)' : '',
            $result['processed'],
            $result['written'],
            $result['failed'],
            $result['skipped']
        ) );

        $this->reportCountDiff( $service->auditCountDiff( $translation ) );

        \WP_CLI::success(
            $dryRun
                ? sprintf( 'Dry run complete — %d chapter(s) would be vendored (pass --write to persist).', $result['processed'] )
                : sprintf( 'Vendored %d chapter(s) for %s.', $result['written'], $translation )
        );
    }

    /**
     * Print the OFFLINE count-diff audit: candidate divergent-zone additions the operator
     * should triage. NEVER auto-committed — these are proposals only (design §3.4).
     *
     * @param array{comparisons:int,proposed:list<array{book:string,chapter:int,webCount:int,referenceCount:int}>,alreadyModeled:list<array{book:string,chapter:int,webCount:int,referenceCount:int}>} $audit
     */
    private function reportCountDiff( array $audit ): void {
        if ( 0 === $audit['comparisons'] ) {
            \WP_CLI::log( 'Count-diff audit: no vendored chapters to compare yet.' );
            return;
        }

        if ( array() === $audit['proposed'] ) {
            \WP_CLI::log( sprintf(
                'Count-diff audit: %d chapter(s) compared; no unmodeled verse-count divergences.',
                $audit['comparisons']
            ) );
            return;
        }

        \WP_CLI::warning( sprintf(
            'Count-diff audit: %d UNMODELED verse-count divergence(s) found — candidate VersificationGate divergent-zone additions (review manually; NEVER auto-committed):',
            count( $audit['proposed'] )
        ) );
        foreach ( $audit['proposed'] as $entry ) {
            \WP_CLI::log( sprintf(
                '  - %s %d: WEB present=%d, reference=%d',
                $entry['book'],
                $entry['chapter'],
                $entry['webCount'],
                $entry['referenceCount']
            ) );
        }
    }

    // TODO(Phase 3b Task 9): `warm` (prime the transient chapter cache on save + a chunked
    // CLI backfill) lands with BibleWarmer — migration-gated, fill-missing, reversible
    // (reverse == `flush` gen bump / TTL expiry). Vendoring (above) is its disk-snapshot peer.
}
