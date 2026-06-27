<?php

declare(strict_types=1);

namespace Sermonator\Bible;

use Sermonator\Frontend\Bible\ChapterProvider;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers as ID;

/**
 * Ground-truth corpus audit for Bible-reference PARSE-coverage (Bundle 3, spec Task 13).
 *
 * It answers one question over the published sermon corpus, independently of any render:
 * of the sermons that carry a human-authored {@see ID::META_BIBLE_PASSAGE} label
 * (the denominator), how many have at least one reference that RESOLVES to a
 * render-ready link (the numerator) — plus a per-post breakdown so a green headline
 * can never hide a suppressed maybe-wrong verse:
 *
 *   - resolved                : >=1 ref is in-canon AND structurally valid (would link).
 *   - withheld_low_confidence : refs were extracted but NONE clear the validator
 *                               (e.g. an out-of-canon/structurally-invalid imported ref) —
 *                               withheld rather than shown wrong (the #1 standard).
 *   - parse_fail              : a non-empty passage that yields ZERO refs.
 *   - empty                   : no passage label at all (excluded from the denominator).
 *
 * The four buckets PARTITION the corpus, so total == resolved + withheld + parse_fail + empty
 * and with_passage == total - empty. parse_coverage = resolved / with_passage * 100.
 *
 * Two hard boundaries from the spec:
 *
 *  - NO WRITE-ON-GET. The rollup is computed and written to {@see ID::OPTION_BIBLE_STATS}
 *    ONLY on the scheduled cron hook ({@see self::EVENT_HOOK}) and on a sermon save —
 *    never inside the Site Health "test" read. The Site Health status test is a pure
 *    reader of the precomputed option.
 *  - PARSE-coverage is STRUCTURAL and is NEVER a rollback trigger. It is deliberately
 *    kept separate from the live-fetch-failure metric (Phase 3b) so messy legacy data
 *    cannot cry wolf against the helloao alarm. The Site Health green/amber threshold
 *    here is an informational DISPLAY threshold only.
 *
 * Resolution is the SAME contract the link-mode {@see \Sermonator\Frontend\BibleResolver}
 * uses (in-canon + structurally valid), read from the stored envelope when present and
 * otherwise live-parsed from the preserved passage label — so the audit reflects what a
 * visitor would actually get without re-running the renderer.
 *
 * ## Phase 3b: the inline corpus-gate instrument (spec §3.9 / Task 14)
 *
 * On top of the structural PARSE-coverage above, this class also answers the harder
 * 3b question: of the corpus's render-ready refs, how many would actually render
 * INLINE verse text under the full never-fail-WRONG L1–L9 predicate, and for the rest
 * — WHY were they withheld? {@see self::inlineReport()} runs the SAME layered gate the
 * live {@see \Sermonator\Frontend\BibleResolver} uses (so it predicts exactly what a
 * visitor would get), tallies every withheld ref by its FIRST failing reason, and emits
 * an inline-eligible% over the corpus. It is READ-ONLY (the corpus-gate instrument the
 * operator runs BEFORE flipping inline on at scale): it queries + classifies, and writes
 * NOTHING. The persisted rollup gains an `inline` sub-report ONLY through the existing
 * write-gated {@see self::run()} (cron + on-save), never on a GET — so Site Health and the
 * CLI stay pure readers of a precomputed value (no write-on-GET, off the render path).
 *
 * Two signals are surfaced distinctly because a green headline must never hide a
 * mis-versification (the #1 standard):
 *   - {@see self::INLINE_REASON_UNMODELED_PAIR} is mirrored into a top-level
 *     `unmodeled_pair_wrong_text` counter — a NONZERO value is direct proof the modeled
 *     (source-family → target) set was incomplete for the real corpus.
 *   - {@see self::INLINE_REASON_SRC_HETEROGENEOUS} fires when the corpus carries MORE
 *     THAN ONE source-versification family — direct proof the single site-wide
 *     attestation premise ("all references use one English tradition") is FALSE, so
 *     attesting would surface real-but-wrong verses for the minority tradition.
 *
 * Attestation is ASSUMED TRUE for the inline classification (best-case ceiling) so the
 * L6 attestation toggle cannot mask the deeper L5/L7/L9 wrong-text signals; the
 * `src-heterogeneous` overlay is the independent check on whether that attestation is
 * actually safe to make.
 *
 * @phpstan-type Breakdown array{resolved:int,withheld_low_confidence:int,parse_fail:int,empty:int}
 * @phpstan-type Withheld array{not-inline-eligible:int,low-confidence:int,translation-ineligible:int,src-versification-unsupported:int,src-heterogeneous:int,unmodeled-versification-pair:int,versification-divergent:int,cold-unwarmed:int,verse-out-of-range:int}
 * @phpstan-type InlineReport array{generated_at:int,target:string,floor:string,refs_total:int,inline_eligible:int,inline_eligible_pct:float,withheld:Withheld,unmodeled_pair_wrong_text:int,families:array<string,int>,dominant_family:string,heterogeneous:bool}
 * @phpstan-type Stats array{generated_at:int,total:int,with_passage:int,resolved:int,parse_coverage:float,breakdown:Breakdown,inline:InlineReport}
 */
final class CoverageAudit {
    /** Cron action that recomputes + persists the rollup (also the on-save target). */
    public const EVENT_HOOK = 'sermonator_bible_coverage_audit';

    /** The native Site Health "direct" test id. */
    public const SITE_HEALTH_TEST = 'sermonator_bible_coverage';

    /**
     * Informational DISPLAY threshold (percent) above which Site Health is green.
     * NOT a rollback trigger and NOT the helloao fetch-failure alarm (Phase 3b) —
     * parse-coverage is structural, see the class docblock.
     */
    public const GREEN_THRESHOLD = 90.0;

    /**
     * The withheld-by-reason tags for the inline corpus-gate report. The first SEVEN
     * map directly to the live resolver's fall-open reasons (the §2 L-layers); the
     * `cold-unwarmed` tag is the audit's name for an offline L8 miss (the chapter is
     * not yet vendored/warmed to disk — a "would be available once warmed" state, as
     * opposed to the render-path's `chapter-unavailable`), and `src-heterogeneous` is
     * the corpus-level overlay that has no single-ref render-path analogue.
     *
     * The three versification-relation reasons reuse {@see VersificationGate}'s
     * constants verbatim so the gate stays the single source of those strings.
     */
    public const INLINE_REASON_NOT_INLINE_ELIGIBLE = 'not-inline-eligible';
    public const INLINE_REASON_LOW_CONFIDENCE       = 'low-confidence';
    public const INLINE_REASON_TRANSLATION_INELIGIBLE = 'translation-ineligible';
    public const INLINE_REASON_SRC_UNSUPPORTED      = VersificationGate::REASON_SRC_UNSUPPORTED;
    public const INLINE_REASON_SRC_HETEROGENEOUS    = 'src-heterogeneous';
    public const INLINE_REASON_UNMODELED_PAIR       = VersificationGate::REASON_UNMODELED_PAIR;
    public const INLINE_REASON_DIVERGENT            = VersificationGate::REASON_DIVERGENT;
    public const INLINE_REASON_COLD_UNWARMED        = 'cold-unwarmed';
    public const INLINE_REASON_VERSE_OUT_OF_RANGE   = 'verse-out-of-range';

    /**
     * L6 — the admin has NOT attested the single-tradition premise. NEVER fires in the
     * always-attested {@see self::inlineReport()} (which assumes the best-case ceiling);
     * it is surfaced ONLY by the would-promote PREVIEW when called with `assumeAttested`
     * false (the real, pre-attestation state — "0% until you attest"). Reuses the gate's
     * own constant so the string has a single home.
     */
    public const INLINE_REASON_UNATTESTED           = VersificationGate::REASON_UNATTESTED;

    /**
     * STORED confidence tiers ranked HIGH → LOW for the L2 floor check — kept in BYTE-
     * lockstep with {@see \Sermonator\Frontend\BibleResolver::STORED_CONFIDENCE_RANK} so
     * the audit predicts the live decision exactly. The vocabulary is **de-stored**
     * (design §3.4): it is DISJOINT from the floor vocabulary
     * `{exact,derived-exact,derived-exact-perseg}`. A ref is NEVER stamped `derived-exact`
     * — that is a RENDER-TIME property derived by the shared {@see DerivedExactClassifier},
     * not a persisted tier. So a PRE-STAMPED (smuggled) `confidence:derived-exact` is NOT a
     * recognized stored tier → ranks 0 → clears nothing (closes the import/bulk-promote
     * bypass the de-store exists to close). `ambiguous`/unknown/absent also rank 0.
     *
     * @var array<string,int>
     */
    private const STORED_CONFIDENCE_RANK = array(
        'exact'     => 2,
        'probable'  => 1,
        'ambiguous' => 0,
    );

    /**
     * Resolves the published sermon ids to audit. Injected so the audit math is
     * unit-testable without a live WP_Query; defaults to the real query.
     *
     * @var callable():list<int>
     */
    private $postsProvider;

    /**
     * The L8 offline chapter reader, signature `(translation,bookUSFM,chapter,warmContext)`.
     * Injected so the inline classification is unit-testable without disk/cache; defaults
     * to {@see ChapterProvider::get} bound to `warmContext: false` (disk/transient ONLY —
     * the audit, like the render path, performs ZERO network I/O).
     *
     * @var callable(string,string,int,bool):(array<int,mixed>|null)
     */
    private $chapterResolver;

    /**
     * @param callable():list<int>|null                                       $postsProvider   Resolve the published sermon ids.
     * @param callable(string,string,int,bool):(array<int,mixed>|null)|null   $chapterResolver Offline chapter reader for L8 (tests inject a spy).
     */
    public function __construct( ?callable $postsProvider = null, ?callable $chapterResolver = null ) {
        $this->postsProvider   = $postsProvider ?? array( $this, 'queryPublishedSermons' );
        $this->chapterResolver = $chapterResolver ?? array( ChapterProvider::class, 'get' );
    }

    /**
     * Wire the audit: the Site Health status test (pure read), the recurring
     * recompute cron, and the on-save recompute. The Site Health filter is the
     * only piece that runs on a normal admin GET, and it never writes.
     */
    public function hook(): void {
        add_filter( 'site_status_tests', array( $this, 'registerSiteHealthTest' ) );
        add_action( self::EVENT_HOOK, array( $this, 'run' ) );
        add_action( 'init', array( $this, 'ensureScheduled' ) );
        add_action( 'save_post_' . ID::POST_TYPE_SERMON, array( $this, 'onSave' ), 99, 1 );
    }

    /**
     * Ensure a recurring daily recompute exists. Idempotent (guarded by
     * wp_next_scheduled), so it is safe to call on every `init`.
     */
    public function ensureScheduled(): void {
        if ( ! wp_next_scheduled( self::EVENT_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::EVENT_HOOK );
        }
    }

    /**
     * Recompute off the request after a sermon is saved. Skips autosaves/revisions
     * and debounces rapid saves into a single near-future run, so a bulk edit does
     * not re-audit the whole corpus per post.
     */
    public function onSave( int $postId ): void {
        if ( ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $postId ) )
            || ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $postId ) )
        ) {
            return;
        }

        $delay = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
        wp_schedule_single_event( time() + $delay, self::EVENT_HOOK );
    }

    /**
     * Compute the parse-coverage rollup over the published corpus and persist it to
     * {@see ID::OPTION_BIBLE_STATS}. Returns the same rollup it stored.
     *
     * @return array{generated_at:int,total:int,with_passage:int,resolved:int,parse_coverage:float,breakdown:array{resolved:int,withheld_low_confidence:int,parse_fail:int,empty:int}}
     */
    public function run(): array {
        $ids       = ( $this->postsProvider )();
        $breakdown = array(
            'resolved'                => 0,
            'withheld_low_confidence' => 0,
            'parse_fail'              => 0,
            'empty'                   => 0,
        );

        foreach ( $ids as $id ) {
            $id      = (int) $id;
            $passage = (string) get_post_meta( $id, ID::META_BIBLE_PASSAGE, true );

            if ( '' === trim( $passage ) ) {
                ++$breakdown['empty'];
                continue;
            }

            $refs = $this->refsForPost( $id, $passage );

            if ( array() === $refs ) {
                ++$breakdown['parse_fail'];
                continue;
            }

            if ( $this->anyResolves( $refs ) ) {
                ++$breakdown['resolved'];
            } else {
                ++$breakdown['withheld_low_confidence'];
            }
        }

        $total       = count( $ids );
        $withPassage = $total - $breakdown['empty'];
        $resolved    = $breakdown['resolved'];

        $stats = array(
            'generated_at'   => time(),
            'total'          => $total,
            'with_passage'   => $withPassage,
            'resolved'       => $resolved,
            'parse_coverage' => self::percentage( $resolved, $withPassage ),
            'breakdown'      => $breakdown,
            // The inline corpus-gate sub-report. Folded in here — the ONLY write-gated
            // path (cron + on-save) — so Site Health can read it without recomputing
            // (no write-on-GET). The standalone read-only CLI report uses the same math.
            'inline'         => $this->computeInlineReport( $ids ),
        );

        update_option( ID::OPTION_BIBLE_STATS, $stats, false );

        return $stats;
    }

    /**
     * Compute the inline corpus-gate report over the published corpus and RETURN it,
     * WRITING NOTHING (the read-only instrument behind `wp sermonator bible audit
     * --inline`). It classifies every render-ready ref through the full never-fail-WRONG
     * L1–L9 predicate and tallies the withheld refs by their first failing reason.
     *
     * @return array{generated_at:int,target:string,floor:string,refs_total:int,inline_eligible:int,inline_eligible_pct:float,withheld:array<string,int>,unmodeled_pair_wrong_text:int,families:array<string,int>,dominant_family:string,heterogeneous:bool}
     */
    public function inlineReport(): array {
        return $this->computeInlineReport( ( $this->postsProvider )() );
    }

    /**
     * The READ-ONLY would-promote PREVIEW (design §4 live preview / spec T-E): over the
     * corpus in a SINGLE pass it returns, under EACH of the three floors
     * (`exact` / strict `derived-exact` / `derived-exact-perseg`), the would-promote count
     * (the L2 lever's effect) and the full-predicate inline-eligible% + withheld-by-reason
     * breakdown — so an admin sees the recall of each floor over THEIR OWN corpus before
     * lowering it. It NEVER reads {@see self::confidenceFloor()} (it spans all three) and
     * WRITES NOTHING (no write-on-GET; the persisted rollup stays the cron/on-save concern).
     *
     * `$assumeAttested` is the ceiling lever: TRUE (the default) computes what WOULD promote
     * if the admin attested (so the potential is visible BEFORE attesting); FALSE reflects
     * the real pre-attestation state where site-default refs fall to L6
     * `src-versification-unattested` ("0% until you attest"). Either way the corpus-level
     * canaries — `heterogeneous` (>1 source-versification family bucket) and
     * `unmodeled_pair_wrong_text` (the worst-case, most-permissive-floor unmodeled-pair
     * count) — surface attestation-independently, because a green recall headline must never
     * hide a mis-versification.
     *
     * `$sampleSize` > 0 additionally returns up to N PROMOTED, inline-eligible refs (under
     * the most-permissive perseg floor) paired with their own `raw` passage substring — the
     * axis-2 human spot-check the perseg floor is gated behind (design §4 step 4).
     *
     * Reuses the SAME shared {@see DerivedExactClassifier} both lockstep L2 checks call, so
     * the preview's promotion decision matches the live render exactly.
     *
     * @return array{generated_at:int,target:string,assume_attested:bool,refs_total:int,families:array<string,int>,dominant_family:string,heterogeneous:bool,unmodeled_pair_wrong_text:int,floors:array<string,array{floor:string,would_promote:int,inline_eligible:int,inline_eligible_pct:float,withheld:array<string,int>}>,sample:list<array<string,mixed>>}
     */
    public function promotionPreview( bool $assumeAttested = true, int $sampleSize = 0 ): array {
        return $this->computePromotionPreview( ( $this->postsProvider )(), $assumeAttested, max( 0, $sampleSize ) );
    }

    /**
     * The single-pass would-promote computation behind {@see self::promotionPreview()}.
     * Collects the corpus's render-ready refs ONCE (one meta pass), buckets families for the
     * heterogeneity canary, then classifies every ref under all three floors — the floor only
     * changes L2, so the same per-ref work feeds all three counters. Never writes; never
     * throws; zero network I/O (L8 reads disk/cache via the injected resolver).
     *
     * @param list<int> $ids
     *
     * @return array{generated_at:int,target:string,assume_attested:bool,refs_total:int,families:array<string,int>,dominant_family:string,heterogeneous:bool,unmodeled_pair_wrong_text:int,floors:array<string,array{floor:string,would_promote:int,inline_eligible:int,inline_eligible_pct:float,withheld:array<string,int>}>,sample:list<array<string,mixed>>}
     */
    private function computePromotionPreview( array $ids, bool $assumeAttested, int $sampleSize ): array {
        $target = TranslationRegistry::current()->inlineTranslation();

        $entries       = $this->corpusRefs( $ids );
        $families      = self::familyHistogram( $entries );
        $dominant      = self::dominantFamily( $families );
        $heterogeneous = count( $families ) > 1;

        $floors = array(
            DerivedExactClassifier::FLOOR_EXACT,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG,
        );

        $reports = array();
        foreach ( $floors as $floor ) {
            $reports[ $floor ] = array(
                'floor'               => $floor,
                'would_promote'       => 0,
                'inline_eligible'     => 0,
                'inline_eligible_pct' => 0.0,
                'withheld'            => self::previewWithheldTemplate(),
            );
        }

        $sample = array();

        foreach ( $entries as $entry ) {
            $ref      = $entry['ref'];
            $refCount = $entry['refCount'];
            // Only the `probable` stored tier is PROMOTABLE; `exact` already clears L2
            // natively (not a promotion) and everything else ranks 0.
            $isProbable = ( ( $ref['confidence'] ?? '' ) === 'probable' );

            $persegReason = null;
            foreach ( $floors as $floor ) {
                $promoted = $isProbable && DerivedExactClassifier::promotes( $ref, $floor, $refCount );
                if ( $promoted ) {
                    ++$reports[ $floor ]['would_promote'];
                }

                $reason = $this->classifyInlineRef(
                    $ref,
                    $refCount,
                    $target,
                    $floor,
                    $dominant,
                    $heterogeneous,
                    $assumeAttested
                );
                if ( DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG === $floor ) {
                    $persegReason = $reason;
                }

                if ( null === $reason ) {
                    ++$reports[ $floor ]['inline_eligible'];
                    continue;
                }
                $key = isset( $reports[ $floor ]['withheld'][ $reason ] )
                    ? $reason
                    : self::INLINE_REASON_NOT_INLINE_ELIGIBLE;
                ++$reports[ $floor ]['withheld'][ $key ];
            }

            // The axis-2 spot-check sample: PROMOTED (probable→inline) AND fully eligible
            // under the most-permissive perseg floor, paired with its own raw substring.
            if (
                $sampleSize > 0
                && count( $sample ) < $sampleSize
                && $isProbable
                && null === $persegReason
                && DerivedExactClassifier::promotes( $ref, DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG, $refCount )
            ) {
                $sample[] = self::sampleEntry( $ref );
            }
        }

        $refsTotal = count( $entries );
        foreach ( $floors as $floor ) {
            $reports[ $floor ]['inline_eligible_pct'] = self::percentage(
                $reports[ $floor ]['inline_eligible'],
                $refsTotal
            );
        }

        return array(
            'generated_at'   => time(),
            'target'         => $target,
            'assume_attested' => $assumeAttested,
            'refs_total'     => $refsTotal,
            'families'       => $families,
            'dominant_family' => $dominant,
            'heterogeneous'  => $heterogeneous,
            // Worst-case WRONG-TEXT canary: the unmodeled-pair count at the MOST permissive
            // (perseg) floor, where the most refs reach the L5 versification check — so the
            // canary shows the admin the full potential exposure before they lower the floor.
            'unmodeled_pair_wrong_text' => $reports[ DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG ]['withheld'][ self::INLINE_REASON_UNMODELED_PAIR ],
            'floors'         => $reports,
            'sample'         => $sample,
        );
    }

    /**
     * The withheld-by-reason zero template for a PREVIEW floor report. Identical to the
     * always-attested {@see self::computeInlineReport()} buckets PLUS the
     * `src-versification-unattested` bucket, which only the preview can surface (when called
     * with `assumeAttested` false).
     *
     * @return array<string,int>
     */
    private static function previewWithheldTemplate(): array {
        return array(
            self::INLINE_REASON_NOT_INLINE_ELIGIBLE    => 0,
            self::INLINE_REASON_LOW_CONFIDENCE         => 0,
            self::INLINE_REASON_TRANSLATION_INELIGIBLE => 0,
            self::INLINE_REASON_SRC_UNSUPPORTED        => 0,
            self::INLINE_REASON_SRC_HETEROGENEOUS      => 0,
            self::INLINE_REASON_UNMODELED_PAIR         => 0,
            self::INLINE_REASON_UNATTESTED             => 0,
            self::INLINE_REASON_DIVERGENT              => 0,
            self::INLINE_REASON_COLD_UNWARMED          => 0,
            self::INLINE_REASON_VERSE_OUT_OF_RANGE     => 0,
        );
    }

    /**
     * A spot-check sample row: the ref's own `raw` passage substring plus its structural
     * identity, for the axis-2 human verification that the promoted reference's raw text
     * genuinely names the verse the inline render would show. Read-only; carries no payload.
     *
     * @param array<string,mixed> $ref
     *
     * @return array{raw:string,bookUSFM:string,chapterStart:?int,verseStart:?int,verseEnd:?int,confidence:string}
     */
    private static function sampleEntry( array $ref ): array {
        return array(
            'raw'          => isset( $ref['raw'] ) && is_string( $ref['raw'] ) ? $ref['raw'] : '',
            'bookUSFM'     => isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '',
            'chapterStart' => isset( $ref['chapterStart'] ) ? (int) $ref['chapterStart'] : null,
            'verseStart'   => isset( $ref['verseStart'] ) && null !== $ref['verseStart'] ? (int) $ref['verseStart'] : null,
            'verseEnd'     => isset( $ref['verseEnd'] ) && null !== $ref['verseEnd'] ? (int) $ref['verseEnd'] : null,
            'confidence'   => isset( $ref['confidence'] ) && is_string( $ref['confidence'] ) ? $ref['confidence'] : '',
        );
    }

    /**
     * The inline corpus-gate computation shared by {@see self::run()} (which persists it
     * under the rollup's `inline` key) and {@see self::inlineReport()} (which returns it
     * read-only). Never writes; never throws; performs ZERO network I/O (L8 reads disk/
     * cache only via the injected resolver with `warmContext: false`).
     *
     * Method: collect every in-canon, structurally-valid ref across the corpus; bucket
     * each by its source-versification FAMILY to find the dominant tradition and whether
     * the corpus is heterogeneous; then classify each ref by the layered predicate
     * ({@see self::classifyInlineRef()}). Attestation is ASSUMED TRUE so the deeper
     * wrong-text signals are not masked; the `src-heterogeneous` overlay independently
     * proves whether that assumption holds.
     *
     * @param list<int> $ids
     *
     * @return array{generated_at:int,target:string,floor:string,refs_total:int,inline_eligible:int,inline_eligible_pct:float,withheld:array<string,int>,unmodeled_pair_wrong_text:int,families:array<string,int>,dominant_family:string,heterogeneous:bool}
     */
    private function computeInlineReport( array $ids ): array {
        $target = TranslationRegistry::current()->inlineTranslation();
        $floor  = self::confidenceFloor();

        // Per-post grouped refs: each entry carries the ref AND its own post's envelope
        // refCount, threaded into the L2 promotion check so the STRICT `derived-exact`
        // singleton constraint (a compound passage's segments never promote) matches the
        // resolver post-for-post.
        $entries       = $this->corpusRefs( $ids );
        $families      = self::familyHistogram( $entries );
        $dominant      = self::dominantFamily( $families );
        $heterogeneous = count( $families ) > 1;

        $withheld = array(
            self::INLINE_REASON_NOT_INLINE_ELIGIBLE   => 0,
            self::INLINE_REASON_LOW_CONFIDENCE        => 0,
            self::INLINE_REASON_TRANSLATION_INELIGIBLE => 0,
            self::INLINE_REASON_SRC_UNSUPPORTED       => 0,
            self::INLINE_REASON_SRC_HETEROGENEOUS     => 0,
            self::INLINE_REASON_UNMODELED_PAIR        => 0,
            self::INLINE_REASON_DIVERGENT             => 0,
            self::INLINE_REASON_COLD_UNWARMED         => 0,
            self::INLINE_REASON_VERSE_OUT_OF_RANGE    => 0,
        );

        $eligible = 0;
        foreach ( $entries as $entry ) {
            $reason = $this->classifyInlineRef(
                $entry['ref'],
                $entry['refCount'],
                $target,
                $floor,
                $dominant,
                $heterogeneous
            );
            if ( null === $reason ) {
                ++$eligible;
                continue;
            }
            // Defensive: an unknown reason is never silently dropped — fold it under the
            // structural pre-filter bucket so the partition still holds.
            $key = isset( $withheld[ $reason ] ) ? $reason : self::INLINE_REASON_NOT_INLINE_ELIGIBLE;
            ++$withheld[ $key ];
        }

        $refsTotal = count( $entries );

        return array(
            'generated_at'              => time(),
            'target'                    => $target,
            'floor'                     => $floor,
            'refs_total'                => $refsTotal,
            'inline_eligible'           => $eligible,
            'inline_eligible_pct'       => self::percentage( $eligible, $refsTotal ),
            'withheld'                  => $withheld,
            // Distinct WRONG-TEXT canary: a nonzero value is direct proof the modeled
            // (source-family → target) set was incomplete for this corpus (spec §3.9).
            'unmodeled_pair_wrong_text' => $withheld[ self::INLINE_REASON_UNMODELED_PAIR ],
            'families'                  => $families,
            'dominant_family'           => $dominant,
            'heterogeneous'             => $heterogeneous,
        );
    }

    /**
     * Classify ONE in-canon, structurally-valid ref through the never-fail-WRONG L1–L9
     * predicate, returning the FIRST failing reason or null when fully inline-eligible.
     * Mirrors {@see \Sermonator\Frontend\BibleResolver::resolveInline()} layer-for-layer
     * (so the audit predicts the live render) with one addition: the corpus-level
     * `src-heterogeneous` overlay, slotted just BEFORE the L4 family check so that in a
     * mixed corpus the MINORITY-tradition refs are tagged heterogeneous (the louder,
     * attestation-violating signal) while a homogeneous-but-foreign corpus still reports
     * the plain L4 `src-versification-unsupported`.
     *
     * @param array<string,mixed> $ref           In-canon, structurally-valid ref.
     * @param int                 $refCount      The ref's OWN post envelope ref count (the STRICT singleton constraint).
     * @param string              $target        Inline target translation id (e.g. ENGWEBP).
     * @param string              $floor         L2 confidence floor.
     * @param string              $dominant      The corpus-dominant source-versification family bucket.
     * @param bool                $heterogeneous Whether the corpus carries >1 family bucket.
     * @param bool                $attested      L6 attestation assumption (default TRUE — the
     *                                           best-case ceiling {@see self::inlineReport()}
     *                                           uses; the preview passes FALSE to surface the
     *                                           pre-attestation `src-versification-unattested`).
     */
    private function classifyInlineRef( array $ref, int $refCount, string $target, string $floor, string $dominant, bool $heterogeneous, bool $attested = true ): ?string {
        // L1 — pure structural inline-shape: a specific verse, not cross-chapter.
        $verseStart = $ref['verseStart'] ?? null;
        $chapterEnd = $ref['chapterEnd'] ?? null;
        if ( null === $verseStart || null !== $chapterEnd ) {
            return self::INLINE_REASON_NOT_INLINE_ELIGIBLE;
        }

        // L2 — confidence floor (default `exact`); a stored `probable` ref may be PROMOTED
        // here by the SAME shared render-time classifier the resolver delegates to.
        if ( ! self::confidenceClears( $ref, $floor, $refCount ) ) {
            return self::INLINE_REASON_LOW_CONFIDENCE;
        }

        // L3 — the inline TARGET translation must itself be inline-eligible.
        if ( ! array_key_exists( $target, BibleTranslations::curatedInline() ) ) {
            return self::INLINE_REASON_TRANSLATION_INELIGIBLE;
        }

        // Corpus overlay — in a heterogeneous corpus, a ref NOT in the dominant tradition
        // bucket means the single site-wide attestation premise is false for it (wrong-text
        // risk), so it is withheld as `src-heterogeneous` regardless of how its own family
        // would otherwise gate.
        $family = VersificationGate::familyCode( self::srcVersification( $ref ) );
        $bucket = '' === $family ? 'unknown' : $family;
        if ( $heterogeneous && $bucket !== $dominant ) {
            return self::INLINE_REASON_SRC_HETEROGENEOUS;
        }

        // L4 — source versification must normalize to a modeled family.
        if ( '' === $family ) {
            return self::INLINE_REASON_SRC_UNSUPPORTED;
        }

        // L5–L7 — the (source-family → target) versification relation. The gate returns the
        // precise reason for an unmodeled pair (L5), an unattested site-default ref (L6, only
        // when `$attested` is false), or a divergent zone (L7). The always-attested ceiling
        // ({@see self::inlineReport()}) passes true so L6 cannot fire there.
        $gate = VersificationGate::eligible( $ref, $target, $attested );
        if ( ! $gate['eligible'] ) {
            return (string) $gate['reason'];
        }

        // L8 — RENDER-CONTEXT parity: disk/cache ONLY, zero network (warmContext FALSE).
        // An offline miss here is "not yet warmed/vendored", the audit's `cold-unwarmed`.
        $book       = isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '';
        $chapterNum = isset( $ref['chapterStart'] ) ? (int) $ref['chapterStart'] : 0;
        $chapter    = ( $this->chapterResolver )( $target, $book, $chapterNum, false );
        if ( ! is_array( $chapter ) || array() === $chapter ) {
            return self::INLINE_REASON_COLD_UNWARMED;
        }

        // L9 — every verse verseStart..verseEnd is physically present in the chapter.
        if ( ! RefValidator::rangeWithinChapter( $ref, $chapter ) ) {
            return self::INLINE_REASON_VERSE_OUT_OF_RANGE;
        }

        return null;
    }

    /**
     * Flatten every in-canon, structurally-valid ref across the corpus (the same
     * render-ready universe {@see self::anyResolves()} counts, but per-ref) — each PAIRED
     * with its own post's envelope refCount.
     *
     * The refCount is the count of the post's WHOLE stored ref list read through the ONE
     * shared {@see RefsEnvelope::decode()}, **UNFILTERED** — non-array junk entries are
     * INCLUDED, exactly as {@see \Sermonator\Frontend\BibleResolver::resolve()} takes
     * `count( $refs )` over `readEnvelopeRefs()` BEFORE its per-ref
     * `if ( ! is_array( $ref ) ) { continue; }`. Counting the identical population is what
     * makes the STRICT `derived-exact` singleton constraint (a compound passage's segments
     * never promote) decide identically here and at render — closing the malformed-envelope
     * lockstep gap where dropping a junk sibling before the count would falsely promote a
     * lone clean `probable` in the audit while the render withholds it.
     *
     * Only the array-typed refs are iterated for the in-canon/structural filter (the
     * resolver's per-ref guard). A post with a stored envelope whose entries are ALL
     * non-array junk contributes nothing here — exactly as the resolver renders nothing
     * inline for it (it never live-parses once an envelope is present). Live-parse of the
     * preserved label is the fallback ONLY when there is no usable stored envelope (where
     * the resolver likewise renders nothing inline and every parsed ref is an array).
     *
     * @param list<int> $ids
     *
     * @return list<array{ref:array<string,mixed>,refCount:int}>
     */
    private function corpusRefs( array $ids ): array {
        $out = array();

        foreach ( $ids as $id ) {
            $id      = (int) $id;
            $passage = (string) get_post_meta( $id, ID::META_BIBLE_PASSAGE, true );
            if ( '' === trim( $passage ) ) {
                continue;
            }

            $envelope = RefsEnvelope::decode( get_post_meta( $id, ID::META_BIBLE_REFS, true ) );

            if ( null !== $envelope ) {
                // UNFILTERED count — byte-lockstep with the resolver's refCount.
                $refCount = count( $envelope );
                $postRefs = array_values( array_filter( $envelope, 'is_array' ) );
            } else {
                // No usable stored envelope: live-parse the preserved label as ground
                // truth (every parsed ref is an array, so the count needs no filter).
                $postRefs = $this->liveParseRefs( $passage );
                $refCount = count( $postRefs );
            }

            foreach ( $postRefs as $ref ) {
                $flags = RefValidator::validate( $ref );
                if ( $flags['inCanon'] && $flags['structurallyValid'] ) {
                    $out[] = array( 'ref' => $ref, 'refCount' => $refCount );
                }
            }
        }

        return $out;
    }

    /**
     * Histogram of source-versification family buckets across the corpus refs. An
     * unrecognized/empty `srcVersification` buckets under the literal `unknown` so a
     * corpus that MIXES a known English tradition with a foreign one reads as
     * heterogeneous (more than one bucket) rather than silently collapsing.
     *
     * @param list<array{ref:array<string,mixed>,refCount:int}> $entries
     *
     * @return array<string,int>
     */
    private static function familyHistogram( array $entries ): array {
        $hist = array();

        foreach ( $entries as $entry ) {
            $family = VersificationGate::familyCode( self::srcVersification( $entry['ref'] ) );
            $bucket = '' === $family ? 'unknown' : $family;
            $hist[ $bucket ] = ( $hist[ $bucket ] ?? 0 ) + 1;
        }

        return $hist;
    }

    /**
     * The dominant (modal) family bucket, or '' when the corpus has no refs. Ties break
     * deterministically toward the first-seen bucket so the report is reproducible.
     *
     * @param array<string,int> $families
     */
    private static function dominantFamily( array $families ): string {
        $dominant = '';
        $best     = -1;

        foreach ( $families as $bucket => $count ) {
            if ( $count > $best ) {
                $best     = $count;
                $dominant = (string) $bucket;
            }
        }

        return $dominant;
    }

    /**
     * L2 — does the ref clear the configured confidence floor? BYTE-lockstep with
     * {@see \Sermonator\Frontend\BibleResolver::confidenceClears()}: the tier is
     * **de-stored**, so promotion is delegated to the SAME shared
     * {@see DerivedExactClassifier::promotes()} the resolver calls (re-parse-identity + the
     * floor's sibling-count policy), never a persisted stamp.
     *
     *   - stored `exact`    → the top stored tier; clears EVERY floor outright;
     *   - stored `probable` → clears a `derived-exact*` floor ONLY when `promotes()` does
     *                         (and `promotes()` is false by construction under `exact`);
     *   - anything else     → `ambiguous`, absent, OR a SMUGGLED pre-stamped
     *                         `derived-exact` (not a stored tier) → clears NOTHING.
     *
     * @param array<string,mixed> $ref
     * @param string              $floor    One of `exact` | `derived-exact` | `derived-exact-perseg`.
     * @param int                 $refCount The ref's OWN post envelope ref count (the STRICT singleton constraint).
     */
    private static function confidenceClears( array $ref, string $floor, int $refCount ): bool {
        $refConf = isset( $ref['confidence'] ) && is_string( $ref['confidence'] ) ? $ref['confidence'] : '';
        $refRank = self::STORED_CONFIDENCE_RANK[ $refConf ] ?? 0;

        // `exact` — the top stored tier — clears every floor outright.
        if ( $refRank >= self::STORED_CONFIDENCE_RANK['exact'] ) {
            return true;
        }

        // `probable` — the only PROMOTABLE stored tier — defers entirely to the shared
        // render-time classifier (which returns false for the `exact` floor).
        if ( $refRank >= self::STORED_CONFIDENCE_RANK['probable'] ) {
            return DerivedExactClassifier::promotes( $ref, $floor, $refCount );
        }

        // `ambiguous` / absent / a pre-stamped `derived-exact`: never clears.
        return false;
    }

    /**
     * The configured L2 confidence FLOOR, validated against the de-stored floor vocabulary
     * `{exact,derived-exact,derived-exact-perseg}` (default + fallback `exact`, the most
     * conservative — promotes nothing). BYTE-lockstep with
     * {@see \Sermonator\Frontend\BibleResolver::confidenceFloor()}; an unknown/legacy value
     * (e.g. a stale `probable` floor) normalizes to `exact`.
     */
    private static function confidenceFloor(): string {
        $stored = get_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, DerivedExactClassifier::FLOOR_EXACT );
        $stored = is_string( $stored ) ? $stored : DerivedExactClassifier::FLOOR_EXACT;

        $valid = array(
            DerivedExactClassifier::FLOOR_EXACT,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT,
            DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG,
        );

        return in_array( $stored, $valid, true ) ? $stored : DerivedExactClassifier::FLOOR_EXACT;
    }

    /**
     * Read a ref's `srcVersification` as a string ('' when absent/non-string).
     *
     * @param array<string,mixed> $ref
     */
    private static function srcVersification( array $ref ): string {
        $value = $ref['srcVersification'] ?? '';

        return is_string( $value ) ? $value : '';
    }

    /**
     * Register the native Site Health "direct" status test. Pure read of the
     * precomputed option — does NOT recompute (no write-on-GET).
     *
     * @param array<string,array<string,mixed>> $tests
     *
     * @return array<string,array<string,mixed>>
     */
    public function registerSiteHealthTest( $tests ): array {
        if ( ! is_array( $tests ) ) {
            $tests = array();
        }
        if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
            $tests['direct'] = array();
        }

        $tests['direct'][ self::SITE_HEALTH_TEST ] = array(
            'label' => __( 'Sermon scripture-reference coverage', 'sermonator' ),
            'test'  => array( $this, 'siteHealthResult' ),
        );

        return $tests;
    }

    /**
     * Build the Site Health result array from the precomputed rollup. Green when the
     * corpus has no passages to resolve OR parse-coverage is at/above the display
     * threshold; amber (recommended) otherwise. Reports the parse-coverage % and the
     * withheld/parse-fail counts so the headline cannot hide a suppressed reference.
     *
     * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
     */
    public function siteHealthResult(): array {
        $stored = get_option( ID::OPTION_BIBLE_STATS, array() );
        $stats  = is_array( $stored ) ? $stored : array();

        $badge = array(
            'label' => __( 'Sermons', 'sermonator' ),
            'color' => 'blue',
        );

        if ( ! isset( $stats['with_passage'] ) ) {
            return array(
                'label'       => __( 'Scripture-reference coverage has not been computed yet', 'sermonator' ),
                'status'      => 'recommended',
                'badge'       => $badge,
                'description' => '<p>' . esc_html__(
                    'Sermonator has not yet audited how many sermon scripture references resolve to a link. The audit runs on a schedule and whenever a sermon is saved.',
                    'sermonator'
                ) . '</p>',
                'actions'     => '',
                'test'        => self::SITE_HEALTH_TEST,
            );
        }

        $withPassage = (int) $stats['with_passage'];
        $resolved    = (int) ( $stats['resolved'] ?? 0 );
        $coverage    = isset( $stats['parse_coverage'] )
            ? (float) $stats['parse_coverage']
            : self::percentage( $resolved, $withPassage );
        $withheld    = (int) ( $stats['breakdown']['withheld_low_confidence'] ?? 0 );
        $parseFail   = (int) ( $stats['breakdown']['parse_fail'] ?? 0 );

        $green  = ( 0 === $withPassage ) || ( $coverage >= self::GREEN_THRESHOLD );
        $status = $green ? 'good' : 'recommended';

        // T-K — corpus-drift advisory: an actionable warning downgrades the headline.
        $drift = $this->driftWarning( $stats );
        if ( '' !== $drift ) {
            $status = 'recommended';
        }

        $label = 0 === $withPassage
            ? __( 'No sermon scripture references to resolve', 'sermonator' )
            : __( 'Sermon scripture references resolve to links', 'sermonator' );

        $description = '<p>' . esc_html(
            sprintf(
                /* translators: 1: percentage, 2: resolved count, 3: total-with-passage count. */
                __( '%1$s%% of sermon scripture references resolve to a link (%2$d of %3$d sermons with a passage).', 'sermonator' ),
                self::formatPercent( $coverage ),
                $resolved,
                $withPassage
            )
        ) . '</p>';

        if ( $withheld > 0 || $parseFail > 0 ) {
            $description .= '<p>' . esc_html(
                sprintf(
                    /* translators: 1: withheld count, 2: parse-fail count. */
                    __( '%1$d reference set(s) were withheld as low-confidence and %2$d passage(s) could not be parsed; these are shown as plain text rather than a possibly-wrong link.', 'sermonator' ),
                    $withheld,
                    $parseFail
                )
            ) . '</p>';
        }

        $description .= $this->inlineDescription( $stats );
        $description .= $drift;

        return array(
            'label'       => $label,
            'status'      => $status,
            'badge'       => $badge,
            'description' => $description,
            'actions'     => '',
            'test'        => self::SITE_HEALTH_TEST,
        );
    }

    /**
     * The Site Health inline corpus-gate paragraph(s), built PURELY from the persisted
     * rollup's `inline` sub-report (no recompute, no write). Empty string when the
     * rollup predates the 3b extension (back-compat) or carries no refs. Surfaces the
     * inline-eligible% and — LOUDLY — the two wrong-text canaries (the unmodeled-pair
     * counter and corpus heterogeneity), because a green parse-coverage headline must
     * never hide a mis-versification.
     *
     * Attestation-aware labeling: the stored inline sub-report is computed with
     * attestation ASSUMED TRUE (the best-case ceiling — {@see self::computeInlineReport()}
     * always passes `$attested=true`). On a pre-attestation site L6 withholds every
     * site-default ref, so displaying the ceiling figure as a live fact would be
     * misleading. When attestation is currently OFF the paragraph is labeled as a
     * potential ceiling ("up to X% once you attest; 0 render inline until then").
     *
     * @param array<string,mixed> $stats
     */
    private function inlineDescription( array $stats ): string {
        $inline = isset( $stats['inline'] ) && is_array( $stats['inline'] ) ? $stats['inline'] : array();
        if ( ! isset( $inline['refs_total'] ) || (int) $inline['refs_total'] <= 0 ) {
            return '';
        }

        $pct      = isset( $inline['inline_eligible_pct'] ) ? (float) $inline['inline_eligible_pct'] : 0.0;
        $eligible = (int) ( $inline['inline_eligible'] ?? 0 );
        $total    = (int) $inline['refs_total'];

        // L6 attestation state: the stored figure was computed with attestation ASSUMED
        // TRUE (the best-case ceiling). If attestation is currently OFF, the live render
        // withholds ALL site-default refs at L6 — so the ceiling must be labeled as a
        // potential, not a live fact.
        $attested = (bool) get_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, false );

        if ( $attested ) {
            $out = '<p>' . esc_html(
                sprintf(
                    /* translators: 1: percentage, 2: eligible ref count, 3: total ref count. */
                    __( '%1$s%% of scripture references are inline-eligible (%2$d of %3$d) under the never-fail-wrong gate; the rest fall back to a link.', 'sermonator' ),
                    self::formatPercent( $pct ),
                    $eligible,
                    $total
                )
            ) . '</p>';
        } else {
            // Pre-attestation: the ceiling assumes the single-tradition premise is met.
            // Until attestation is set, L6 withholds all site-default refs and 0 render inline.
            $out = '<p>' . esc_html(
                sprintf(
                    /* translators: 1: percentage, 2: eligible ref count, 3: total ref count. */
                    __( 'Up to %1$s%% of scripture references could be inline-eligible (%2$d of %3$d) once the single-tradition premise is attested; 0 render inline until then (L6 withholds all site-default references until attestation is set).', 'sermonator' ),
                    self::formatPercent( $pct ),
                    $eligible,
                    $total
                )
            ) . '</p>';
        }

        $wrongText = (int) ( $inline['unmodeled_pair_wrong_text'] ?? 0 );
        if ( $wrongText > 0 ) {
            $out .= '<p>' . esc_html(
                sprintf(
                    /* translators: %d: count of references hitting an unmodeled versification pair. */
                    __( 'WARNING: %d reference(s) use an unmodeled source/target versification pair — proof the divergent-zone table is incomplete. Do NOT enable inline scripture at scale until these are modeled.', 'sermonator' ),
                    $wrongText
                )
            ) . '</p>';
        }

        if ( ! empty( $inline['heterogeneous'] ) ) {
            $out .= '<p>' . esc_html__(
                'WARNING: the corpus mixes more than one source-versification tradition. The single site-wide attestation is unsafe; inline rendering could surface real-but-wrong verses for the minority tradition.',
                'sermonator'
            ) . '</p>';
        }

        return $out;
    }

    /**
     * Stable CORPUS-CONTENT signature of an inline sub-report — the drift fingerprint
     * (design §3.6, decision 6 / spec T-K; adversarial-review fix). It hashes ONLY the
     * pure corpus-content fields — `refs_total`, the (key-sorted) source-versification
     * `families` map, the `dominant_family`, and the `heterogeneous` flag — and
     * DELIBERATELY EXCLUDES both `generated_at` and `unmodeled_pair_wrong_text`.
     *
     * `generated_at` is a wall-clock {@see time()} re-stamped on EVERY recompute (the daily
     * cron and every sermon save), independent of any corpus change. Keying drift off it
     * produced a permanent FALSE POSITIVE: the first routine re-audit after enable advanced
     * the timestamp past the enable stamp and the advisory fired forever, with zero corpus
     * change.
     *
     * `unmodeled_pair_wrong_text` is DELIBERATELY EXCLUDED because it is a FLOOR-DEPENDENT
     * computed count: refs must clear L2 (the configured confidence floor) before they reach
     * the L5 versification gate where unmodeled pairs fire. Widening the floor from `exact`
     * to `derived-exact` over an UNCHANGED corpus promotes additional refs into L5, which can
     * change the unmodeled-pair count without any corpus change — producing a misattributed
     * "a later import or new sermons" drift warning. The corpus-content fields above
     * (`refs_total`, `families`, `dominant_family`, `heterogeneous`) change whenever the
     * CORPUS genuinely changes and are sufficient to detect real drift; a floor change alone
     * — with no corpus change — must not advance the signature.
     *
     * Hashing corpus CONTENT instead means a routine recompute over an UNCHANGED corpus
     * (or a floor change over an unchanged corpus) yields an IDENTICAL signature (silent),
     * while a genuine corpus change (a new family bucket, a different ref count) advances it
     * (warns) — so the equal/silent steady state is actually REACHABLE in production.
     * Pure: deterministic, no WP, no I/O, never throws.
     *
     * @param array<string,mixed> $inline An inline sub-report (or {@see self::inlineReport()} shape).
     */
    public static function inlineSignature( array $inline ): string {
        $families   = isset( $inline['families'] ) && is_array( $inline['families'] ) ? $inline['families'] : array();
        $normalized = array();
        foreach ( $families as $bucket => $count ) {
            $normalized[ (string) $bucket ] = (int) $count;
        }
        ksort( $normalized );

        $payload = array(
            'refs_total'      => (int) ( $inline['refs_total'] ?? 0 ),
            'families'        => $normalized,
            'dominant_family' => isset( $inline['dominant_family'] ) && is_string( $inline['dominant_family'] )
                ? $inline['dominant_family']
                : '',
            'heterogeneous'   => ! empty( $inline['heterogeneous'] ),
            // unmodeled_pair_wrong_text is INTENTIONALLY excluded: it is floor-dependent
            // (more refs reach L5 under a wider floor), so including it would advance the
            // signature on a floor change with no corpus change, firing a false drift warning.
        );

        return hash( 'sha256', (string) json_encode( $payload ) );
    }

    /**
     * The Site-Health CORPUS-DRIFT advisory (design §3.6, decision 6 / spec T-K), built
     * PURELY from already-persisted options (no recompute, no write — the same pure-reader
     * boundary the rest of {@see self::siteHealthResult()} honors). It fires when, with
     * inline rendering ENABLED, the LIVE corpus-content signature (derived from the persisted
     * rollup's `inline` sub-report via {@see self::inlineSignature()}) DIFFERS from the
     * reconciliation signature stamped at enable-time
     * ({@see ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN}, written by the enable soft-gate in
     * {@see \Sermonator\Admin\SettingsRegistrar::sanitizeInlineEnabled()}).
     *
     * The signal is a corpus-content fingerprint, NOT a wall-clock timestamp: a routine
     * cron/on-save re-audit over an UNCHANGED corpus reproduces the SAME signature and stays
     * silent. Only a genuine corpus change — a freshly imported sub-corpus that drifted
     * heterogeneous, landed on an unmodeled versification pair, or merely changed the
     * ref/family makeup the enable-moment reconciliation never saw — advances the signature
     * and surfaces the advisory. The advisory tells the operator to re-audit (which re-stamps
     * the signature once the corpus is safe again — {@see \Sermonator\Cli\BibleCommand::audit()});
     * it NEVER disables inline (instant rollback stays the floor/attestation lever).
     *
     * Empty string (silent) unless ALL hold: inline is enabled; a reconciliation signature
     * exists (an enable actually happened); the persisted rollup carries an inline sub-report
     * (a pre-3b rollup never falsely drifts); and the live signature DIFFERS from the stamp.
     *
     * @param array<string,mixed> $stats The persisted rollup (already read by the caller).
     */
    private function driftWarning( array $stats ): string {
        // Only meaningful once inline rendering is ENABLED (a reconciliation occurred).
        if ( ! self::inlineEnabled() ) {
            return '';
        }

        // The corpus-content signature the enable reconciled against. Absent/'' (or a legacy
        // non-string stamp) → no usable enable reconciliation → silent.
        $stamped = get_option( ID::OPTION_BIBLE_INLINE_ENABLED_AUDIT_GEN, '' );
        $stamped = is_string( $stamped ) ? $stamped : '';
        if ( '' === $stamped ) {
            return '';
        }

        // The LIVE corpus signature, derived from the persisted inline sub-report. A pre-3b
        // rollup (no inline key / no refs_total) carries no signal → never falsely drifts.
        $inline = isset( $stats['inline'] ) && is_array( $stats['inline'] ) ? $stats['inline'] : array();
        if ( ! isset( $inline['refs_total'] ) ) {
            return '';
        }

        // Decoupled from wall-clock entirely: equal signature (corpus unchanged since enable,
        // INCLUDING after any number of routine re-audits) → silent; different → warn.
        if ( self::inlineSignature( $inline ) === $stamped ) {
            return '';
        }

        return '<p>' . esc_html__(
            'WARNING: the sermon corpus has changed since inline scripture was enabled. Inline rendering was reconciled against an older corpus; a later import or new sermons may have introduced a different versification tradition or an unmodeled versification pair that the enable-time reconciliation never checked. Re-run "wp sermonator bible audit --inline" and re-confirm the warnings before relying on inline coverage.',
            'sermonator'
        ) . '</p>';
    }

    /**
     * Whether inline verse rendering is currently enabled (pure read). Drift is only
     * surfaced for an enabled site — a stale stamp under a disabled feature is not drift.
     */
    private static function inlineEnabled(): bool {
        return (bool) get_option( ID::OPTION_BIBLE_INLINE_ENABLED, false );
    }

    /**
     * Read the persisted rollup (pure read). Returns an empty array when the audit
     * has never run.
     *
     * @return array<string,mixed>
     */
    public static function stats(): array {
        $stored = get_option( ID::OPTION_BIBLE_STATS, array() );

        return is_array( $stored ) ? $stored : array();
    }

    /**
     * The refs to classify for a post: the stored envelope when it carries usable
     * refs, otherwise a live parse of the preserved passage label (so un-backfilled
     * sermons still count as ground truth). Never throws; never writes.
     *
     * @return list<array<string,mixed>>
     */
    private function refsForPost( int $postId, string $passage ): array {
        $envelope = RefsEnvelope::decode( get_post_meta( $postId, ID::META_BIBLE_REFS, true ) );

        if ( null !== $envelope ) {
            $refs = array_values( array_filter( $envelope, 'is_array' ) );
            if ( array() !== $refs ) {
                return $refs;
            }
        }

        // No usable stored envelope (un-backfilled / un-authored): live-parse the
        // preserved label as ground truth.
        return $this->liveParseRefs( $passage );
    }

    /**
     * Live-parse a preserved passage label into its array refs (the un-backfilled
     * ground-truth fallback). Never throws; never writes.
     *
     * @return list<array<string,mixed>>
     */
    private function liveParseRefs( string $passage ): array {
        $parsed = ReferenceParser::parse( $passage );
        $refs   = array();
        foreach ( $parsed['segments'] as $segment ) {
            foreach ( $segment['refs'] as $ref ) {
                if ( is_array( $ref ) ) {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * Does at least one ref resolve to a render-ready link? Uses the exact link-mode
     * contract of {@see \Sermonator\Frontend\BibleResolver} (in-canon + structurally
     * valid), so the audit numerator matches what a visitor would actually see.
     *
     * @param list<array<string,mixed>> $refs
     */
    private function anyResolves( array $refs ): bool {
        foreach ( $refs as $ref ) {
            $flags = RefValidator::validate( $ref );
            if ( $flags['inCanon'] && $flags['structurallyValid'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default query: every PUBLISHED sermon id (the audited corpus). Includes posts
     * with and without a passage so the denominator (with_passage) and the `empty`
     * bucket are both measured from one pass.
     *
     * @return list<int>
     */
    private function queryPublishedSermons(): array {
        $query = new \WP_Query( array(
            'post_type'              => ID::POST_TYPE_SERMON,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ) );

        return array_map( 'intval', $query->posts );
    }

    /** Percentage of $numerator over $denominator, one decimal, 0.0 when no denominator. */
    private static function percentage( int $numerator, int $denominator ): float {
        if ( $denominator <= 0 ) {
            return 0.0;
        }

        return round( $numerator / $denominator * 100, 1 );
    }

    /** Trim a one-decimal percentage to an integer-looking string when whole (90 not 90.0). */
    private static function formatPercent( float $percent ): string {
        if ( floor( $percent ) === $percent ) {
            return (string) (int) $percent;
        }

        return rtrim( rtrim( number_format( $percent, 1, '.', '' ), '0' ), '.' );
    }
}
