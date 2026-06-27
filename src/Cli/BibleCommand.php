<?php

declare(strict_types=1);

namespace Sermonator\Cli;

use Sermonator\Bible\CoverageAudit;
use Sermonator\Frontend\Bible\BibleWarmer;
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
 *   wp sermonator bible warm [--limit=<n>] [--rollback]
 *   wp sermonator bible audit [--inline]
 *
 * Phase 3a was LINK-MODE only. Phase 3b Task 8 adds the `vendor` subcommand (vendor the
 * public-domain ENGWEBP per-chapter text to the uploads snapshot, reversibly). Phase 3b
 * Task 9 adds the `warm` subcommand (prime the disposable transient chapter cache for the
 * chapters real sermons cite) — its disk-snapshot peer is `vendor`.
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

    /**
     * Warm the disposable transient chapter cache for the chapters real sermons cite, so
     * the front end can serve inline verse text with ZERO network I/O once inline rendering
     * is enabled. Delegates to {@see BibleWarmer}, which owns every guardrail: CACHE-ONLY
     * (never post meta, never the preserved passage/envelope), fill-missing + idempotent
     * (a chapter already on disk or in cache is skipped), migration-gated, and structurally
     * reversible (the reverse just bumps the cache generation — see `--rollback` / `flush`).
     *
     * The save-time peer (warm-on-save) runs automatically and synchronously after an
     * authoring save; this subcommand is the bulk sweep over the EXISTING corpus. It is
     * chunkable: a chapter warmed by an earlier run resolves from cache and is skipped, so
     * `--limit=N` drains the missing tail across repeated runs (the cache is the progress
     * marker — no touched-id log of derived text is kept).
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Warm at most N MISSING chapters this run (re-run to drain the rest; idempotent).
     *
     * [--rollback]
     * : Bump the cache generation so every warmed entry becomes unreachable and expires by
     *   TTL (the exact, bookkeeping-free reverse of warming; same effect as `flush`).
     *
     * ## EXAMPLES
     *
     *     wp sermonator bible warm
     *     wp sermonator bible warm --limit=200
     *     wp sermonator bible warm --rollback
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function warm( array $args, array $assoc_args ): void {
        $service = new BibleWarmer();

        if ( ! empty( $assoc_args['rollback'] ) ) {
            $result = $service->rollback();
            if ( $result['gated'] ) {
                \WP_CLI::warning( 'A migration is in progress — rollback is gated. Finalize or roll back the migration first.' );
                return;
            }
            \WP_CLI::success( sprintf(
                'Flushed warmed chapter cache (generation %d -> %d); warmed entries are now unreachable and expire by TTL.',
                $result['from'],
                $result['to']
            ) );
            return;
        }

        $limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
        $result = $service->warm( $limit );

        if ( $result['gated'] ) {
            \WP_CLI::warning( 'A migration is in progress — warming is gated. Finalize or roll back the migration first.' );
            return;
        }

        \WP_CLI::log( sprintf(
            '%d sermon(s) with refs; %d cited chapter(s); this run: %d warmed, %d already-present, %d failed.',
            $result['sermons'],
            $result['cited'],
            $result['warmed'],
            $result['skipped'],
            $result['failed']
        ) );
        \WP_CLI::success( sprintf( 'Warmed %d chapter(s) for %s.', $result['warmed'], $result['translation'] ) );
    }

    /**
     * READ-ONLY corpus-gate report. Delegates to {@see CoverageAudit}, which owns the
     * never-fail-WRONG L1–L9 classification and writes NOTHING on this path (the
     * standalone instrument the operator runs BEFORE deciding to enable inline at scale —
     * the §3.9 / Task 16 go/no-go).
     *
     * Without `--inline` it prints the persisted parse-coverage rollup (a pure read of
     * {@see ID::OPTION_BIBLE_STATS}; recomputed only by the cron / on-save path). With
     * `--inline` it computes the inline withheld-by-reason tally + inline-eligible% fresh
     * over the live corpus and prints it — still WITHOUT persisting anything.
     *
     * ## OPTIONS
     *
     * [--inline]
     * : Run the inline corpus-gate report (withheld-by-reason, inline-eligible%, and the
     *   unmodeled-pair WRONG-TEXT canary) instead of the parse-coverage summary.
     *
     * [--sample=<n>]
     * : With `--inline`, print the would-promote PREVIEW (the would-promote count +
     *   inline-eligible% under each of the three confidence floors, assume-attested) plus
     *   up to N PROMOTED references with their raw passage substrings — the axis-2 human
     *   spot-check the per-segment floor is gated behind (design §4 step 4).
     *
     * ## EXAMPLES
     *
     *     wp sermonator bible audit
     *     wp sermonator bible audit --inline
     *     wp sermonator bible audit --inline --sample=20
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function audit( array $args, array $assoc_args ): void {
        $audit = new CoverageAudit();

        if ( empty( $assoc_args['inline'] ) ) {
            $this->reportParseCoverage( CoverageAudit::stats() );
            return;
        }

        if ( array_key_exists( 'sample', $assoc_args ) ) {
            $this->reportPromotionPreview( $audit->promotionPreview( true, (int) $assoc_args['sample'] ) );
            return;
        }

        $this->reportInline( $audit->inlineReport() );
    }

    /**
     * Print the READ-ONLY would-promote PREVIEW: the would-promote count + inline-eligible%
     * under each of the three confidence floors (assume-attested ceiling), the corpus
     * canaries, and the axis-2 promoted-ref sample (raw passage substrings). Writes nothing.
     *
     * @param array{target:string,assume_attested:bool,refs_total:int,heterogeneous:bool,families:array<string,int>,dominant_family:string,unmodeled_pair_wrong_text:int,floors:array<string,array{floor:string,would_promote:int,inline_eligible:int,inline_eligible_pct:float,withheld:array<string,int>}>,sample:list<array<string,mixed>>} $preview
     */
    private function reportPromotionPreview( array $preview ): void {
        \WP_CLI::log( sprintf(
            'Would-promote preview (target %s, assume-attested %s, %d render-ready references):',
            $preview['target'],
            $preview['assume_attested'] ? 'yes' : 'no',
            $preview['refs_total']
        ) );

        foreach ( $preview['floors'] as $floor ) {
            \WP_CLI::log( sprintf(
                '  %-22s would-promote %d, inline-eligible %s%% (%d).',
                $floor['floor'] . ':',
                $floor['would_promote'],
                (string) $floor['inline_eligible_pct'],
                $floor['inline_eligible']
            ) );
        }

        if ( $preview['unmodeled_pair_wrong_text'] > 0 ) {
            \WP_CLI::warning( sprintf(
                'WRONG-TEXT canary: %d reference(s) hit an UNMODELED versification pair at the per-segment floor — model these before enabling inline at scale.',
                $preview['unmodeled_pair_wrong_text']
            ) );
        }

        if ( $preview['heterogeneous'] ) {
            \WP_CLI::warning( sprintf(
                'Source-versification HETEROGENEITY: the corpus carries %d distinct family bucket(s) (dominant: %s). The single site-wide attestation is unsafe.',
                count( $preview['families'] ),
                '' === $preview['dominant_family'] ? '(none)' : $preview['dominant_family']
            ) );
        }

        if ( array() === $preview['sample'] ) {
            \WP_CLI::log( '  No promoted references to sample.' );
        } else {
            \WP_CLI::log( sprintf( '  Axis-2 spot-check sample (%d promoted reference(s)):', count( $preview['sample'] ) ) );
            foreach ( $preview['sample'] as $entry ) {
                \WP_CLI::log( sprintf(
                    '    - %s  [%s %s:%s]',
                    (string) ( $entry['raw'] ?? '' ),
                    (string) ( $entry['bookUSFM'] ?? '' ),
                    (string) ( $entry['chapterStart'] ?? '' ),
                    (string) ( $entry['verseStart'] ?? '' )
                ) );
            }
        }

        \WP_CLI::success( 'Would-promote preview complete (read-only; no writes).' );
    }

    /**
     * Print the persisted parse-coverage rollup (pure read; never recomputed here).
     *
     * @param array<string,mixed> $stats
     */
    private function reportParseCoverage( array $stats ): void {
        if ( ! isset( $stats['with_passage'] ) ) {
            \WP_CLI::warning( 'Parse-coverage has not been computed yet (runs on the daily cron and on sermon save).' );
            return;
        }

        \WP_CLI::log( sprintf(
            'Parse-coverage: %s%% (%d of %d sermons with a passage resolve to a link).',
            (string) ( $stats['parse_coverage'] ?? 0 ),
            (int) ( $stats['resolved'] ?? 0 ),
            (int) $stats['with_passage']
        ) );
        \WP_CLI::log( 'Pass --inline for the inline corpus-gate report.' );
        \WP_CLI::success( 'Parse-coverage report complete (no writes).' );
    }

    /**
     * Print the READ-ONLY inline corpus-gate report: the inline-eligible% over the
     * corpus, the withheld-by-reason tally, and — loudly — the unmodeled-pair WRONG-TEXT
     * canary and any source-versification heterogeneity. Writes nothing.
     *
     * @param array{target:string,floor:string,refs_total:int,inline_eligible:int,inline_eligible_pct:float,withheld:array<string,int>,unmodeled_pair_wrong_text:int,families:array<string,int>,dominant_family:string,heterogeneous:bool} $report
     */
    private function reportInline( array $report ): void {
        \WP_CLI::log( sprintf(
            'Inline corpus-gate (target %s, confidence floor %s):',
            $report['target'],
            $report['floor']
        ) );
        \WP_CLI::log( sprintf(
            '  inline-eligible: %s%% (%d of %d render-ready references).',
            (string) $report['inline_eligible_pct'],
            $report['inline_eligible'],
            $report['refs_total']
        ) );

        \WP_CLI::log( '  withheld by reason:' );
        foreach ( $report['withheld'] as $reason => $count ) {
            \WP_CLI::log( sprintf( '    - %-30s %d', $reason, $count ) );
        }

        if ( $report['unmodeled_pair_wrong_text'] > 0 ) {
            \WP_CLI::warning( sprintf(
                'WRONG-TEXT canary: %d reference(s) hit an UNMODELED versification pair — direct proof the divergent-zone table is incomplete. Model these before enabling inline at scale.',
                $report['unmodeled_pair_wrong_text']
            ) );
        }

        if ( $report['heterogeneous'] ) {
            \WP_CLI::warning( sprintf(
                'Source-versification HETEROGENEITY: the corpus carries %d distinct family bucket(s) (dominant: %s). The single site-wide attestation is unsafe — inline could surface real-but-wrong verses for the minority tradition.',
                count( $report['families'] ),
                '' === $report['dominant_family'] ? '(none)' : $report['dominant_family']
            ) );
        }

        \WP_CLI::success( 'Inline corpus-gate report complete (read-only; no writes).' );
    }
}
