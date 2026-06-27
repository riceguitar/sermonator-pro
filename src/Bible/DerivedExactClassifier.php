<?php

declare(strict_types=1);

namespace Sermonator\Bible;

/**
 * Pure, render-time promotion classifier for the Bible inline L2 (parse-confidence)
 * floor — the single shared decision both lockstep L2 checks call
 * ({@see \Sermonator\Frontend\BibleResolver::confidenceClears()} and
 * {@see CoverageAudit}). It NEVER writes, NEVER reads options/DB/network, and NEVER
 * throws: it is a function of one ref plus that ref's OWN raw text.
 *
 * ## Why render-time, never a stored re-stamp (design §1)
 *
 * The `probable → inline` promotion is computed here at render time, not persisted.
 * That keeps #1 data preservation absolute (zero new writes, instant reversibility —
 * lowering the floor back to `exact` promotes nothing with nothing to undo) and makes
 * the live preview, the resolver, and the corpus audit share ONE classifier (no
 * "preview lies" fork). Crucially, the stored-confidence vocabulary
 * `{exact,probable,ambiguous}` and the floor vocabulary
 * `{exact,derived-exact,derived-exact-perseg}` are **disjoint**: a ref is NEVER stamped
 * `derived-exact`. "derived-exact" is a RENDER-TIME property derived here, so a
 * pre-stamped (smuggled) `derived-exact` confidence clears nothing.
 *
 * ## The predicate — {@see self::isDerivedExact()} (design §2)
 *
 * `isDerivedExact($ref)` is true ONLY when ALL hold:
 *   1. L1-shaped — a concrete in-chapter verse/range: `verseStart !== null` AND
 *      `chapterEnd === null` (never chapter-only, never cross-chapter).
 *   2. the ref carries a non-empty own `raw` (missing/empty → false).
 *   3. RE-PARSE-IDENTITY — re-parsing `$ref['raw']` IN ISOLATION through the pure
 *      {@see ReferenceParser::parse()} yields exactly ONE `matched` segment carrying
 *      exactly ONE ref whose `bookUSFM/chapterStart/verseStart/verseEnd/chapterEnd` are
 *      structurally identical to the stored ref. A fallback segment, ≠1 segment, ≠1 ref,
 *      or ANY structural mismatch → false (conservative; never-fail-WRONG).
 *
 * It is **per-ref by construction**: it never inspects siblings, so a carry-over
 * continuation — the bare `18` in `John 3:16, 18`, or `5:1-11` in
 * `Isaiah 6:1-13; Luke 5:1-11` — re-parsed alone has no book at its head → fallback →
 * false → stays `probable` → falls open to a 3a link. This pins the carry-over safety
 * (otherwise emergent parser behavior) into a hard contract.
 *
 * ## The policy — {@see self::promotes()} (design §2)
 *
 * `promotes($ref, $floor, $refCountInEnvelope)` is the single shared promotion rule:
 *   - `exact`                → false (the conservative default; nothing promotes);
 *   - `derived-exact` (STRICT) → `$refCountInEnvelope === 1 && isDerivedExact($ref)` —
 *      a compound passage's segments are NEVER promoted even when individually clean
 *      (the corpus-independent dark guarantee);
 *   - `derived-exact-perseg` → `isDerivedExact($ref)` regardless of sibling count.
 *
 * The axis-1 {@see VersificationGate} (L4–L7) and {@see RefValidator::rangeWithinChapter}
 * (L9) STILL run unchanged AFTER promotion and independently withhold any
 * wrong-versification ref — promotion only lets a ref REACH that unchanged gate; it can
 * never surface a wrong verse.
 */
final class DerivedExactClassifier {
    /** The conservative default floor: nothing is promoted. */
    public const FLOOR_EXACT = 'exact';

    /** STRICT single-segment floor: only a lone, clean in-chapter ref promotes. */
    public const FLOOR_DERIVED_EXACT = 'derived-exact';

    /** Per-segment floor: every clean in-chapter ref promotes, sibling count aside. */
    public const FLOOR_DERIVED_EXACT_PERSEG = 'derived-exact-perseg';

    /**
     * Per-request memo of the isolated re-parse IDENTITY of a raw string: the normalized
     * 5-tuple of the single matched ref `$raw` re-parses to, or null when `$raw` does NOT
     * re-parse to exactly one matched single ref. Keyed on the raw so a scripture-dense
     * liturgical page re-parses each UNIQUE raw at most once.
     *
     * It memoizes only the (deterministic, pure) re-parse — NOT the boolean — so two
     * stored refs sharing a raw but differing structurally are still compared individually
     * against the same cached identity and get the correct (possibly different) result.
     *
     * @var array<string,array{book:string,cs:?int,vs:?int,ve:?int,ce:?int}|null>
     */
    private static array $reparseMemo = array();

    /**
     * The render-time `derived-exact` predicate (design §2). True ONLY for an L1-shaped,
     * own-raw-bearing ref whose raw, re-parsed in isolation, is structurally identical to
     * the stored ref. See the class docblock for the full contract.
     *
     * @param array<string,mixed> $ref
     */
    public static function isDerivedExact( array $ref ): bool {
        // (1) L1-shaped: a concrete in-chapter verse/range — never chapter-only, never
        //     cross-chapter. (absent verseStart or set chapterEnd → not derived-exact.)
        $verseStart = $ref['verseStart'] ?? null;
        $chapterEnd = $ref['chapterEnd'] ?? null;
        if ( null === $verseStart || null !== $chapterEnd ) {
            return false;
        }

        // (2) own raw must be present and non-empty.
        $raw = isset( $ref['raw'] ) && is_string( $ref['raw'] ) ? $ref['raw'] : '';
        if ( '' === $raw ) {
            return false;
        }

        // (3) re-parse-identity: the raw, re-parsed ALONE, must be exactly one matched
        //     single ref structurally identical to the stored ref. A continuation whose
        //     book lived in a sibling segment re-parses to a fallback here → null → false.
        $identity = self::reparseIdentity( $raw );
        if ( null === $identity ) {
            return false;
        }

        return $identity === self::tuple( $ref );
    }

    /**
     * The single shared promotion rule (design §2). Returns whether a ref is promoted
     * from `probable` to inline-eligible under the configured floor and the ref's envelope
     * sibling count. See the class docblock for the per-floor semantics.
     *
     * @param array<string,mixed> $ref
     * @param string              $floor               One of `exact` | `derived-exact` | `derived-exact-perseg`.
     * @param int                 $refCountInEnvelope  Number of refs in the SAME post's envelope (the singleton constraint for STRICT).
     */
    public static function promotes( array $ref, string $floor, int $refCountInEnvelope ): bool {
        switch ( $floor ) {
            case self::FLOOR_DERIVED_EXACT:
                // STRICT: a compound passage's segments NEVER promote, even if each is
                // individually clean — the one corpus-independent safety property.
                return 1 === $refCountInEnvelope && self::isDerivedExact( $ref );

            case self::FLOOR_DERIVED_EXACT_PERSEG:
                return self::isDerivedExact( $ref );

            case self::FLOOR_EXACT:
            default:
                // `exact` (and any unknown floor) promotes nothing — most conservative.
                return false;
        }
    }

    /**
     * The memoized isolated re-parse identity of a raw string, or null when it does not
     * re-parse to exactly one matched single ref. Pure + deterministic, so the memo never
     * goes stale.
     *
     * @return array{book:string,cs:?int,vs:?int,ve:?int,ce:?int}|null
     */
    private static function reparseIdentity( string $raw ): ?array {
        if ( array_key_exists( $raw, self::$reparseMemo ) ) {
            return self::$reparseMemo[ $raw ];
        }

        $identity = self::computeReparseIdentity( $raw );

        self::$reparseMemo[ $raw ] = $identity;

        return $identity;
    }

    /**
     * Re-parse a raw in isolation and return the normalized 5-tuple of its single matched
     * ref, or null unless the parse is exactly ONE `matched` segment carrying exactly ONE
     * ref. Anything else — a fallback (e.g. a bookless continuation), multiple segments
     * (a stray separator), or multiple refs — is conservatively null.
     *
     * @return array{book:string,cs:?int,vs:?int,ve:?int,ce:?int}|null
     */
    private static function computeReparseIdentity( string $raw ): ?array {
        $segments = ReferenceParser::parse( $raw )['segments'];

        if ( 1 !== count( $segments ) ) {
            return null;
        }

        $segment = $segments[0];
        if ( ! is_array( $segment ) || 'matched' !== ( $segment['status'] ?? '' ) ) {
            return null;
        }

        $refs = $segment['refs'] ?? array();
        if ( ! is_array( $refs ) || 1 !== count( $refs ) || ! is_array( $refs[0] ) ) {
            return null;
        }

        return self::tuple( $refs[0] );
    }

    /**
     * The structural identity 5-tuple of a ref, normalized so a stored ref and a freshly
     * re-parsed ref compare with strict `===`: book to a string, the four positional
     * fields to int-or-null. Extra keys (`raw`, `confidence`, `srcVersification`, …) are
     * deliberately ignored — identity is purely the parse SHAPE.
     *
     * @param array<string,mixed> $ref
     *
     * @return array{book:string,cs:?int,vs:?int,ve:?int,ce:?int}
     */
    private static function tuple( array $ref ): array {
        return array(
            'book' => isset( $ref['bookUSFM'] ) && is_string( $ref['bookUSFM'] ) ? $ref['bookUSFM'] : '',
            'cs'   => self::intOrNull( $ref['chapterStart'] ?? null ),
            'vs'   => self::intOrNull( $ref['verseStart'] ?? null ),
            've'   => self::intOrNull( $ref['verseEnd'] ?? null ),
            'ce'   => self::intOrNull( $ref['chapterEnd'] ?? null ),
        );
    }

    /**
     * Normalize a positional ref field to int-or-null: a real integer (or integer-valued
     * numeric) becomes int; null/absent/non-integer becomes null. This keeps a stored
     * JSON value (int) and a parser-produced value (int) byte-comparable while refusing to
     * coerce a non-integer string into a false match.
     *
     * @param mixed $value
     */
    private static function intOrNull( $value ): ?int {
        if ( null === $value ) {
            return null;
        }

        if ( is_int( $value ) ) {
            return $value;
        }

        return null;
    }
}
