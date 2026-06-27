<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Bible;

use Sermonator\Schema\Identifiers as ID;

/**
 * Pure transform of a helloao chapter object into the flat, typed-node render
 * shape consumed downstream — with ZERO I/O and ZERO raw HTML.
 *
 * INPUT (a single helloao chapter object):
 *   {
 *     content:   [ {type:'verse', number:N, content:[ <run>, … ]}, … (+ headings, line_breaks, …) ],
 *     footnotes: [ {noteId:int, text:string}, … ]
 *   }
 * where each verse `<run>` is one of:
 *   - a plain string                       → a text run,
 *   - {text:string, wordsOfJesus?:bool}    → a text run (red-letter if wordsOfJesus),
 *   - {noteId:int}                          → an editorial footnote reference.
 *
 * OUTPUT (the flat render shape — identical to what {@see \Sermonator\Bible\RefValidator::rangeWithinChapter()}
 * presence-checks and what the {@see \Sermonator\Frontend\ResolvedScripture} inline
 * payload carries):
 *   [ {number:int, nodes:[ {type:'text'|'wordsOfJesus'|'note', text:string}, … ]}, … ]
 * — one entry per verse the public text actually contains, each text run its own
 * typed node. The output carries NO raw HTML; the pure {@see \Sermonator\Frontend\Renderer}
 * escapes every leaf and builds its own markup.
 *
 * Design decisions (documented per task):
 *   - NOTE RESOLUTION: a {noteId} run is resolved to a `note` node carrying the
 *     footnote TEXT from the chapter's `footnotes[]`. An unresolvable noteId — no
 *     matching footnote, or an empty footnote body — is DROPPED (never a placeholder
 *     or an empty node), so a missing footnote can never inject stray characters.
 *   - EMPTY TEXT RUNS are dropped (a run whose text is the empty string '').
 *     This transform stays a FAITHFUL, lossless mapping: a verse with zero
 *     renderable nodes (an edition that keeps the verse NUMBER but excises its
 *     words — empty or footnote-only, e.g. WEB John 5:4) is still EMITTED here, so
 *     the normalized shape reflects exactly what the edition contains. The
 *     never-fail-WRONG presence judgement lives downstream, NOT here:
 *     {@see RefValidator::rangeWithinChapter()} counts a verse as present ONLY when
 *     it carries a renderable text/wordsOfJesus node, so an emitted-but-empty verse
 *     fails a crossing range OPEN to the 3a link rather than rendering blank. (This
 *     class never decides presence; it only reports the edition faithfully.)
 *   - NON-VERSE content items (headings, line_breaks, etc.) are ignored — only
 *     `type:'verse'` items become output entries.
 *
 * SCHEMA VERSION: the output structure is versioned by
 * {@see ID::BIBLE_CACHE_SCHEMA_VERSION}, surfaced here via {@see schemaVersion()}.
 * The vendor/cache layers stamp each persisted chapter with this value and fold it
 * into the cache key; bumping the constant whenever this normalized shape changes
 * forces a full re-vendor / cache miss so no stale-shaped chapter is ever served.
 *
 * Pure: never throws. Any malformed / unexpected input yields an empty array.
 */
final class ChapterNormalizer {
    /**
     * The normalization-shape version this class produces. A shape change here
     * MUST be accompanied by a bump of {@see ID::BIBLE_CACHE_SCHEMA_VERSION},
     * which invalidates every vendored snapshot and cached chapter.
     */
    public static function schemaVersion(): int {
        return ID::BIBLE_CACHE_SCHEMA_VERSION;
    }

    /**
     * Normalize a helloao chapter object into the flat typed-node render shape.
     *
     * @param mixed $chapter Raw decoded helloao chapter object (assoc array expected).
     *
     * @return list<array{number:int,nodes:list<array{type:string,text:string}>}>
     *         One entry per verse; empty array on any malformed input.
     */
    public static function normalize( $chapter ): array {
        try {
            if ( ! is_array( $chapter ) ) {
                return array();
            }

            $content = $chapter['content'] ?? null;
            if ( ! is_array( $content ) ) {
                return array();
            }

            $footnotes = self::footnoteMap( $chapter['footnotes'] ?? null );

            $verses = array();

            foreach ( $content as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                if ( ( $item['type'] ?? null ) !== 'verse' ) {
                    continue;
                }

                $number = self::verseNumber( $item['number'] ?? null );
                if ( null === $number ) {
                    continue;
                }

                $verses[] = array(
                    'number' => $number,
                    'nodes'  => self::verseNodes( $item['content'] ?? null, $footnotes ),
                );
            }

            return $verses;
        } catch ( \Throwable $e ) {
            // Pure + never-throws: any unexpected shape falls open to empty,
            // which the resolver treats as chapter-unavailable → 3a link.
            return array();
        }
    }

    /**
     * Build the typed-node list for one verse's content runs.
     *
     * @param mixed             $runs      The verse's `content` array.
     * @param array<int,string> $footnotes noteId => footnote text.
     *
     * @return list<array{type:string,text:string}>
     */
    private static function verseNodes( $runs, array $footnotes ): array {
        if ( ! is_array( $runs ) ) {
            return array();
        }

        $nodes = array();

        foreach ( $runs as $run ) {
            // A bare string run is plain verse text.
            if ( is_string( $run ) ) {
                if ( '' !== $run ) {
                    $nodes[] = array( 'type' => 'text', 'text' => $run );
                }
                continue;
            }

            if ( ! is_array( $run ) ) {
                continue;
            }

            // A footnote reference: resolve to its body, or DROP it entirely.
            if ( array_key_exists( 'noteId', $run ) && ! array_key_exists( 'text', $run ) ) {
                $noteId = $run['noteId'];
                if ( is_int( $noteId ) && isset( $footnotes[ $noteId ] ) && '' !== $footnotes[ $noteId ] ) {
                    $nodes[] = array( 'type' => 'note', 'text' => $footnotes[ $noteId ] );
                }
                continue;
            }

            // A text run, optionally flagged as words of Jesus (red letter).
            if ( array_key_exists( 'text', $run ) && is_string( $run['text'] ) ) {
                $text = $run['text'];
                if ( '' === $text ) {
                    continue;
                }
                $type    = ! empty( $run['wordsOfJesus'] ) ? 'wordsOfJesus' : 'text';
                $nodes[] = array( 'type' => $type, 'text' => $text );
            }
        }

        return $nodes;
    }

    /**
     * Index a chapter's footnotes by noteId for O(1) resolution.
     *
     * @param mixed $footnotes The chapter's `footnotes` array.
     *
     * @return array<int,string> noteId => footnote text (only well-formed entries).
     */
    private static function footnoteMap( $footnotes ): array {
        if ( ! is_array( $footnotes ) ) {
            return array();
        }

        $map = array();

        foreach ( $footnotes as $note ) {
            if ( ! is_array( $note ) ) {
                continue;
            }

            $noteId = $note['noteId'] ?? null;
            $text   = $note['text'] ?? null;

            if ( is_int( $noteId ) && is_string( $text ) ) {
                $map[ $noteId ] = $text;
            }
        }

        return $map;
    }

    /**
     * Coerce a verse number to a positive int, or null if it cannot be placed.
     *
     * @param mixed $number
     */
    private static function verseNumber( $number ): ?int {
        if ( is_int( $number ) ) {
            return $number > 0 ? $number : null;
        }

        if ( is_string( $number ) && ctype_digit( $number ) ) {
            $n = (int) $number;
            return $n > 0 ? $n : null;
        }

        return null;
    }
}
