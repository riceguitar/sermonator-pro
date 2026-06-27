<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\RefValidator;
use Sermonator\Frontend\Bible\ChapterNormalizer;

/**
 * Integration coverage for {@see ChapterNormalizer} composed with the REAL
 * downstream consumer of its output shape, {@see RefValidator::rangeWithinChapter}
 * (the spec-L9 never-fail-WRONG valve). Where the unit suite mocks the flat shape
 * structurally, this suite proves the contract holds end-to-end: a realistic
 * multi-verse helloao chapter object — including a WEB critical-text GAP (a missing
 * verse number) — normalizes to the `[{number, nodes}]` shape, and the gap then
 * fails the whole range OPEN exactly as L9 requires.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). Authored to
 * run later under wp-env. ChapterNormalizer is pure and performs ZERO I/O, so the
 * value here is the cross-component composition, not WordPress globals.
 */
final class ChapterNormalizerTest extends WP_UnitTestCase {
    /**
     * A realistic helloao chapter (mixed text + words of Jesus + footnotes)
     * normalizes to the flat typed-node shape, and a fully-present verse range
     * confirms within it.
     */
    public function test_normalized_chapter_feeds_range_within_chapter(): void {
        $chapter = self::john3();

        $normalized = ChapterNormalizer::normalize( $chapter );

        // Shape contract: one entry per verse, each {number:int, nodes:list}.
        $this->assertNotEmpty( $normalized );
        foreach ( $normalized as $verse ) {
            $this->assertIsInt( $verse['number'] );
            $this->assertIsArray( $verse['nodes'] );
            foreach ( $verse['nodes'] as $node ) {
                $this->assertContains( $node['type'], array( 'text', 'wordsOfJesus', 'note' ), 'node type is typed' );
                $this->assertIsString( $node['text'] );
            }
        }

        // L9 confirmation: John 3:16-17 is fully present → range confirms.
        $this->assertTrue(
            RefValidator::rangeWithinChapter(
                array( 'verseStart' => 16, 'verseEnd' => 17, 'chapterEnd' => null ),
                $normalized
            )
        );
    }

    /**
     * A WEB critical-text gap (a missing verse number in the normalized output)
     * fails the WHOLE crossing range open to the 3a link — presence, not partial.
     */
    public function test_critical_text_gap_fails_range_open(): void {
        // helloao omits the verse entirely; the normalized chapter has a hole at 4.
        $chapter = array(
            'content'   => array(
                array( 'type' => 'verse', 'number' => 3, 'content' => array( 'Verse three' ) ),
                // verse 4 omitted (critical-text gap)
                array( 'type' => 'verse', 'number' => 5, 'content' => array( 'Verse five' ) ),
            ),
            'footnotes' => array(),
        );

        $normalized = ChapterNormalizer::normalize( $chapter );

        $this->assertSame( array( 3, 5 ), array_column( $normalized, 'number' ) );

        // A range 3-5 spans the missing 4 → fails the whole ref open.
        $this->assertFalse(
            RefValidator::rangeWithinChapter(
                array( 'verseStart' => 3, 'verseEnd' => 5, 'chapterEnd' => null ),
                $normalized
            )
        );
    }

    /**
     * @return array<string,mixed> A small John 3 fixture in helloao chapter shape.
     */
    private static function john3(): array {
        return array(
            'content'   => array(
                array(
                    'type'    => 'verse',
                    'number'  => 16,
                    'content' => array(
                        'For God so loved the world, that he gave his ',
                        array( 'text' => 'one and only Son', 'wordsOfJesus' => true ),
                        array( 'noteId' => 0 ),
                    ),
                ),
                array(
                    'type'    => 'verse',
                    'number'  => 17,
                    'content' => array(
                        array( 'text' => 'For God didn\'t send his Son into the world to judge the world,', 'wordsOfJesus' => true ),
                    ),
                ),
            ),
            'footnotes' => array(
                array( 'noteId' => 0, 'text' => 'or, only born Son' ),
            ),
        );
    }
}
