<?php

declare(strict_types=1);

namespace Sermonator\Bible;

use Sermonator\Schema\BibleTranslations;

/**
 * The PURE (source-family → target) never-fail-WRONG relation (design §2 L4–L7,
 * §3.1) — the core of the inline-Bible spine.
 *
 * ## Why this class exists (the headline correction)
 *
 * 3a's {@see RefValidator::isVersificationDivergent()} is a UNARY constant blind to
 * BOTH axes: it reads neither the church's source versification nor the inline
 * target translation, so it cannot answer the only question that matters — "would
 * rendering THIS target's words, for a reference carried in THAT source's
 * versification, show real-but-WRONG verses?". Worse, its divergent table was
 * calibrated on the HEBREW↔English offset (counting Hebrew superscriptions as
 * verse 1), which darkens the WHOLE Psalter. That mis-calibration is meaningless
 * for the question Phase 3b actually asks, because Phase 3b renders an ENGLISH
 * public-domain target (ENGWEBP) for ENGLISH-Protestant-sourced churches: two
 * English versions number the Psalter identically.
 *
 * Phase 3b makes versification a RELATION over the ordered pair (source-family →
 * target translation), owned here. {@see RefValidator} stays pure-structural and
 * keeps its existing `inlineEligible()` as a NECESSARY pre-filter (L1–L3) only.
 *
 * ## The spine: never-fail-WRONG
 *
 * Falling open (eligible:false → the 3a link) is always free; a false-positive
 * inline (real-but-WRONG WEB words shown to a congregation) is the only
 * unacceptable outcome. Therefore EVERY uncertain path returns eligible:false with
 * a DISTINCT, documented reason code, and a ref clears the gate only when the
 * (source, target, book, chapter) tuple is PROVABLY aligned. The divergent-zone
 * table is an explicit, auditable constant (it can only grow deliberately) — never
 * a heuristic.
 *
 * ## Purity
 *
 * Every method is pure: no I/O, no WordPress, no exceptions, deterministic. The
 * impure {@see \Sermonator\Frontend\BibleResolver} calls it; the render path never
 * touches it directly (Renderer stays pure).
 */
final class VersificationGate {
    /**
     * L4 — the source `srcVersification` did not normalize to any modeled
     * versification family (foreign tradition, e.g. Spanish Reina-Valera or an
     * LXX/Vulgate-numbered canon, or a blank/unrecognized code). We cannot reason
     * about its alignment with the English target, so fall open.
     */
    public const REASON_SRC_UNSUPPORTED = 'src-versification-unsupported';

    /**
     * L5 — the ordered (source-family → target) pair is not modeled: either the
     * inline target is not an enumerated/audited target, or the source family has
     * no divergent-zone table against it. Counted DISTINCTLY so a green inline can
     * never hide a mis-versification (a nonzero count is direct proof the modeled
     * set is incomplete; see the §3.9 corpus gate).
     */
    public const REASON_UNMODELED_PAIR = 'unmodeled-versification-pair';

    /**
     * L6 — the ref's versification provenance is the site-wide stamp
     * (`srcVersificationConfidence == site-default`, i.e. backfill/absent) and the
     * admin has NOT affirmed that all references use one English-tradition version
     * (the {@see \Sermonator\Schema\Identifiers::OPTION_BIBLE_INLINE_ATTESTATION}).
     * `authored` refs (stamped contemporaneously at save) skip this gate.
     */
    public const REASON_UNATTESTED = 'src-versification-unattested';

    /**
     * L7 — the (book, chapter) falls inside the modeled pair's enumerated
     * divergent zone, where the source and target genuinely RENUMBER, so the same
     * "Book chapter:verse" addresses different words.
     */
    public const REASON_DIVERGENT = 'versification-divergent';

    /**
     * The single modeled inline TARGET translation → its versification family.
     *
     * Phase 3b ships exactly ONE audited inline target: ENGWEBP (the public-domain
     * World English Bible), which belongs to the modern English-Protestant family.
     * ENGKJV and BSB are deliberately absent (unaudited / license-ambiguous) so a
     * pair into them is `unmodeled-versification-pair`.
     *
     * @var array<string,string>
     */
    private const TARGET_FAMILIES = array(
        BibleTranslations::DEFAULT_INLINE => BibleTranslations::FAMILY_ENGLISH_PROTESTANT,
    );

    /**
     * The re-derived ENGLISH↔ENGLISH (modern-critical ESV/NIV/NASB/NKJV ↔ WEB)
     * versification-divergent zones — the GENUINE renumber points between two
     * English-Protestant versions, authored SPECIFICALLY for the ESV/English-vs-WEB
     * pair. Value `'*'` = the whole book is divergent.
     *
     * ## Provenance (auditable; can only grow)
     *
     * These are verse-DIVISION / boundary renumbers, where the same "Book ch:verse"
     * addresses DIFFERENT words across the two English traditions (WEB carries the
     * Byzantine-Majority basis; ESV/NIV/NASB the NA/UBS critical text):
     *
     *  - ROM 16       : the 16:25-27 doxology is RELOCATED across editions (placed
     *                   after 14:23 in part of the manuscript tradition) and 16:24
     *                   is present in WEB / absent in the critical text — so a
     *                   "Romans 16:24/25" number can address different words.
     *  - 2CO 13       : the closing verses are DIVIDED differently — the grace
     *                   benediction is 13:14 in WEB but 13:13 where 12-13 are
     *                   combined — so 13:12/13/14 shift by one across editions.
     *  - 3JN '*'      : single chapter; the v14/15 split differs across editions
     *                   (14 vs 15 verses), shifting the tail of the letter.
     *  - REV 12,13    : the "he stood on the sand of the sea" clause is 12:18 in the
     *                   critical text but 13:1 in WEB, shifting all of Rev 13 by one.
     *  - ACT 19       : 19:40 is one long verse in the critical text but split 40-41
     *                   in WEB, shifting the chapter's tail.
     *
     * ## Deliberately EXCLUDED (the headline correction vs 3a)
     *
     * The following 3a zones are HEBREW↔English artefacts, NOT English↔English, so
     * they are OMITTED here (both ESV and WEB are English and agree):
     *
     *  - PSA  : Hebrew superscriptions counted as verse 1 / the Ps 9+10 merge are a
     *           Hebrew-vs-English offset. Two English versions number the Psalter
     *           identically → the Psalter is inline-ELIGIBLE for this pair.
     *  - JOL  : the 2/3 boundary (Joel 2:28-32 == Hebrew 3:1-5) is Hebrew-vs-English;
     *           both ESV and WEB use the 3-chapter English scheme.
     *  - MAL  : the 3/4 split is Hebrew-vs-English; both English versions use 4
     *           chapters.
     *
     * Pure critical-text OMISSION gaps (Acts 8:37, 15:34, 24:7, 28:29; the Markan /
     * Matthean "missing verses") are intentionally NOT here either: those are GAPs,
     * not renumbers, and are caught at render time by L9
     * ({@see RefValidator::rangeWithinChapter()}) — when the WEB chapter genuinely
     * carries the verse, rendering its words is correct; when it does not, the whole
     * ref fails open. L7 owns RENUMBERS; L9 owns presence (design §2).
     *
     * @var array<string,string|list<int>>
     */
    private const ENG_ESV_WEB_DIVERGENT_ZONES = array(
        'ROM' => array( 16 ),
        '2CO' => array( 13 ),
        '3JN' => '*',
        'REV' => array( 12, 13 ),
        'ACT' => array( 19 ),
    );

    /**
     * Normalize a source `srcVersification` (an axis-A link-version code) to a
     * versification FAMILY code, or '' when it belongs to no modeled tradition.
     *
     * The gate OWNS the (source-family → target) relation but DELEGATES the family
     * normalization to {@see BibleTranslations::familyCode()} (case-folding, UK
     * suffix stripping, the English-Protestant alias set) so the alias table has a
     * single home. This is the L4 primitive: '' means `src-versification-unsupported`.
     */
    public static function familyCode( string $srcVersification ): string {
        return BibleTranslations::familyCode( $srcVersification );
    }

    /**
     * L5 — may a source FAMILY be rendered into a target translation at all?
     *
     * Passes ONLY when the ordered pair is MODELED, i.e. we own an enumerated
     * divergent-zone table for it. That is true when EITHER (a) the source family
     * equals the target's family by construction (the modern English-Protestant
     * source → the English-Protestant ENGWEBP target — the family owns the
     * re-derived {@see self::ENG_ESV_WEB_DIVERGENT_ZONES}), OR (b) the pair is an
     * explicit cross-family modeled entry (none today). Anything else returns false
     * → `unmodeled-versification-pair`.
     *
     * Unifying L5 with the L7 data source — a pair is eligible ONLY when a divergent
     * table exists for it — is the never-fail-wrong guarantee: we never declare a
     * pair "safe" without the very table L7 needs to police it.
     */
    public static function inlineEligibleForPair( string $srcFamily, string $targetTranslation ): bool {
        return null !== self::divergentZonesForPair( $srcFamily, $targetTranslation );
    }

    /**
     * The top-level L4→L7 predicate: may this ref render inline verse text for the
     * given target, or must it fall open to the 3a link?
     *
     * Layers are evaluated in order and the FIRST failure wins, so the returned
     * reason is the most-conservative cause (and the audit counters stay distinct).
     * The pure structural pre-filter (L1–L3) and the render-time confirmation
     * (L8–L9) live elsewhere; this method owns ONLY the versification relation.
     *
     * @param array<string,mixed> $ref              A decoded envelope ref; reads
     *                                               `srcVersification`,
     *                                               `srcVersificationConfidence`,
     *                                               `bookUSFM`, `chapterStart`.
     * @param string              $targetTranslation The inline target id (e.g. ENGWEBP).
     * @param bool                $attested          Whether the admin attestation
     *                                               (L6) is set for the site.
     *
     * @return array{eligible:bool,reason:?string} `reason` is null iff eligible.
     */
    public static function eligible( array $ref, string $targetTranslation, bool $attested ): array {
        // L4 — source versification must normalize to a modeled family.
        $srcFamily = self::familyCode( self::srcVersification( $ref ) );
        if ( '' === $srcFamily ) {
            return self::fail( self::REASON_SRC_UNSUPPORTED );
        }

        // L5 — the ordered (source-family → target) pair must be modeled.
        $zones = self::divergentZonesForPair( $srcFamily, $targetTranslation );
        if ( null === $zones ) {
            return self::fail( self::REASON_UNMODELED_PAIR );
        }

        // L6 — site-default provenance needs the admin attestation; authored skips it.
        $confidence = RefsCapture::srcVersificationConfidence( $ref );
        if ( RefsCapture::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT === $confidence && ! $attested ) {
            return self::fail( self::REASON_UNATTESTED );
        }

        // L7 — (book, chapter) must sit OUTSIDE the modeled pair's divergent zones.
        $book    = isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '';
        $chapter = isset( $ref['chapterStart'] ) ? (int) $ref['chapterStart'] : 0;
        if ( self::inZone( $zones, $book, $chapter ) ) {
            return self::fail( self::REASON_DIVERGENT );
        }

        return array( 'eligible' => true, 'reason' => null );
    }

    /**
     * The enumerated divergent-zone table for an ordered (srcFamily →
     * targetTranslation) pair, or null when the pair is UNMODELED.
     *
     * @return array<string,string|list<int>>|null
     */
    private static function divergentZonesForPair( string $srcFamily, string $targetTranslation ): ?array {
        if ( '' === $srcFamily ) {
            return null;
        }

        $targetFamily = self::TARGET_FAMILIES[ $targetTranslation ] ?? '';
        if ( '' === $targetFamily ) {
            // The target is not a modeled (enumerated, audited) inline target.
            return null;
        }

        // (a) Same family by construction: the source and the inline target share a
        //     versification family, so the only residual divergences are the genuine
        //     intra-family renumber points enumerated for that family↔target.
        if ( $srcFamily === $targetFamily ) {
            return self::sameFamilyZones( $targetFamily, $targetTranslation );
        }

        // (b) Explicit cross-family modeled pairs would be looked up here. None are
        //     modeled today (a cross-family pair without its OWN audited renumber
        //     table must stay unmodeled — never-fail-wrong).
        return null;
    }

    /**
     * The divergent-zone table for the single modeled SAME-FAMILY relationship: any
     * modern English-Protestant source rendered into the public-domain ENGWEBP. Any
     * other (family, target) same-family combination is intentionally unmodeled
     * (returns null) until its own renumber table is authored.
     *
     * @return array<string,string|list<int>>|null
     */
    private static function sameFamilyZones( string $family, string $targetTranslation ): ?array {
        if ( BibleTranslations::FAMILY_ENGLISH_PROTESTANT === $family
            && BibleTranslations::DEFAULT_INLINE === $targetTranslation ) {
            return self::ENG_ESV_WEB_DIVERGENT_ZONES;
        }

        return null;
    }

    /**
     * Does (book, chapter) fall inside a divergent-zone table? Mirrors
     * {@see RefValidator::isVersificationDivergent()} but over the pair-specific
     * table rather than the 3a unary constant.
     *
     * @param array<string,string|list<int>> $zones
     */
    private static function inZone( array $zones, string $bookUSFM, int $chapter ): bool {
        if ( ! isset( $zones[ $bookUSFM ] ) ) {
            return false;
        }

        $zone = $zones[ $bookUSFM ];
        if ( '*' === $zone ) {
            return true;
        }

        return is_array( $zone ) && in_array( $chapter, $zone, true );
    }

    /**
     * The re-derived English↔English divergent-zone table, exposed for auditing and
     * tests (the provenance is documented on {@see self::ENG_ESV_WEB_DIVERGENT_ZONES}).
     *
     * @return array<string,string|list<int>>
     */
    public static function divergentZones(): array {
        return self::ENG_ESV_WEB_DIVERGENT_ZONES;
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
     * @return array{eligible:bool,reason:string}
     */
    private static function fail( string $reason ): array {
        return array( 'eligible' => false, 'reason' => $reason );
    }
}
