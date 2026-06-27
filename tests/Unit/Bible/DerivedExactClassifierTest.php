<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use PHPUnit\Framework\TestCase;
use Sermonator\Bible\DerivedExactClassifier;

/**
 * {@see DerivedExactClassifier} is a PURE function of one ref plus its own raw: no
 * WordPress, no I/O, no Brain Monkey. It re-parses each ref's raw IN ISOLATION through
 * the pure {@see \Sermonator\Bible\ReferenceParser} and demands structural identity, so
 * a carry-over continuation (whose book lived in a sibling segment) can never promote.
 *
 * These tests pin the §2 contract:
 *   - a clean lone in-chapter ref IS derived-exact;
 *   - a bookless continuation (re-parsed alone → fallback) is NOT;
 *   - a raw that re-parses to a DIFFERENT shape is NOT (structural mismatch);
 *   - chapter-only and cross-chapter refs are NOT (fail L1 by shape);
 *   - STRICT withholds a compound passage's segments while per-seg promotes them;
 *   - `exact` promotes nothing.
 */
final class DerivedExactClassifierTest extends TestCase {
    /**
     * Build a stored ref. Defaults form a clean single verse "John 3:16"; override any
     * field to model continuations, mismatches, chapter-only, or cross-chapter shapes.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function ref( array $overrides = array() ): array {
        return array_merge(
            array(
                'bookUSFM'     => 'JHN',
                'chapterStart' => 3,
                'verseStart'   => 16,
                'verseEnd'     => null,
                'chapterEnd'   => null,
                'raw'          => 'John 3:16',
            ),
            $overrides
        );
    }

    // ---- isDerivedExact ----------------------------------------------------

    public function test_clean_single_verse_is_derived_exact(): void {
        $this->assertTrue( DerivedExactClassifier::isDerivedExact( $this->ref() ) );
    }

    public function test_clean_in_chapter_range_is_derived_exact(): void {
        $ref = $this->ref( array(
            'verseStart' => 16,
            'verseEnd'   => 18,
            'raw'        => 'John 3:16-18',
        ) );

        $this->assertTrue( DerivedExactClassifier::isDerivedExact( $ref ) );
    }

    public function test_bookless_continuation_is_not_derived_exact(): void {
        // The bare "18" trailing "John 3:16, 18": the stored ref carries the carried-over
        // book/chapter, but re-parsing its OWN raw "18" alone has no book → fallback → false.
        $continuation = $this->ref( array(
            'chapterStart' => 3,
            'verseStart'   => 18,
            'raw'          => '18',
        ) );

        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $continuation ) );
    }

    public function test_bare_verse_range_continuation_is_not_derived_exact(): void {
        // "5:1-11" trailing "Isaiah 6:1-13; Luke 5:1-11": stored as LUK 5:1-11, but the raw
        // "5:1-11" re-parsed alone is just a numeric tail with no book → fallback → false.
        $continuation = $this->ref( array(
            'bookUSFM'     => 'LUK',
            'chapterStart' => 5,
            'verseStart'   => 1,
            'verseEnd'     => 11,
            'raw'          => '5:1-11',
        ) );

        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $continuation ) );
    }

    public function test_structural_mismatch_is_not_derived_exact(): void {
        // The raw re-parses cleanly to John 3:17, but the stored ref claims verse 16 — the
        // identity guard rejects the mismatch (conservative; never re-stamp a wrong shape).
        $mismatch = $this->ref( array(
            'verseStart' => 16,
            'raw'        => 'John 3:17',
        ) );

        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $mismatch ) );
    }

    public function test_book_mismatch_is_not_derived_exact(): void {
        // Stored book disagrees with what the raw re-parses to.
        $mismatch = $this->ref( array(
            'bookUSFM' => 'MRK',
            'raw'      => 'John 3:16',
        ) );

        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $mismatch ) );
    }

    public function test_chapter_only_ref_is_not_derived_exact(): void {
        // "John 3" — no verseStart → fails L1 shape regardless of a clean re-parse.
        $chapterOnly = $this->ref( array(
            'verseStart' => null,
            'raw'        => 'John 3',
        ) );

        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $chapterOnly ) );
    }

    public function test_cross_chapter_ref_is_not_derived_exact(): void {
        // "Matthew 5:1-7:29" — chapterEnd set → fails L1 shape (never cross-chapter inline).
        $crossChapter = $this->ref( array(
            'bookUSFM'     => 'MAT',
            'chapterStart' => 5,
            'verseStart'   => 1,
            'verseEnd'     => 29,
            'chapterEnd'   => 7,
            'raw'          => 'Matthew 5:1-7:29',
        ) );

        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $crossChapter ) );
    }

    public function test_missing_raw_is_not_derived_exact(): void {
        $ref = $this->ref();
        unset( $ref['raw'] );

        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $ref ) );
    }

    public function test_empty_raw_is_not_derived_exact(): void {
        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $this->ref( array( 'raw' => '' ) ) ) );
    }

    public function test_pre_stamped_derived_exact_confidence_clears_nothing_on_its_own(): void {
        // A smuggled `derived-exact` stored confidence must NOT short-circuit anything: the
        // classifier ignores `confidence` entirely and still demands re-parse identity. Here
        // the raw is a bookless continuation, so it stays withheld despite the stamp.
        $smuggled = $this->ref( array(
            'verseStart' => 18,
            'raw'        => '18',
            'confidence' => 'derived-exact',
        ) );

        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $smuggled ) );
    }

    // ---- promotes ----------------------------------------------------------

    public function test_exact_floor_promotes_nothing_even_for_clean_ref(): void {
        $this->assertFalse(
            DerivedExactClassifier::promotes( $this->ref(), DerivedExactClassifier::FLOOR_EXACT, 1 )
        );
    }

    public function test_strict_promotes_lone_clean_ref(): void {
        $this->assertTrue(
            DerivedExactClassifier::promotes( $this->ref(), DerivedExactClassifier::FLOOR_DERIVED_EXACT, 1 )
        );
    }

    public function test_strict_withholds_a_compound_passage_segment_while_perseg_promotes_it(): void {
        $cleanSegment = $this->ref( array( 'raw' => 'John 3:16-18', 'verseStart' => 16, 'verseEnd' => 18 ) );

        // Two siblings in the envelope → STRICT refuses the singleton constraint…
        $this->assertFalse(
            DerivedExactClassifier::promotes( $cleanSegment, DerivedExactClassifier::FLOOR_DERIVED_EXACT, 2 ),
            'STRICT must never promote a compound passage segment.'
        );

        // …but per-seg promotes the same individually-clean segment regardless of count.
        $this->assertTrue(
            DerivedExactClassifier::promotes( $cleanSegment, DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG, 2 ),
            'Per-seg must promote an individually-clean segment.'
        );
    }

    public function test_perseg_still_withholds_a_continuation_segment(): void {
        // Even per-seg cannot rescue a bookless continuation — identity fails first.
        $continuation = $this->ref( array( 'verseStart' => 18, 'raw' => '18' ) );

        $this->assertFalse(
            DerivedExactClassifier::promotes( $continuation, DerivedExactClassifier::FLOOR_DERIVED_EXACT_PERSEG, 2 )
        );
    }

    public function test_unknown_floor_promotes_nothing(): void {
        $this->assertFalse(
            DerivedExactClassifier::promotes( $this->ref(), 'something-else', 1 )
        );
    }

    public function test_memo_keys_on_raw_not_on_boolean_so_same_raw_different_shape_diverges(): void {
        // Two stored refs share the raw "John 3:16"; the memo caches the RE-PARSE of that raw
        // once, but each ref is compared individually — the matching shape promotes, the
        // mismatching one does not, proving the memo never poisons a sibling's result.
        $match    = $this->ref( array( 'verseStart' => 16, 'raw' => 'John 3:16' ) );
        $mismatch = $this->ref( array( 'verseStart' => 17, 'raw' => 'John 3:16' ) );

        $this->assertTrue( DerivedExactClassifier::isDerivedExact( $match ) );
        $this->assertFalse( DerivedExactClassifier::isDerivedExact( $mismatch ) );
    }
}
