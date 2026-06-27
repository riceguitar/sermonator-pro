<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use PHPUnit\Framework\TestCase;
use Sermonator\Bible\RefsCapture;
use Sermonator\Bible\VersificationGate;
use Sermonator\Schema\BibleTranslations;

/**
 * VersificationGate is the pure (source-family → target) never-fail-WRONG relation
 * (design §2 L4–L7, §3.1). It is the only layer that reads BOTH axes — the source
 * versification family AND the inline target — so it can answer the real question
 * 3a's unary divergence flag could not: "would rendering THIS target's words for a
 * ref carried in THAT source versification show real-but-WRONG verses?".
 *
 * The proof obligation of every test here is precision, not recall: an uncertain
 * path MUST fall open (eligible:false → the 3a link), and only a provably-aligned
 * (source, target, book, chapter) clears the gate.
 */
final class VersificationGateTest extends TestCase {
    private const TARGET = BibleTranslations::DEFAULT_INLINE; // ENGWEBP

    /**
     * Build a Ref with sane inline-clearing defaults the individual tests override.
     *
     * @param array<string,mixed> $ref
     *
     * @return array<string,mixed>
     */
    private function ref( array $ref = array() ): array {
        return array_merge(
            array(
                'bookUSFM'                   => 'JHN',
                'chapterStart'               => 3,
                'verseStart'                 => 16,
                'verseEnd'                   => null,
                'chapterEnd'                 => null,
                'srcVersification'           => 'ESV',
                'srcVersificationConfidence' => RefsCapture::SRC_VERSIFICATION_CONFIDENCE_AUTHORED,
                'raw'                        => 'John 3:16',
            ),
            $ref
        );
    }

    // ----- L4: unknown / foreign source versification family --------------------

    public function test_foreign_source_family_is_unsupported(): void {
        // Reina-Valera (Spanish) normalizes to no modeled family.
        $result = VersificationGate::eligible(
            $this->ref( array( 'srcVersification' => 'RVR1960' ) ),
            self::TARGET,
            true
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_SRC_UNSUPPORTED, $result['reason'] );
    }

    public function test_empty_source_versification_is_unsupported(): void {
        $result = VersificationGate::eligible(
            $this->ref( array( 'srcVersification' => '' ) ),
            self::TARGET,
            true
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_SRC_UNSUPPORTED, $result['reason'] );
    }

    // ----- L5: unmodeled (source-family → target) pair --------------------------

    public function test_unmodeled_target_translation_is_unmodeled_pair(): void {
        // ENGKJV is a real English-Protestant code but is NOT a modeled inline
        // target (its divergence table is unaudited), so the PAIR is unmodeled.
        $result = VersificationGate::eligible(
            $this->ref(),
            'ENGKJV',
            true
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_UNMODELED_PAIR, $result['reason'] );
    }

    public function test_same_family_by_construction_pair_is_inline_eligible(): void {
        $this->assertTrue(
            VersificationGate::inlineEligibleForPair(
                BibleTranslations::FAMILY_ENGLISH_PROTESTANT,
                self::TARGET
            )
        );
    }

    public function test_unsupported_source_family_is_never_pair_eligible(): void {
        $this->assertFalse(
            VersificationGate::inlineEligibleForPair( '', self::TARGET )
        );
    }

    // ----- L6: provenance attestation -------------------------------------------

    public function test_site_default_ref_without_attestation_is_unattested(): void {
        $result = VersificationGate::eligible(
            $this->ref( array(
                'srcVersificationConfidence' => RefsCapture::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT,
            ) ),
            self::TARGET,
            false
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_UNATTESTED, $result['reason'] );
    }

    public function test_absent_confidence_reads_as_site_default_and_needs_attestation(): void {
        // No srcVersificationConfidence key at all → conservative site-default.
        $ref = $this->ref();
        unset( $ref['srcVersificationConfidence'] );

        $result = VersificationGate::eligible( $ref, self::TARGET, false );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_UNATTESTED, $result['reason'] );
    }

    public function test_site_default_ref_with_attestation_clears_the_provenance_gate(): void {
        $result = VersificationGate::eligible(
            $this->ref( array(
                'srcVersificationConfidence' => RefsCapture::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT,
            ) ),
            self::TARGET,
            true
        );

        $this->assertTrue( $result['eligible'] );
        $this->assertNull( $result['reason'] );
    }

    public function test_authored_ref_skips_attestation(): void {
        // authored + not attested → still eligible (authored gates directly).
        $result = VersificationGate::eligible( $this->ref(), self::TARGET, false );

        $this->assertTrue( $result['eligible'] );
        $this->assertNull( $result['reason'] );
    }

    // ----- L7: genuine ESV↔WEB divergent zones ----------------------------------

    public function test_known_divergent_chapter_is_versification_divergent(): void {
        // Revelation 13 — the 12:18/13:1 "stood on the sand of the sea" boundary.
        $result = VersificationGate::eligible(
            $this->ref( array( 'bookUSFM' => 'REV', 'chapterStart' => 13, 'verseStart' => 1 ) ),
            self::TARGET,
            true
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_DIVERGENT, $result['reason'] );
    }

    public function test_romans_16_doxology_chapter_is_divergent(): void {
        $result = VersificationGate::eligible(
            $this->ref( array( 'bookUSFM' => 'ROM', 'chapterStart' => 16, 'verseStart' => 25 ) ),
            self::TARGET,
            true
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_DIVERGENT, $result['reason'] );
    }

    public function test_third_john_single_chapter_is_divergent(): void {
        $result = VersificationGate::eligible(
            $this->ref( array( 'bookUSFM' => '3JN', 'chapterStart' => 1, 'verseStart' => 14 ) ),
            self::TARGET,
            true
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_DIVERGENT, $result['reason'] );
    }

    public function test_authored_ref_in_non_divergent_zone_is_eligible(): void {
        $result = VersificationGate::eligible( $this->ref(), self::TARGET, false );

        $this->assertTrue( $result['eligible'] );
        $this->assertNull( $result['reason'] );
    }

    // ----- The headline correction: Hebrew↔English zones are NOT ESV↔WEB zones ---

    public function test_psalms_are_not_divergent_for_the_english_to_english_pair(): void {
        // The 3a unary table darkened the WHOLE Psalter on a Hebrew-superscription
        // mis-calibration. Two ENGLISH versions number the Psalter identically, so a
        // psalm with a superscription is inline-eligible for the ESV↔WEB pair.
        $result = VersificationGate::eligible(
            $this->ref( array( 'bookUSFM' => 'PSA', 'chapterStart' => 23, 'verseStart' => 1 ) ),
            self::TARGET,
            true
        );

        $this->assertTrue( $result['eligible'], 'Psalms must NOT be blanket-divergent for English↔English.' );
        $this->assertNull( $result['reason'] );
    }

    public function test_psalm_3_with_hebrew_superscription_is_not_divergent(): void {
        // Ps 3 carries a Hebrew title; in English versions it is unnumbered in BOTH
        // ESV and WEB, so no offset → eligible.
        $result = VersificationGate::eligible(
            $this->ref( array( 'bookUSFM' => 'PSA', 'chapterStart' => 3, 'verseStart' => 1 ) ),
            self::TARGET,
            true
        );

        $this->assertTrue( $result['eligible'] );
    }

    public function test_joel_chapter_boundary_is_not_divergent_for_english_pair(): void {
        // Joel 2/3 diverges Hebrew↔English, NOT English↔English (both use 3 chapters).
        $result = VersificationGate::eligible(
            $this->ref( array( 'bookUSFM' => 'JOL', 'chapterStart' => 2, 'verseStart' => 28 ) ),
            self::TARGET,
            true
        );

        $this->assertTrue( $result['eligible'] );
    }

    public function test_malachi_chapter_4_is_not_divergent_for_english_pair(): void {
        $result = VersificationGate::eligible(
            $this->ref( array( 'bookUSFM' => 'MAL', 'chapterStart' => 4, 'verseStart' => 1 ) ),
            self::TARGET,
            true
        );

        $this->assertTrue( $result['eligible'] );
    }

    // ----- Ordering: an earlier failing layer wins -------------------------------

    public function test_unsupported_source_short_circuits_before_divergence(): void {
        // Foreign source AND a divergent chapter — L4 must win (most-conservative,
        // and reason-distinct so the audit counters never conflate the two).
        $result = VersificationGate::eligible(
            $this->ref( array(
                'srcVersification' => 'RVR1960',
                'bookUSFM'         => 'REV',
                'chapterStart'     => 13,
                'verseStart'       => 1,
            ) ),
            self::TARGET,
            true
        );

        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_SRC_UNSUPPORTED, $result['reason'] );
    }

    public function test_all_reason_codes_are_distinct(): void {
        $reasons = array(
            VersificationGate::REASON_SRC_UNSUPPORTED,
            VersificationGate::REASON_UNMODELED_PAIR,
            VersificationGate::REASON_UNATTESTED,
            VersificationGate::REASON_DIVERGENT,
        );

        $this->assertSame( $reasons, array_values( array_unique( $reasons ) ) );
    }

    // ----- familyCode normalization pass-through ---------------------------------

    public function test_family_code_normalizes_uk_suffix_to_english_protestant(): void {
        $this->assertSame(
            BibleTranslations::FAMILY_ENGLISH_PROTESTANT,
            VersificationGate::familyCode( 'NIVUK' )
        );
    }

    public function test_family_code_of_foreign_version_is_empty(): void {
        $this->assertSame( '', VersificationGate::familyCode( 'RVR1960' ) );
    }
}
