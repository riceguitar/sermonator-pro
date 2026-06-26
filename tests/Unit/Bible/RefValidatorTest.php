<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use PHPUnit\Framework\TestCase;
use Sermonator\Bible\RefValidator;

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

    public function test_is_versification_divergent_helper(): void {
        $this->assertTrue( RefValidator::isVersificationDivergent( 'PSA', 119 ) );
        $this->assertTrue( RefValidator::isVersificationDivergent( 'REV', 12 ) );
        $this->assertFalse( RefValidator::isVersificationDivergent( 'REV', 1 ) );
        $this->assertFalse( RefValidator::isVersificationDivergent( 'JHN', 3 ) );
    }
}
