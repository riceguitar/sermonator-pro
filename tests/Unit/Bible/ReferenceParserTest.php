<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use PHPUnit\Framework\TestCase;
use Sermonator\Bible\ReferenceParser;

/**
 * The parser is a PURE function: no WordPress, no I/O, no Brain Monkey needed.
 * Segment granularity is the never-fail-wrong unit, so the tests assert both the
 * resolved refs AND that unrecognized text survives verbatim as a fallback.
 */
final class ReferenceParserTest extends TestCase {
    /**
     * @param array<int,array{book:string,cs:int,vs:?int,ve:?int,ce:?int}> $expectedRefs
     *
     * @dataProvider provideReferences
     */
    public function test_parses_reference_into_expected_refs( string $raw, array $expectedRefs ): void {
        $result = ReferenceParser::parse( $raw );

        // Flatten the matched refs across all segments for the value assertions.
        $flat = array();
        foreach ( $result['segments'] as $segment ) {
            foreach ( $segment['refs'] as $ref ) {
                $flat[] = $ref;
            }
        }

        $this->assertCount( count( $expectedRefs ), $flat, "Wrong ref count for: {$raw}" );

        foreach ( $expectedRefs as $i => $expected ) {
            $this->assertSame( $expected['book'], $flat[ $i ]['bookUSFM'], "book mismatch #{$i} for: {$raw}" );
            $this->assertSame( $expected['cs'], $flat[ $i ]['chapterStart'], "chapterStart mismatch #{$i} for: {$raw}" );
            $this->assertSame( $expected['vs'], $flat[ $i ]['verseStart'], "verseStart mismatch #{$i} for: {$raw}" );
            $this->assertSame( $expected['ve'], $flat[ $i ]['verseEnd'], "verseEnd mismatch #{$i} for: {$raw}" );
            $this->assertSame( $expected['ce'], $flat[ $i ]['chapterEnd'], "chapterEnd mismatch #{$i} for: {$raw}" );
        }
    }

    /**
     * @return array<string,array{0:string,1:array<int,array{book:string,cs:int,vs:?int,ve:?int,ce:?int}>}>
     */
    public static function provideReferences(): array {
        return array(
            'full name single verse' => array(
                'John 3:16',
                array(
                    array( 'book' => 'JHN', 'cs' => 3, 'vs' => 16, 've' => null, 'ce' => null ),
                ),
            ),
            'two segments, two books' => array(
                'Jn 3:16-18, Luke 2:1-3',
                array(
                    array( 'book' => 'JHN', 'cs' => 3, 'vs' => 16, 've' => 18, 'ce' => null ),
                    array( 'book' => 'LUK', 'cs' => 2, 'vs' => 1, 've' => 3, 'ce' => null ),
                ),
            ),
            'chapter carry-over across ampersand' => array(
                'Ps 23 & 24',
                array(
                    array( 'book' => 'PSA', 'cs' => 23, 'vs' => null, 've' => null, 'ce' => null ),
                    array( 'book' => 'PSA', 'cs' => 24, 'vs' => null, 've' => null, 'ce' => null ),
                ),
            ),
            'verse carry-over within chapter' => array(
                'John 3:16, 18',
                array(
                    array( 'book' => 'JHN', 'cs' => 3, 'vs' => 16, 've' => null, 'ce' => null ),
                    array( 'book' => 'JHN', 'cs' => 3, 'vs' => 18, 've' => null, 'ce' => null ),
                ),
            ),
            'cross-chapter range' => array(
                'Matt 5:1-7:29',
                array(
                    array( 'book' => 'MAT', 'cs' => 5, 'vs' => 1, 've' => 29, 'ce' => 7 ),
                ),
            ),
            'J-cluster: Jn is John not Jonah' => array(
                'Jn 3:16',
                array(
                    array( 'book' => 'JHN', 'cs' => 3, 'vs' => 16, 've' => null, 'ce' => null ),
                ),
            ),
            'longest match: Philemon not Philippians' => array(
                'Philemon 6',
                array(
                    array( 'book' => 'PHM', 'cs' => 6, 'vs' => null, 've' => null, 'ce' => null ),
                ),
            ),
            'ordinal word collapses to digit' => array(
                'First John 4:8',
                array(
                    array( 'book' => '1JN', 'cs' => 4, 'vs' => 8, 've' => null, 'ce' => null ),
                ),
            ),
            'roman numeral collapses to digit' => array(
                'II Timothy 1:7',
                array(
                    array( 'book' => '2TI', 'cs' => 1, 'vs' => 7, 've' => null, 'ce' => null ),
                ),
            ),
            'chapter-only range' => array(
                'Genesis 1-2',
                array(
                    array( 'book' => 'GEN', 'cs' => 1, 'vs' => null, 've' => null, 'ce' => 2 ),
                ),
            ),
            'dot as chapter verse separator' => array(
                'Rom 8.28',
                array(
                    array( 'book' => 'ROM', 'cs' => 8, 'vs' => 28, 've' => null, 'ce' => null ),
                ),
            ),
            'abbreviation dot is stripped' => array(
                'Gen. 1:1',
                array(
                    array( 'book' => 'GEN', 'cs' => 1, 'vs' => 1, 've' => null, 'ce' => null ),
                ),
            ),
            'garbage yields no refs' => array(
                'see also the bulletin',
                array(),
            ),
        );
    }

    public function test_garbage_segment_is_fallback_and_keeps_raw(): void {
        $result = ReferenceParser::parse( 'see also the bulletin' );

        $this->assertCount( 1, $result['segments'] );
        $this->assertSame( 'fallback', $result['segments'][0]['status'] );
        $this->assertSame( 'see also the bulletin', $result['segments'][0]['raw'] );
        $this->assertSame( array(), $result['segments'][0]['refs'] );
    }

    public function test_partial_garbage_is_isolated_to_its_own_segment(): void {
        // Never-fail-wrong: the good reference resolves, the garbage survives as
        // a fallback segment carrying its raw text; neither contaminates the other.
        $result = ReferenceParser::parse( 'John 3:16 & see the bulletin' );

        $this->assertCount( 2, $result['segments'] );

        $this->assertSame( 'matched', $result['segments'][0]['status'] );
        $this->assertSame( 'JHN', $result['segments'][0]['refs'][0]['bookUSFM'] );

        $this->assertSame( 'fallback', $result['segments'][1]['status'] );
        $this->assertSame( 'see the bulletin', $result['segments'][1]['raw'] );
        $this->assertSame( array(), $result['segments'][1]['refs'] );
    }

    public function test_bare_book_name_resets_carry_over_context(): void {
        // 'Ps 23, John, 3:16': the bare 'John' segment matches a book head but has
        // no numeric tail. It MUST become the new carry-over context so the trailing
        // '3:16' resolves under JHN, never silently mis-attributed to the prior PSA.
        $result = ReferenceParser::parse( 'Ps 23, John, 3:16' );

        $this->assertCount( 3, $result['segments'] );

        // Ps 23 -> PSA 23.
        $this->assertSame( 'matched', $result['segments'][0]['status'] );
        $this->assertSame( 'PSA', $result['segments'][0]['refs'][0]['bookUSFM'] );

        // Bare 'John' -> fallback (no linkable chapter yet), but resets context.
        $this->assertSame( 'fallback', $result['segments'][1]['status'] );
        $this->assertSame( 'John', $result['segments'][1]['raw'] );

        // 3:16 -> resolves under JHN, NOT PSA.
        $this->assertSame( 'matched', $result['segments'][2]['status'] );
        $this->assertSame( 'JHN', $result['segments'][2]['refs'][0]['bookUSFM'] );
        $this->assertSame( 3, $result['segments'][2]['refs'][0]['chapterStart'] );
        $this->assertSame( 16, $result['segments'][2]['refs'][0]['verseStart'] );
    }

    public function test_matched_segment_preserves_its_raw_text(): void {
        $result = ReferenceParser::parse( 'John 3:16' );

        $this->assertSame( 'matched', $result['segments'][0]['status'] );
        $this->assertSame( 'John 3:16', $result['segments'][0]['raw'] );
    }

    public function test_every_ref_carries_its_own_raw(): void {
        $result = ReferenceParser::parse( 'Jn 3:16-18, Luke 2:1-3' );

        $this->assertSame( 'Jn 3:16-18', $result['segments'][0]['refs'][0]['raw'] );
        $this->assertSame( 'Luke 2:1-3', $result['segments'][1]['refs'][0]['raw'] );
    }

    public function test_empty_input_yields_no_segments(): void {
        $this->assertSame( array( 'segments' => array() ), ReferenceParser::parse( '' ) );
        $this->assertSame( array( 'segments' => array() ), ReferenceParser::parse( '   ' ) );
    }

    public function test_ref_shape_has_all_keys(): void {
        $ref = ReferenceParser::parse( 'John 3:16' )['segments'][0]['refs'][0];

        $this->assertSame(
            array( 'bookUSFM', 'chapterStart', 'verseStart', 'verseEnd', 'chapterEnd', 'raw' ),
            array_keys( $ref )
        );
    }
}
