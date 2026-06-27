<?php

declare(strict_types=1);

namespace Sermonator\Bible;

use Sermonator\Schema\BibleBookMap;

/**
 * Pure, versification-aware validator for a parsed Ref (see {@see ReferenceParser}).
 *
 * It answers three independent questions and never throws:
 *   - inCanon          : is bookUSFM one of the 66 canonical codes?
 *   - structurallyValid: positive chapter, sane (ascending) verse/chapter ranges?
 *   - inlineEligible   : may we render verse TEXT inline, or only a link?
 *
 * `inlineEligible` is deliberately conservative — it embodies the bundle's #1
 * standard, "never fail-*wrong*". Inline verse text is withheld (a link is shown
 * instead) whenever showing real-but-wrong words is possible, namely for:
 *   1. chapter-only refs (no specific verse to render),
 *   2. cross-chapter ranges (chapterEnd set; spans a chapter break),
 *   3. references inside an enumerated versification-divergent zone, where the
 *      church's source versification (legacy ESV-family) and the public-domain
 *      inline text (WEB/ENGWEBP) disagree on verse/chapter boundaries.
 *
 * The divergent zones are an explicit, documented constant ({@see DIVERGENT_ZONES})
 * rather than a heuristic, so the set is auditable and can only grow deliberately.
 */
final class RefValidator {
    /**
     * Enumerated (book => chapters) zones where ESV-family and WEB-family
     * versifications diverge, so the same "Book chapter:verse" can address
     * different words. Value `'*'` = the whole book is divergent.
     *
     *  - PSA '*'       : Hebrew superscriptions are counted as verse 1 in many
     *                    psalms (verse-number offset), AND the Hebrew merge of
     *                    Ps 9+10 shifts every subsequent psalm NUMBER by one in
     *                    some versifications. Both make the whole Psalter unsafe
     *                    to render inline; covers the spec's "Ps 9/10" case.
     *  - JOL 2,3       : English Joel 2:28-32 == Hebrew 3:1-5; the 2/3 boundary
     *                    diverges (English has 3 chapters, Hebrew 4).
     *  - MAL 3,4       : English Malachi splits Hebrew chapter 3 into 3 + 4.
     *  - ACT 8,15,24,28: critical-text omitted verses (8:37, 15:34, 24:7, 28:29)
     *                    create verse-number gaps between editions.
     *  - ROM 16        : Rom 16:24 is omitted in critical texts and the 16:25-27
     *                    doxology is placed differently across editions.
     *  - 3JN '*'       : 3 John (single chapter) splits/merges verses 14-15
     *                    differently across versifications.
     *  - REV 12,13     : the Rev 12:18 / 13:1 boundary diverges across editions.
     *
     * @var array<string,string|list<int>>
     */
    private const DIVERGENT_ZONES = array(
        'PSA' => '*',
        'JOL' => array( 2, 3 ),
        'MAL' => array( 3, 4 ),
        'ACT' => array( 8, 15, 24, 28 ),
        'ROM' => array( 16 ),
        '3JN' => '*',
        'REV' => array( 12, 13 ),
    );

    /**
     * Validate a Ref into render-readiness flags.
     *
     * @param array{bookUSFM?:string,chapterStart?:int,verseStart?:?int,verseEnd?:?int,chapterEnd?:?int} $ref
     *
     * @return array{inCanon:bool,structurallyValid:bool,inlineEligible:bool}
     */
    public static function validate( array $ref ): array {
        $book         = $ref['bookUSFM'] ?? '';
        $chapterStart = $ref['chapterStart'] ?? 0;
        $verseStart   = $ref['verseStart'] ?? null;
        $verseEnd     = $ref['verseEnd'] ?? null;
        $chapterEnd   = $ref['chapterEnd'] ?? null;

        $inCanon           = self::isInCanon( $book );
        $structurallyValid = self::isStructurallyValid( $chapterStart, $verseStart, $verseEnd, $chapterEnd );

        $inlineEligible = $inCanon
            && $structurallyValid
            && null !== $verseStart                 // not chapter-only
            && null === $chapterEnd                 // not a cross-chapter range
            && ! self::isVersificationDivergent( $book, $chapterStart );

        return array(
            'inCanon'           => $inCanon,
            'structurallyValid' => $structurallyValid,
            'inlineEligible'    => $inlineEligible,
        );
    }

    /**
     * Is the book one of the 66 canonical USFM codes?
     */
    public static function isInCanon( string $bookUSFM ): bool {
        return in_array( $bookUSFM, array_values( BibleBookMap::usfm() ), true );
    }

    /**
     * Does (book, chapter) fall inside a documented versification-divergent zone?
     */
    public static function isVersificationDivergent( string $bookUSFM, int $chapter ): bool {
        if ( ! isset( self::DIVERGENT_ZONES[ $bookUSFM ] ) ) {
            return false;
        }

        $zone = self::DIVERGENT_ZONES[ $bookUSFM ];

        if ( '*' === $zone ) {
            return true;
        }

        return in_array( $chapter, $zone, true );
    }

    /**
     * The documented divergent-zone table (exposed for auditing/tests).
     *
     * @return array<string,string|list<int>>
     */
    public static function divergentZones(): array {
        return self::DIVERGENT_ZONES;
    }

    /**
     * Render-time confirmation (spec L9): is EVERY verse number in the ref's
     * verseStart..verseEnd span physically present in the fetched, normalized
     * chapter?
     *
     * $chapter is the normalized flat shape produced by ChapterNormalizer:
     * a list of `{number:int, nodes:[...]}` entries, one per verse the public
     * text actually contains. This is a pure presence check with NO I/O.
     *
     * It is the final fail-open valve of the never-fail-WRONG spine, catching
     * two failure modes that the pure pre-filter cannot see without chapter data:
     *   1. OUT-OF-RANGE ends — a ref whose verseEnd exceeds the chapter's last
     *      verse (e.g. asking for v25 of a 23-verse chapter).
     *   2. Critical-text verse-number GAPS — public-domain editions (WEB/ENGWEBP)
     *      omit verses present in the source versification (e.g. a missing
     *      Matthew 16:24-style omission), leaving a hole in the numbering.
     *
     * A SINGLE missing verse fails the WHOLE ref (returns false), so the caller
     * falls open to the 3a link rather than rendering a partial range that would
     * silently imply a skipped verse. Presence is NOT correspondence: this proves
     * the numbers exist, not that they map to the same words — RENUMBER shifts are
     * the versification gate's job, not this method's.
     *
     * Conservative by construction: a chapter-only ref (no verseStart) or a
     * cross-chapter ref (chapterEnd set, which cannot be confirmed against a
     * single chapter) returns false — withhold inline, show the link.
     *
     * @param array{verseStart?:?int,verseEnd?:?int,chapterEnd?:?int} $ref
     * @param list<array{number?:int}>                                $chapter Normalized flat chapter: one entry per present verse.
     */
    public static function rangeWithinChapter( array $ref, array $chapter ): bool {
        $verseStart = $ref['verseStart'] ?? null;
        $verseEnd   = $ref['verseEnd'] ?? null;
        $chapterEnd = $ref['chapterEnd'] ?? null;

        // No specific verse, or a cross-chapter span that a single chapter cannot
        // possibly confirm: fail open to the link.
        if ( null === $verseStart || null !== $chapterEnd ) {
            return false;
        }

        // A single verse when verseEnd is absent; never widen the span.
        $end = $verseEnd ?? $verseStart;

        // A descending span is meaningless to confirm: fail open.
        if ( $end < $verseStart ) {
            return false;
        }

        $present = self::presentVerseNumbers( $chapter );

        for ( $verse = $verseStart; $verse <= $end; $verse++ ) {
            if ( ! isset( $present[ $verse ] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collect the set of verse numbers physically present in a normalized chapter.
     * Returned as an int-keyed map for O(1) membership tests.
     *
     * @param list<array{number?:int}> $chapter
     *
     * @return array<int,true>
     */
    private static function presentVerseNumbers( array $chapter ): array {
        $present = array();

        foreach ( $chapter as $entry ) {
            if ( is_array( $entry ) && isset( $entry['number'] ) && is_int( $entry['number'] ) ) {
                $present[ $entry['number'] ] = true;
            }
        }

        return $present;
    }

    private static function isStructurallyValid( int $chapterStart, ?int $verseStart, ?int $verseEnd, ?int $chapterEnd ): bool {
        if ( $chapterStart < 1 ) {
            return false;
        }

        if ( null !== $verseStart && $verseStart < 1 ) {
            return false;
        }

        // An end-verse with no start-verse is always malformed.
        if ( null !== $verseEnd && null === $verseStart ) {
            return false;
        }

        // The verseEnd < verseStart guard only applies WITHIN a single chapter.
        // For a cross-chapter range (chapterEnd set), verseEnd belongs to chapterEnd
        // — a different chapter — and verse numbers reset each chapter, so a smaller
        // end-verse (e.g. John 7:53-8:11) is legitimate. Cross-chapter ordering is
        // covered by the chapterEnd < chapterStart check below.
        if ( null !== $verseEnd && null === $chapterEnd && $verseEnd < $verseStart ) {
            return false;
        }

        if ( null !== $chapterEnd && $chapterEnd < $chapterStart ) {
            return false;
        }

        return true;
    }
}
