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
 *   wp sermonator bible audit [--inline] [--sample=<n>]
 *   wp sermonator bible attest [--force]
 *   wp sermonator bible ack-perseg [--confirm] [--revoke]
 *
 * Phase 3a was LINK-MODE only. Phase 3b Task 8 adds the `vendor` subcommand (vendor the
 * public-domain ENGWEBP per-chapter text to the uploads snapshot, reversibly). Phase 3b
 * Task 9 adds the `warm` subcommand (prime the disposable transient chapter cache for the
 * chapters real sermons cite) — its disk-snapshot peer is `vendor`. The inline-enablement
 * Task I extends `audit --inline` to the three-floor would-promote PREVIEW (+ `--sample`
 * axis-2 spot-check), adds `attest --force` — the single, LOGGED attestation override — and
 * adds `ack-perseg --confirm` — the single, LOGGED axis-2 spot-check acknowledgement that
 * unlocks the per-segment `derived-exact-perseg` confidence floor (design §4 / §5 step 4).
 */
final class BibleCommand {
    /**
     * Optional {@see CoverageAudit} seam: the `audit` path constructs its own read-only
     * audit by default, but tests inject one (e.g. with a warm-chapter resolver) so the
     * promoted-ref `--sample` becomes exercisable without vendored text. Never used by any
     * write path; carries no logic of its own.
     */
    private ?CoverageAudit $coverageAudit;

    public function __construct( ?CoverageAudit $coverageAudit = null ) {
        $this->coverageAudit = $coverageAudit;
    }
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
     * `--inline` it computes the would-promote PREVIEW (T-E) fresh over the live corpus —
     * the would-promote count + inline-eligible% under EACH of the three confidence floors
     * (exact / derived-exact / derived-exact-perseg, assume-attested ceiling), the
     * withheld-by-reason breakdown, and the heterogeneity + unmodeled-pair WRONG-TEXT
     * canaries — and prints it WITHOUT persisting anything.
     *
     * ## OPTIONS
     *
     * [--inline]
     * : Run the three-floor would-promote PREVIEW (per-floor would-promote count +
     *   inline-eligible%, the withheld-by-reason tally, and the heterogeneity / unmodeled-
     *   pair WRONG-TEXT canaries) instead of the parse-coverage summary.
     *
     * [--sample=<n>]
     * : With `--inline`, additionally print up to N PROMOTED references with their raw
     *   passage substrings — the axis-2 human spot-check the per-segment floor is gated
     *   behind (design §4 step 4). READ-ONLY: it writes nothing. Once the printed references
     *   have been verified, the operator records the acknowledgement with the separate,
     *   deliberate `wp sermonator bible ack-perseg --confirm` step (which is the one write).
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
        if ( empty( $assoc_args['inline'] ) ) {
            $this->reportParseCoverage( CoverageAudit::stats() );
            return;
        }

        $audit = $this->coverageAudit ?? new CoverageAudit();

        // `--inline` is now the three-floor would-promote PREVIEW (delegating to the shared
        // CoverageAudit preview, T-E). `--sample=N` adds up to N promoted refs + raw; both
        // are READ-ONLY (the preview classifies and counts but persists nothing).
        $withSample = array_key_exists( 'sample', $assoc_args );
        $sampleSize = $withSample ? max( 0, (int) $assoc_args['sample'] ) : 0;

        $this->reportPromotionPreview( $audit->promotionPreview( true, $sampleSize ), $withSample );

        // T-K remediation (adversarial-review fix): this is the command the Site-Health drift
        // advisory tells the operator to re-run. When inline is enabled and the corpus has
        // drifted back to a SAFE state, re-stamp the reconciliation signature so the advisory
        // actually clears (the prior text was un-clearable by its own instructions).
        $this->reconcileDriftStamp();
    }

    /**
     * Reconcile the Site-Health corpus-drift advisory (T-K) after a re-audit — the documented
     * remediation for that warning ("re-run audit --inline and re-confirm"). The advisory fires
     * when the LIVE corpus-content signature has moved past the one stamped at enable
     * ({@see ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN}). This re-stamps that signature to the
     * current persisted corpus ONLY when ALL hold: inline is ENABLED; a reconciliation stamp
     * already exists; the persisted rollup carries an inline sub-report; the corpus signature
     * has ACTUALLY drifted from the stamp; AND the current corpus is SAFE — homogeneous, zero
     * unmodeled-pair wrong text, and at least one inline-eligible ref (the same canaries the
     * enable soft-gate required). On an UNSAFE drift it writes nothing and warns the operator to
     * resolve the heterogeneity / unmodeled pairs first (so re-auditing can never silently bless
     * a now-dangerous corpus). It reads the SAME persisted rollup the drift warning compares
     * against, so a successful re-stamp deterministically clears the advisory.
     *
     * This re-stamp is operational reconciliation state ONLY — it never touches the preserved
     * {@see ID::META_BIBLE_PASSAGE}/{@see ID::META_BIBLE_REFS} (#1 data preservation), and it
     * never promotes any ref (render-time only).
     */
    private function reconcileDriftStamp(): void {
        if ( ! (bool) get_option( ID::OPTION_BIBLE_INLINE_ENABLED, false ) ) {
            return; // drift is only surfaced for an enabled site; nothing to reconcile.
        }

        $stamped = get_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' );
        $stamped = is_string( $stamped ) ? $stamped : '';
        if ( '' === $stamped ) {
            return; // no enable reconciliation to refresh.
        }

        $stats  = CoverageAudit::stats();
        $inline = isset( $stats['inline'] ) && is_array( $stats['inline'] ) ? $stats['inline'] : array();
        if ( ! isset( $inline['refs_total'] ) ) {
            return; // no persisted inline rollup to reconcile against yet (pre-3b / never run).
        }

        $liveSig = CoverageAudit::inlineSignature( $inline );
        if ( $liveSig === $stamped ) {
            return; // no drift — nothing to reconcile (and no spurious write).
        }

        $heterogeneous = ! empty( $inline['heterogeneous'] );
        $wrongText     = (int) ( $inline['unmodeled_pair_wrong_text'] ?? 0 );
        $eligible      = (int) ( $inline['inline_eligible'] ?? 0 );

        if ( $heterogeneous || $wrongText > 0 || $eligible <= 0 ) {
            \WP_CLI::warning(
                'Corpus drift detected since inline was enabled, but the current corpus is NOT safe '
                . 'to reconcile (heterogeneous, on an unmodeled versification pair, or zero '
                . 'inline-eligible references). Resolve the warnings above; the Site-Health drift '
                . 'advisory stays until the corpus is safe again. No changes made.'
            );
            return;
        }

        update_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, $liveSig );
        \WP_CLI::success(
            'Corpus drift reconciled: re-stamped the enable-time reconciliation signature to the '
            . 'current (safe) corpus. The Site-Health drift advisory is now cleared.'
        );
    }

    /**
     * Print the READ-ONLY would-promote PREVIEW: the would-promote count + inline-eligible%
     * under each of the three confidence floors (assume-attested ceiling), the corpus
     * canaries, and the axis-2 promoted-ref sample (raw passage substrings). Writes nothing.
     *
     * @param array{target:string,assume_attested:bool,refs_total:int,heterogeneous:bool,families:array<string,int>,dominant_family:string,unmodeled_pair_wrong_text:int,floors:array<string,array{floor:string,would_promote:int,inline_eligible:int,inline_eligible_pct:float,withheld:array<string,int>}>,sample:list<array<string,mixed>>} $preview
     * @param bool                                                                                                                                                                                                                                                                                                          $withSample Whether `--sample` was requested (prints the axis-2 promoted-ref section).
     */
    private function reportPromotionPreview( array $preview, bool $withSample = false ): void {
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
            \WP_CLI::log( '    withheld by reason:' );
            foreach ( $floor['withheld'] as $reason => $count ) {
                \WP_CLI::log( sprintf( '      - %-30s %d', $reason, $count ) );
            }
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

        if ( $withSample ) {
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
        \WP_CLI::log( 'Pass --inline for the three-floor would-promote preview.' );
        \WP_CLI::success( 'Parse-coverage report complete (no writes).' );
    }

    /**
     * LOGGED attestation override (design §4). Sets the L6 admin attestation
     * ({@see ID::OPTION_BIBLE_INLINE_ATTESTATION}) TRUE, bypassing the Settings-API
     * heterogeneity hard-disable in {@see \Sermonator\Admin\SettingsRegistrar::sanitizeAttestation()}
     * — the rare-false-positive escape hatch, never a silent UI bypass. It is the ONLY
     * writing path in this command and it ALWAYS records a {@see ID::OPTION_BIBLE_INLINE_ATTEST_LOG}
     * entry (time, acting user, previous state, `cli-force` marker), then bumps the cache
     * generation so the resolver re-evaluates L6 immediately.
     *
     * Refuses without the explicit `--force` flag: the bare subcommand is READ-ONLY — it
     * reports the current attestation state and exits, writing nothing. Unlike the Settings
     * checkbox, `--force` does NOT consult the live audit — the bypass is the whole point —
     * so the operator owns the recorded judgment that the single-tradition premise holds.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Required to write. Set the attestation TRUE despite any heterogeneity signal,
     *   recording a timestamped override entry in the attestation audit log.
     *
     * ## EXAMPLES
     *
     *     wp sermonator bible attest
     *     wp sermonator bible attest --force
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function attest( array $args, array $assoc_args ): void {
        $current = (bool) get_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, false );

        if ( empty( $assoc_args['force'] ) ) {
            \WP_CLI::log( sprintf( 'Inline attestation is currently %s.', $current ? 'ON' : 'OFF' ) );
            \WP_CLI::warning(
                'Setting attestation from the CLI requires the explicit --force override flag '
                . '(the normal path is the Settings checkbox, which is hard-disabled on a '
                . 'heterogeneous corpus). No changes made.'
            );
            return;
        }

        // The single writing path: set the option TRUE and append the logged override entry
        // (the auditable record that the heterogeneity hard-disable was bypassed deliberately).
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $log = get_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        $log[] = array(
            'at'       => time(),
            'user'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'via'      => 'cli-force',
            'previous' => $current,
        );
        update_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG, $log );

        // Mirror the settings-save bump on a bible-axis change so the resolver re-evaluates
        // L6 on the next render (a stale cached link must not survive the attestation).
        $gen = (int) get_option( ID::OPTION_BIBLE_CACHE_GEN, 0 );
        update_option( ID::OPTION_BIBLE_CACHE_GEN, $gen + 1 );

        \WP_CLI::success(
            'Attestation set TRUE via logged --force override (heterogeneity hard-disable bypassed; '
            . 'override recorded in the attestation audit log).'
        );
    }

    /**
     * LOGGED axis-2 spot-check acknowledgement (design §3.3/§3.6, §5 step 4) — the THIRD
     * gate the per-ref `derived-exact-perseg` confidence floor is un-selectable until set
     * ({@see ID::OPTION_BIBLE_INLINE_PERSEG_ACK}). This is the dedicated, human-confirmed
     * SETTER the floor's gate depends on: without it the perseg floor is permanently
     * un-selectable (the sanitize callback floors any perseg submission back to STRICT
     * `derived-exact`), so the headline 49%→76% per-segment recall would be a gate with no
     * key.
     *
     * The intended flow keeps the audit purely READ-ONLY: first run
     * `wp sermonator bible audit --inline --sample=N` and eyeball the promoted references
     * against their raw passage text; once satisfied they are correct, run THIS command with
     * `--confirm` — the one deliberate WRITE, mirroring `attest --force`. It ALWAYS records a
     * {@see ID::OPTION_BIBLE_INLINE_PERSEG_ACK_LOG} entry (time, acting user, previous state,
     * `cli-confirm`/`cli-revoke` marker), so trusting (or withdrawing trust in) per-segment
     * promotion is never silent. This feature is RENDER-TIME only: the ack writes NOTHING to
     * the preserved {@see ID::META_BIBLE_REFS}/{@see ID::META_BIBLE_PASSAGE} (#1 data
     * preservation) and does not itself promote any ref — it merely unlocks the floor for
     * selection. `--revoke` is the exact, instant reverse (the floor returns to
     * un-selectable; a selected perseg floor is floored back to `derived-exact` on the next
     * Settings save).
     *
     * Refuses without an explicit flag: the bare subcommand is READ-ONLY — it reports the
     * current ack state (and the spot-check command to run first) and exits, writing nothing.
     *
     * ## OPTIONS
     *
     * [--confirm]
     * : Required to set the ack. Records the axis-2 acknowledgement TRUE (run AFTER the
     *   `audit --inline --sample=N` spot-check), making `derived-exact-perseg` selectable in
     *   Settings, and appends a timestamped entry to the ack audit log.
     *
     * [--revoke]
     * : Clear the ack (instant, reversible): `derived-exact-perseg` returns to un-selectable.
     *   Also recorded in the ack audit log.
     *
     * ## EXAMPLES
     *
     *     wp sermonator bible ack-perseg
     *     wp sermonator bible ack-perseg --confirm
     *     wp sermonator bible ack-perseg --revoke
     *
     * @subcommand ack-perseg
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function ackPerseg( array $args, array $assoc_args ): void {
        $current = (bool) get_option( ID::OPTION_BIBLE_INLINE_PERSEG_ACK, false );
        $confirm = ! empty( $assoc_args['confirm'] );
        $revoke  = ! empty( $assoc_args['revoke'] );

        if ( $confirm && $revoke ) {
            \WP_CLI::warning( 'Pass either --confirm or --revoke, not both. No changes made.' );
            return;
        }

        if ( ! $confirm && ! $revoke ) {
            \WP_CLI::log( sprintf(
                'Per-segment (derived-exact-perseg) spot-check ack is currently %s.',
                $current ? 'ON' : 'OFF'
            ) );
            \WP_CLI::warning(
                'Recording the axis-2 ack requires the explicit --confirm flag. First run '
                . '"wp sermonator bible audit --inline --sample=N" and verify the promoted '
                . 'references against their raw text, then run this command with --confirm '
                . '(use --revoke to withdraw it). No changes made.'
            );
            return;
        }

        // The single writing path: set/clear the ack and append the logged audit entry (the
        // auditable record that per-segment promotion was deliberately trusted or withdrawn).
        $next = $confirm; // true on --confirm, false on --revoke (the two are mutually exclusive above).
        update_option( ID::OPTION_BIBLE_INLINE_PERSEG_ACK, $next );

        $log = get_option( ID::OPTION_BIBLE_INLINE_PERSEG_ACK_LOG, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        $log[] = array(
            'at'       => time(),
            'user'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'via'      => $confirm ? 'cli-confirm' : 'cli-revoke',
            'previous' => $current,
        );
        update_option( ID::OPTION_BIBLE_INLINE_PERSEG_ACK_LOG, $log );

        \WP_CLI::success(
            $confirm
                ? 'Axis-2 spot-check acknowledged via logged --confirm (recorded in the ack audit log). '
                    . 'The derived-exact-perseg confidence floor is now selectable in Settings.'
                : 'Axis-2 spot-check ack revoked via logged --revoke (recorded in the ack audit log). '
                    . 'The derived-exact-perseg floor is no longer selectable; a selected perseg floor '
                    . 'falls back to derived-exact on the next Settings save.'
        );
    }
}
