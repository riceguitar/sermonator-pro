<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Bible;

use PHPUnit\Framework\TestCase;
use Sermonator\Frontend\Bible\ChapterNormalizer;
use Sermonator\Schema\Identifiers as ID;

/**
 * ChapterNormalizer is pure: it folds a helloao chapter object into the flat
 * typed-node render shape with no I/O, no raw HTML, and never throws. The render
 * shape it produces is exactly what RefValidator::rangeWithinChapter() and the
 * ResolvedScripture inline payload consume.
 */
final class ChapterNormalizerTest extends TestCase {
    /**
     * The headline parse test: one verse mixing a plain text run, a wordsOfJesus
     * run, and a footnote reference maps each to its correctly typed node, with
     * the noteId resolved from the chapter footnotes.
     */
    public function test_mixed_text_words_of_jesus_and_note_map_correctly(): void {
        $chapter = array(
            'content'   => array(
                array(
                    'type'    => 'verse',
                    'number'  => 16,
                    'content' => array(
                        array( 'text' => 'For God so loved the world, ' ),
                        array( 'text' => 'that he gave his only Son', 'wordsOfJesus' => true ),
                        array( 'noteId' => 0 ),
                    ),
                ),
            ),
            'footnotes' => array(
                array( 'noteId' => 0, 'text' => 'Or only begotten Son' ),
            ),
        );

        $result = ChapterNormalizer::normalize( $chapter );

        $this->assertSame(
            array(
                array(
                    'number' => 16,
                    'nodes'  => array(
                        array( 'type' => 'text', 'text' => 'For God so loved the world, ' ),
                        array( 'type' => 'wordsOfJesus', 'text' => 'that he gave his only Son' ),
                        array( 'type' => 'note', 'text' => 'Or only begotten Son' ),
                    ),
                ),
            ),
            $result
        );
    }

    public function test_emits_one_entry_per_verse_and_ignores_non_verse_items(): void {
        $chapter = array(
            'content'   => array(
                array( 'type' => 'heading', 'content' => array( 'A Heading' ) ),
                array(
                    'type'    => 'verse',
                    'number'  => 1,
                    'content' => array( 'In the beginning' ),
                ),
                array( 'type' => 'line_break' ),
                array(
                    'type'    => 'verse',
                    'number'  => 2,
                    'content' => array( 'And the earth was formless' ),
                ),
            ),
            'footnotes' => array(),
        );

        $result = ChapterNormalizer::normalize( $chapter );

        $this->assertCount( 2, $result );
        $this->assertSame( 1, $result[0]['number'] );
        $this->assertSame( 2, $result[1]['number'] );
        $this->assertSame( 'In the beginning', $result[0]['nodes'][0]['text'] );
    }

    public function test_bare_string_run_becomes_a_text_node(): void {
        $chapter = array(
            'content' => array(
                array( 'type' => 'verse', 'number' => 1, 'content' => array( 'Plain text' ) ),
            ),
        );

        $result = ChapterNormalizer::normalize( $chapter );

        $this->assertSame(
            array( array( 'type' => 'text', 'text' => 'Plain text' ) ),
            $result[0]['nodes']
        );
    }

    public function test_unresolvable_note_is_dropped(): void {
        $chapter = array(
            'content'   => array(
                array(
                    'type'    => 'verse',
                    'number'  => 1,
                    'content' => array(
                        array( 'text' => 'Some words' ),
                        array( 'noteId' => 99 ), // no matching footnote
                    ),
                ),
            ),
            'footnotes' => array(
                array( 'noteId' => 0, 'text' => 'Unrelated note' ),
            ),
        );

        $result = ChapterNormalizer::normalize( $chapter );

        // Note dropped; only the text run survives.
        $this->assertSame(
            array( array( 'type' => 'text', 'text' => 'Some words' ) ),
            $result[0]['nodes']
        );
    }

    public function test_empty_footnote_body_drops_the_note(): void {
        $chapter = array(
            'content'   => array(
                array(
                    'type'    => 'verse',
                    'number'  => 1,
                    'content' => array( array( 'noteId' => 0 ) ),
                ),
            ),
            'footnotes' => array(
                array( 'noteId' => 0, 'text' => '' ),
            ),
        );

        $result = ChapterNormalizer::normalize( $chapter );

        // Verse still emitted (presence is keyed on number), but with no nodes.
        $this->assertSame(
            array( array( 'number' => 1, 'nodes' => array() ) ),
            $result
        );
    }

    public function test_empty_text_run_is_dropped(): void {
        $chapter = array(
            'content' => array(
                array(
                    'type'    => 'verse',
                    'number'  => 1,
                    'content' => array(
                        array( 'text' => '' ),
                        array( 'text' => 'Kept' ),
                    ),
                ),
            ),
        );

        $result = ChapterNormalizer::normalize( $chapter );

        $this->assertSame(
            array( array( 'type' => 'text', 'text' => 'Kept' ) ),
            $result[0]['nodes']
        );
    }

    public function test_verse_with_no_renderable_nodes_is_still_emitted(): void {
        // A verse present but with empty content must still register its number so
        // RefValidator::rangeWithinChapter sees it as present (not a critical gap).
        $chapter = array(
            'content' => array(
                array( 'type' => 'verse', 'number' => 7, 'content' => array() ),
            ),
        );

        $result = ChapterNormalizer::normalize( $chapter );

        $this->assertSame(
            array( array( 'number' => 7, 'nodes' => array() ) ),
            $result
        );
    }

    /**
     * @dataProvider malformedChapters
     *
     * @param mixed $chapter
     */
    public function test_malformed_chapter_yields_empty_array( $chapter ): void {
        $this->assertSame( array(), ChapterNormalizer::normalize( $chapter ) );
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function malformedChapters(): array {
        return array(
            'null'                 => array( null ),
            'string'               => array( 'not a chapter' ),
            'int'                  => array( 42 ),
            'empty array'          => array( array() ),
            'content not an array' => array( array( 'content' => 'nope' ) ),
            'content of garbage'   => array( array( 'content' => array( 'x', 5, null ) ) ),
            'verse missing number' => array(
                array( 'content' => array( array( 'type' => 'verse', 'content' => array( 'hi' ) ) ) ),
            ),
            'verse bad number'     => array(
                array( 'content' => array( array( 'type' => 'verse', 'number' => 'abc', 'content' => array( 'hi' ) ) ) ),
            ),
        );
    }

    public function test_numeric_string_verse_number_is_coerced(): void {
        $chapter = array(
            'content' => array(
                array( 'type' => 'verse', 'number' => '3', 'content' => array( 'text' ) ),
            ),
        );

        $result = ChapterNormalizer::normalize( $chapter );

        $this->assertSame( 3, $result[0]['number'] );
    }

    public function test_schema_version_tracks_the_cache_schema_constant(): void {
        $this->assertSame( ID::BIBLE_CACHE_SCHEMA_VERSION, ChapterNormalizer::schemaVersion() );
    }
}
