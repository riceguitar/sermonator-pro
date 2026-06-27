<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\RefValidator;

/**
 * Integration coverage for the spec-L9 render-time confirmation valve,
 * {@see RefValidator::rangeWithinChapter}, exercised against realistic
 * normalized flat chapters in the shape ChapterNormalizer will emit
 * (`[{number:int, nodes:[...]}]`).
 *
 * NOT run in this environment (no Docker / wp-env) — authored to run under
 * wp-env later. `rangeWithinChapter` is pure and performs ZERO I/O, so this
 * suite asserts the never-fail-WRONG contract holds when the method is handed
 * the kind of chapter payload the real pipeline produces, including the WEB
 * critical-text gap that the unit suite mocks structurally.
 */
final class RangeWithinChapterTest extends WP_UnitTestCase {
    /**
     * Build a normalized flat chapter from a list of present verse numbers,
     * each entry carrying the `{number, nodes}` shape of the real cache files.
     *
     * @param list<int> $numbers
     *
     * @return list<array{number:int,nodes:list<array{type:string,text:string}>}>
     */
    private function chapter( array $numbers ): array {
        return array_map(
            static fn ( int $n ): array => array(
                'number' => $n,
                'nodes'  => array( array( 'type' => 'text', 'text' => "Verse {$n} text." ) ),
            ),
            $numbers
        );
    }

    /** @param array<string,mixed> $ref */
    private function ref( array $ref ): array {
        return array_merge(
            array(
                'bookUSFM'     => 'JHN',
                'chapterStart' => 3,
                'verseStart'   => 16,
                'verseEnd'     => null,
                'chapterEnd'   => null,
            ),
            $ref
        );
    }

    public function test_full_present_range_confirms_inline(): void {
        $chapter = $this->chapter( range( 1, 36 ) ); // John 3 has 36 verses.
        $ref     = $this->ref( array( 'verseStart' => 16, 'verseEnd' => 17 ) );
        $this->assertTrue( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_critical_text_gap_fails_whole_ref_open_to_link(): void {
        // A WEB-style critical-text omission leaves a numbering hole; the whole
        // span must withhold inline so the renderer falls open to the 3a link.
        $chapter = $this->chapter( array( 21, 22, 23, 25, 26, 27, 28 ) ); // 24 omitted.
        $ref     = $this->ref(
            array( 'bookUSFM' => 'MAT', 'chapterStart' => 16, 'verseStart' => 23, 'verseEnd' => 26 )
        );
        $this->assertFalse( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_out_of_range_end_fails(): void {
        $chapter = $this->chapter( range( 1, 23 ) );
        $ref     = $this->ref( array( 'verseStart' => 20, 'verseEnd' => 25 ) );
        $this->assertFalse( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_single_present_verse_confirms_inline(): void {
        $chapter = $this->chapter( range( 1, 36 ) );
        $ref     = $this->ref( array( 'verseStart' => 16, 'verseEnd' => null ) );
        $this->assertTrue( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }
}
