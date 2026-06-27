<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use PHPUnit\Framework\TestCase;
use Sermonator\Bible\RefValidator;
use Sermonator\Schema\BibleBookMap;

/**
 * RefValidator is pure: it gates a parsed Ref to render-readiness. inlineEligible
 * is the conservative, versification-aware flag — when in doubt, withhold (a link
 * is shown instead of maybe-wrong verse text).
 */
final class RefValidatorTest extends TestCase {
    /**
     * @param array<string,mixed> $ref
     */
    private function ref( array $ref ): array {
        return array_merge(
            array(
                'bookUSFM'     => 'JHN',
                'chapterStart' => 3,
                'verseStart'   => 16,
                'verseEnd'     => null,
                'chapterEnd'   => null,
                'raw'          => 'John 3:16',
            ),
            $ref
        );
    }

    public function test_in_canon_book_is_recognized(): void {
        $this->assertTrue( RefValidator::validate( $this->ref( array() ) )['inCanon'] );
    }

    public function test_unknown_book_is_not_in_canon(): void {
        $result = RefValidator::validate( $this->ref( array( 'bookUSFM' => 'XYZ' ) ) );
        $this->assertFalse( $result['inCanon'] );
        $this->assertFalse( $result['inlineEligible'] );
    }

    public function test_fully_specified_in_canon_verse_is_inline_eligible(): void {
        $this->assertTrue( RefValidator::validate( $this->ref( array() ) )['inlineEligible'] );
    }

    public function test_chapter_only_ref_is_not_inline_eligible(): void {
        $result = RefValidator::validate(
            $this->ref( array( 'verseStart' => null, 'verseEnd' => null ) )
        );
        $this->assertTrue( $result['inCanon'] );
        $this->assertTrue( $result['structurallyValid'] );
        $this->assertFalse( $result['inlineEligible'] );
    }

    public function test_cross_chapter_range_is_not_inline_eligible(): void {
        $result = RefValidator::validate(
            $this->ref(
                array(
                    'bookUSFM'     => 'MAT',
                    'chapterStart' => 5,
                    'verseStart'   => 1,
                    'verseEnd'     => 29,
                    'chapterEnd'   => 7,
                )
            )
        );
        $this->assertFalse( $result['inlineEligible'] );
    }

    public function test_cross_chapter_range_with_descending_end_verse_is_structurally_valid(): void {
        // John 7:53-8:11 (pericope adulterae): verseEnd (11) belongs to chapterEnd
        // (8), a DIFFERENT chapter than verseStart (53, in chapter 7). Verse numbers
        // reset each chapter, so 11 < 53 is legitimate, NOT descending. The
        // cross-chapter case is first-class lossless (§3), so it must stay valid.
        $result = RefValidator::validate(
            $this->ref(
                array(
                    'bookUSFM'     => 'JHN',
                    'chapterStart' => 7,
                    'verseStart'   => 53,
                    'verseEnd'     => 11,
                    'chapterEnd'   => 8,
                )
            )
        );
        $this->assertTrue( $result['structurallyValid'] );
        // Still link-only (cross-chapter is never inline-eligible), but not dropped.
        $this->assertFalse( $result['inlineEligible'] );
    }

    public function test_descending_chapter_end_is_structurally_invalid(): void {
        // chapterEnd < chapterStart remains a real descending range -> invalid.
        $result = RefValidator::validate(
            $this->ref(
                array(
                    'bookUSFM'     => 'MAT',
                    'chapterStart' => 7,
                    'verseStart'   => 1,
                    'verseEnd'     => 29,
                    'chapterEnd'   => 5,
                )
            )
        );
        $this->assertFalse( $result['structurallyValid'] );
    }

    public function test_verse_end_without_verse_start_is_structurally_invalid(): void {
        // A dangling end-verse with no start (even cross-chapter) is malformed.
        $result = RefValidator::validate(
            $this->ref(
                array(
                    'bookUSFM'     => 'JHN',
                    'chapterStart' => 7,
                    'verseStart'   => null,
                    'verseEnd'     => 11,
                    'chapterEnd'   => 8,
                )
            )
        );
        $this->assertFalse( $result['structurallyValid'] );
    }

    public function test_psalm_9_is_not_inline_eligible_versification_divergent(): void {
        $result = RefValidator::validate(
            $this->ref(
                array( 'bookUSFM' => 'PSA', 'chapterStart' => 9, 'verseStart' => 1, 'verseEnd' => null )
            )
        );
        $this->assertTrue( $result['inCanon'] );
        $this->assertFalse( $result['inlineEligible'] );
    }

    public function test_joel_2_28_is_not_inline_eligible_versification_divergent(): void {
        $result = RefValidator::validate(
            $this->ref(
                array( 'bookUSFM' => 'JOL', 'chapterStart' => 2, 'verseStart' => 28, 'verseEnd' => null )
            )
        );
        $this->assertFalse( $result['inlineEligible'] );
    }

    public function test_negative_chapter_is_structurally_invalid(): void {
        $result = RefValidator::validate( $this->ref( array( 'chapterStart' => 0 ) ) );
        $this->assertFalse( $result['structurallyValid'] );
        $this->assertFalse( $result['inlineEligible'] );
    }

    public function test_descending_verse_range_is_structurally_invalid(): void {
        $result = RefValidator::validate(
            $this->ref( array( 'verseStart' => 18, 'verseEnd' => 16 ) )
        );
        $this->assertFalse( $result['structurallyValid'] );
        $this->assertFalse( $result['inlineEligible'] );
    }

    public function test_ascending_verse_range_is_structurally_valid_and_eligible(): void {
        $result = RefValidator::validate(
            $this->ref( array( 'verseStart' => 16, 'verseEnd' => 18 ) )
        );
        $this->assertTrue( $result['structurallyValid'] );
        $this->assertTrue( $result['inlineEligible'] );
    }

    public function test_divergent_zones_cover_the_documented_set(): void {
        $zones = RefValidator::divergentZones();

        // Whole-book divergences.
        $this->assertSame( '*', $zones['PSA'] );
        $this->assertSame( '*', $zones['3JN'] );

        // Chapter-scoped divergences.
        $this->assertContains( 2, $zones['JOL'] );
        $this->assertContains( 4, $zones['MAL'] );
        $this->assertContains( 16, $zones['ROM'] );
        $this->assertContains( 12, $zones['REV'] );
        $this->assertContains( 13, $zones['REV'] );
        $this->assertArrayHasKey( 'ACT', $zones );
    }

    public function test_every_divergent_zone_key_is_a_canonical_usfm_code(): void {
        // Drift guard: a typo'd zone key (e.g. 'PSM' for 'PSA') would silently make
        // isVersificationDivergent() never match, re-enabling inline rendering in a
        // zone the #1 standard says must be suppressed. Every key MUST be a real
        // USFM code, mirroring BibleBookMap's own drift-guard discipline.
        $canonical = array_values( BibleBookMap::usfm() );

        foreach ( array_keys( RefValidator::divergentZones() ) as $book ) {
            $this->assertContains(
                $book,
                $canonical,
                "Divergent-zone key '{$book}' is not a canonical USFM code"
            );
        }
    }

    public function test_is_versification_divergent_helper(): void {
        $this->assertTrue( RefValidator::isVersificationDivergent( 'PSA', 119 ) );
        $this->assertTrue( RefValidator::isVersificationDivergent( 'REV', 12 ) );
        $this->assertFalse( RefValidator::isVersificationDivergent( 'REV', 1 ) );
        $this->assertFalse( RefValidator::isVersificationDivergent( 'JHN', 3 ) );
    }

    // ---------------------------------------------------------------------
    // rangeWithinChapter (spec L9): the render-time fail-open valve.
    // ---------------------------------------------------------------------

    /**
     * Build a normalized flat chapter ({number, nodes}) from a list of verse
     * numbers, in the shape ChapterNormalizer emits.
     *
     * @param list<int> $numbers
     *
     * @return list<array{number:int,nodes:list<array{type:string,text:string}>}>
     */
    private function chapter( array $numbers ): array {
        return array_map(
            static fn ( int $n ): array => array(
                'number' => $n,
                'nodes'  => array( array( 'type' => 'text', 'text' => "verse {$n}" ) ),
            ),
            $numbers
        );
    }

    public function test_range_within_chapter_full_present_range_passes(): void {
        // Matthew 16:24-26, all three verses present.
        $chapter = $this->chapter( range( 1, 28 ) );
        $ref     = $this->ref(
            array( 'bookUSFM' => 'MAT', 'chapterStart' => 16, 'verseStart' => 24, 'verseEnd' => 26 )
        );
        $this->assertTrue( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_range_within_chapter_single_present_verse_passes(): void {
        $chapter = $this->chapter( range( 1, 31 ) );
        $ref     = $this->ref(
            array( 'verseStart' => 16, 'verseEnd' => null )
        );
        $this->assertTrue( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_range_within_chapter_critical_text_gap_fails_whole_ref(): void {
        // WEB critical-text omits 16:24 (a verse-number gap). The WHOLE ref
        // 16:23-25 must fail open to the link, never render a partial range.
        $chapter = $this->chapter( array( 21, 22, 23, 25, 26 ) ); // 24 is missing.
        $ref     = $this->ref(
            array( 'bookUSFM' => 'MAT', 'chapterStart' => 16, 'verseStart' => 23, 'verseEnd' => 25 )
        );
        $this->assertFalse( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_range_within_chapter_out_of_range_end_fails(): void {
        // Chapter has only 23 verses; asking through v25 must fail.
        $chapter = $this->chapter( range( 1, 23 ) );
        $ref     = $this->ref(
            array( 'verseStart' => 22, 'verseEnd' => 25 )
        );
        $this->assertFalse( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_range_within_chapter_out_of_range_single_verse_fails(): void {
        $chapter = $this->chapter( range( 1, 23 ) );
        $ref     = $this->ref(
            array( 'verseStart' => 24, 'verseEnd' => null )
        );
        $this->assertFalse( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_range_within_chapter_chapter_only_ref_fails_open(): void {
        // No verseStart: nothing to confirm -> withhold inline.
        $chapter = $this->chapter( range( 1, 31 ) );
        $ref     = $this->ref(
            array( 'verseStart' => null, 'verseEnd' => null )
        );
        $this->assertFalse( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_range_within_chapter_cross_chapter_ref_fails_open(): void {
        // chapterEnd set: a single chapter cannot confirm a cross-chapter span.
        $chapter = $this->chapter( range( 1, 53 ) );
        $ref     = $this->ref(
            array(
                'bookUSFM'     => 'JHN',
                'chapterStart' => 7,
                'verseStart'   => 53,
                'verseEnd'     => 11,
                'chapterEnd'   => 8,
            )
        );
        $this->assertFalse( RefValidator::rangeWithinChapter( $ref, $chapter ) );
    }

    public function test_range_within_chapter_empty_chapter_fails(): void {
        $ref = $this->ref( array( 'verseStart' => 1, 'verseEnd' => null ) );
        $this->assertFalse( RefValidator::rangeWithinChapter( $ref, array() ) );
    }
}
