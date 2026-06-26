<?php

declare(strict_types=1);

namespace Sermonator\Bible;

use Sermonator\Schema\BibleBookMap;

/**
 * Pure scripture-reference parser. Turns a free-text passage label (the preserved,
 * human-authored {@see \Sermonator\Schema\Identifiers::META_BIBLE_PASSAGE}) into a
 * structured, lossless set of refs WITHOUT any I/O, exceptions, or writes.
 *
 * Contract:
 *   parse(string $raw): array{segments: list<Segment>}
 *   Segment = array{raw:string, status:'matched'|'fallback', refs:list<Ref>}
 *   Ref     = array{
 *       bookUSFM:string, chapterStart:int, verseStart:?int,
 *       verseEnd:?int, chapterEnd:?int, raw:string
 *   }
 *
 * The SEGMENT is the never-fail-wrong unit: a passage is split on top-level
 * separators (',', ';', '&', ' and '), and each segment is resolved independently.
 * An unrecognized segment becomes a `fallback` segment that keeps its raw text and
 * carries no ref, so the worst case for any fragment is "shown as plain text" —
 * never a wrong reference. Recognized books/chapters are carried forward across
 * segments so "Ps 23 & 24" and "John 3:16, 18" resolve like a human reads them.
 *
 * Out of scope (by design): non-English book names, prose, and fuzzy/typo matching.
 *
 * @phpstan-type Ref array{bookUSFM:string,chapterStart:int,verseStart:?int,verseEnd:?int,chapterEnd:?int,raw:string}
 * @phpstan-type Segment array{raw:string,status:'matched'|'fallback',refs:list<Ref>}
 */
final class ReferenceParser {
    /**
     * Leading ordinal forms (word / Roman / suffixed) collapsed to a digit so that
     * "I John", "First John", "1st John" and "1 John" all normalize identically and
     * line up with the digit-prefixed alias keys in {@see BibleBookMap::aliases()}.
     * Ordered longest-first per numeral so "iii" wins over "ii"/"i" in the regex.
     *
     * @var array<string,string>
     */
    private const ORDINALS = array(
        '1st'    => '1',
        '2nd'    => '2',
        '3rd'    => '3',
        'first'  => '1',
        'second' => '2',
        'third'  => '3',
        'iii'    => '3',
        'ii'     => '2',
        'i'      => '1',
    );

    /**
     * Parse a raw passage label into structured segments.
     *
     * @return array{segments:list<array{raw:string,status:string,refs:list<array<string,mixed>>}>}
     */
    public static function parse( string $raw ): array {
        $segments = array();

        // State carried forward across segments so continuation fragments resolve.
        $currentBook     = null; // ?string USFM code
        $currentChapter  = null; // ?int
        $lastRefHadVerse = false;

        foreach ( self::splitSegments( $raw ) as $segmentRaw ) {
            $normalized = self::normalize( $segmentRaw );

            // 1) Longest-match book lookup against the curated map. A real book at
            //    the head of the segment resets the carry-over context.
            list( $book, $remainder ) = self::matchBook( $normalized );

            if ( null !== $book ) {
                $ref = self::parseNumericTail( $book, $remainder, false, null );

                if ( null === $ref ) {
                    // Book matched but no usable chapter (e.g. a bare book name): we
                    // cannot form a linkable reference, so it survives as plain text.
                    $segments[] = self::fallbackSegment( $segmentRaw );
                    continue;
                }

                $ref['raw'] = $segmentRaw;
                $segments[] = array(
                    'raw'    => $segmentRaw,
                    'status' => 'matched',
                    'refs'   => array( $ref ),
                );

                $currentBook     = $book;
                $currentChapter  = $ref['chapterEnd'] ?? $ref['chapterStart'];
                $lastRefHadVerse = ( null !== $ref['verseStart'] );
                continue;
            }

            // 2) No book at the head: only a purely-numeric continuation can resolve,
            //    and only when a prior segment established the current book.
            if ( null !== $currentBook && self::isNumericTail( $normalized ) ) {
                $ref = self::parseNumericTail( $currentBook, $normalized, $lastRefHadVerse, $currentChapter );

                if ( null !== $ref ) {
                    $ref['raw'] = $segmentRaw;
                    $segments[] = array(
                        'raw'    => $segmentRaw,
                        'status' => 'matched',
                        'refs'   => array( $ref ),
                    );

                    $currentChapter  = $ref['chapterEnd'] ?? $ref['chapterStart'];
                    $lastRefHadVerse = ( null !== $ref['verseStart'] );
                    continue;
                }
            }

            // 3) Never-fail-wrong: anything else is kept verbatim, no ref attached.
            $segments[] = self::fallbackSegment( $segmentRaw );
        }

        return array( 'segments' => $segments );
    }

    /**
     * @return array{raw:string,status:string,refs:list<array<string,mixed>>}
     */
    private static function fallbackSegment( string $segmentRaw ): array {
        return array(
            'raw'    => $segmentRaw,
            'status' => 'fallback',
            'refs'   => array(),
        );
    }

    /**
     * Split the raw passage on top-level separators, preserving each segment's
     * original (trimmed) text. Empty pieces are dropped.
     *
     * @return list<string>
     */
    private static function splitSegments( string $raw ): array {
        $parts = preg_split( '/\s*[,;&]\s*|\s+and\s+/i', $raw );

        if ( false === $parts ) {
            return array();
        }

        $segments = array();
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( '' !== $part ) {
                $segments[] = $part;
            }
        }

        return $segments;
    }

    /**
     * Build a working copy of a segment for matching only (the raw is preserved
     * separately): lowercase, dashes unified to '-', chapter.verse dots turned into
     * ':', abbreviation dots stripped, and a leading ordinal collapsed to a digit.
     */
    private static function normalize( string $segment ): string {
        $s = $segment;

        // En/em/figure dashes + minus sign -> ASCII hyphen.
        $s = str_replace(
            array( "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}" ),
            '-',
            $s
        );

        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );

        // Keep digit.digit as a chapter:verse separator; drop every other dot
        // (abbreviation dots after letters: "gen." -> "gen").
        $s = preg_replace( '/(?<=\d)\.(?=\d)/', ':', $s ) ?? $s;
        $s = str_replace( '.', '', $s );

        // Collapse internal whitespace.
        $s = trim( preg_replace( '/\s+/', ' ', $s ) ?? $s );

        // Leading ordinal -> digit (so "i"/"first"/"1st" all become "1 ").
        $s = self::collapseLeadingOrdinal( $s );

        return $s;
    }

    private static function collapseLeadingOrdinal( string $s ): string {
        $pattern = '/^(1st|2nd|3rd|first|second|third|iii|ii|i)\s+/';

        return preg_replace_callback(
            $pattern,
            static function ( array $m ): string {
                return self::ORDINALS[ $m[1] ] . ' ';
            },
            $s
        ) ?? $s;
    }

    /**
     * Longest-match book lookup. Returns [usfm|null, remainder]. The book portion
     * must be followed by a separator boundary (end-of-string, space, or a digit)
     * so "ps" never swallows "psalm" and "phil" never beats "philemon".
     *
     * @return array{0:?string,1:string}
     */
    private static function matchBook( string $normalized ): array {
        $best       = null;
        $bestLength = 0;
        $bestKey    = '';

        foreach ( self::bookLookup() as $key => $usfm ) {
            $len = strlen( $key );
            if ( $len <= $bestLength ) {
                continue;
            }
            if ( 0 !== strncmp( $normalized, $key, $len ) ) {
                continue;
            }

            // Boundary check: the char right after the key (if any) must be a space
            // or a digit — never a letter (which would mean a longer real word).
            $next = $normalized[ $len ] ?? '';
            if ( '' !== $next && ' ' !== $next && ! ctype_digit( $next ) ) {
                continue;
            }

            $best       = $usfm;
            $bestLength = $len;
            $bestKey    = $key;
        }

        if ( null === $best ) {
            return array( null, $normalized );
        }

        $remainder = ltrim( substr( $normalized, strlen( $bestKey ) ) );

        return array( $best, $remainder );
    }

    /**
     * The combined normalized-name + alias lookup table. Display names from
     * {@see BibleBookMap::usfm()} are lowercased so a normalized segment can match
     * either a full name or a curated abbreviation.
     *
     * @return array<string,string>
     */
    private static function bookLookup(): array {
        $lookup = array();

        foreach ( BibleBookMap::usfm() as $name => $usfm ) {
            $lookup[ strtolower( $name ) ] = $usfm;
        }

        // Aliases are already normalized (lowercase, digit-prefixed); they extend
        // but never override a canonical name collision.
        foreach ( BibleBookMap::aliases() as $alias => $usfm ) {
            $lookup[ $alias ] = $usfm;
        }

        return $lookup;
    }

    /**
     * Is the (normalized) string a pure numeric tail the grammar can consume?
     */
    private static function isNumericTail( string $s ): bool {
        return 1 === preg_match( '/^\d+(?::\d+)?(?:-\d+(?::\d+)?)?$/', $s );
    }

    /**
     * Tiny numeric grammar applied to the remainder after a book (or to a bare
     * continuation tail). Supported forms:
     *   chapter                       -> whole chapter
     *   chapter:verse                 -> single verse
     *   chapter:verse-verse           -> verse range in one chapter
     *   chapter:verse-chapter:verse   -> cross-chapter range (chapterEnd set)
     *   chapter-chapter               -> chapter range (chapterEnd set)
     *
     * In a verse-context continuation (the prior ref named a verse) a bare "N" or
     * "N-M" is read as verse(s) within $currentChapter instead of new chapters.
     *
     * @return array{bookUSFM:string,chapterStart:int,verseStart:?int,verseEnd:?int,chapterEnd:?int}|null
     */
    private static function parseNumericTail( string $book, string $rem, bool $verseContext, ?int $currentChapter ): ?array {
        $rem = trim( $rem );

        if ( '' === $rem ) {
            return null;
        }

        // chapter:verse-chapter:verse
        if ( preg_match( '/^(\d+):(\d+)-(\d+):(\d+)$/', $rem, $m ) ) {
            return self::ref( $book, (int) $m[1], (int) $m[2], (int) $m[4], (int) $m[3] );
        }

        // chapter:verse-verse
        if ( preg_match( '/^(\d+):(\d+)-(\d+)$/', $rem, $m ) ) {
            return self::ref( $book, (int) $m[1], (int) $m[2], (int) $m[3], null );
        }

        // chapter:verse
        if ( preg_match( '/^(\d+):(\d+)$/', $rem, $m ) ) {
            return self::ref( $book, (int) $m[1], (int) $m[2], null, null );
        }

        // N-M : verse range in the carried chapter, or a chapter range.
        if ( preg_match( '/^(\d+)-(\d+)$/', $rem, $m ) ) {
            if ( $verseContext && null !== $currentChapter ) {
                return self::ref( $book, $currentChapter, (int) $m[1], (int) $m[2], null );
            }

            return self::ref( $book, (int) $m[1], null, null, (int) $m[2] );
        }

        // N : a single verse in the carried chapter, or a whole chapter.
        if ( preg_match( '/^(\d+)$/', $rem, $m ) ) {
            if ( $verseContext && null !== $currentChapter ) {
                return self::ref( $book, $currentChapter, (int) $m[1], null, null );
            }

            return self::ref( $book, (int) $m[1], null, null, null );
        }

        return null;
    }

    /**
     * @return array{bookUSFM:string,chapterStart:int,verseStart:?int,verseEnd:?int,chapterEnd:?int}
     */
    private static function ref( string $book, int $chapterStart, ?int $verseStart, ?int $verseEnd, ?int $chapterEnd ): array {
        return array(
            'bookUSFM'     => $book,
            'chapterStart' => $chapterStart,
            'verseStart'   => $verseStart,
            'verseEnd'     => $verseEnd,
            'chapterEnd'   => $chapterEnd,
        );
    }
}
