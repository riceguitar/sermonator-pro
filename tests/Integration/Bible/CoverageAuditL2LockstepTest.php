<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Bible\DerivedExactClassifier as DEC;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the DE-STORED, lockstep L2 confidence floor in
 * {@see CoverageAudit} (design §3.4–§3.5 / spec Task C). NOT run in this environment
 * (no Docker / wp-env) — authored to run under wp-env later.
 *
 * Drives the real `get_post_meta` / `get_option` / `WP_Query` stack (no Brain Monkey)
 * to prove the audit reaches the SAME promotion decision the resolver does, over the
 * de-stored stored-confidence vocabulary `{exact,probable,ambiguous}` delegated to the
 * shared {@see DEC::promotes()}.
 *
 * ## How L2 is observed without vendored chapters
 *
 * Chapters are NOT vendored under wp-env, so the L8 offline read misses. The audit
 * classifies a ref by its FIRST failing layer, and L2 runs BEFORE L8 — so:
 *   - a ref WITHHELD at L2 lands in the `low-confidence` bucket;
 *   - a ref that CLEARS L2 (and passes L3–L7) but is unwarmed lands in `cold-unwarmed`.
 * That split is a precise, chapter-free oracle for the L2 promotion decision: the exact
 * cases the audit regression broke (smuggled `derived-exact` false-green, perseg recall,
 * lone-probable promotion) are each visible as a low-confidence-vs-cold-unwarmed flip.
 */
final class CoverageAuditL2LockstepTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( ID::OPTION_BIBLE_STATS );
        delete_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR );
    }

    /** @param array<string,mixed> $overrides */
    private function ref( int $verseStart, string $raw, string $confidence, array $overrides = array() ): array {
        return array_merge( array(
            'bookUSFM'         => 'JHN',
            'chapterStart'     => 3,
            'verseStart'       => $verseStart,
            'verseEnd'         => null,
            'chapterEnd'       => null,
            'raw'              => $raw,
            'confidence'       => $confidence,
            'srcVersification' => 'ESV',
        ), $overrides );
    }

    /** @param list<array<string,mixed>> $refs */
    private function sermonWithRefs( array $refs ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => 'L2 lockstep sermon',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'passage' );
        update_post_meta( $id, ID::META_BIBLE_REFS, (string) wp_json_encode( array( 'v' => 1, 'refs' => $refs ) ) );

        return $id;
    }

    private function reportUnderFloor( string $floor ): array {
        update_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR, $floor );

        return ( new CoverageAudit() )->inlineReport();
    }

    public function test_smuggled_derived_exact_is_withheld_low_confidence_under_strict(): void {
        // FALSE-GREEN regression: a pre-stamped `confidence:derived-exact` is NOT a stored
        // tier (de-store) → ranks 0 → withheld at L2 (low-confidence), never reaching the
        // cold-unwarmed L8 the way a genuinely promoted ref would.
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'derived-exact' ) ) );

        $report = $this->reportUnderFloor( DEC::FLOOR_DERIVED_EXACT );

        $this->assertSame( 1, $report['withheld']['low-confidence'] );
        $this->assertSame( 0, $report['withheld']['cold-unwarmed'] );
        $this->assertSame( 0, $report['inline_eligible'] );
    }

    public function test_lone_probable_clears_l2_under_strict_and_reaches_cold_unwarmed(): void {
        // A lone clean `probable` PROMOTES via re-parse-identity → clears L2 → its only
        // remaining block under wp-env is the unvendored chapter (cold-unwarmed).
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $report = $this->reportUnderFloor( DEC::FLOOR_DERIVED_EXACT );

        $this->assertSame( 0, $report['withheld']['low-confidence'] );
        $this->assertSame( 1, $report['withheld']['cold-unwarmed'] );
    }

    public function test_compound_probable_stays_low_confidence_under_strict_singleton(): void {
        // STRICT singleton constraint: a 2-ref envelope never promotes, so BOTH segments
        // stay withheld at L2 (low-confidence), never cold-unwarmed.
        $this->sermonWithRefs( array(
            $this->ref( 16, 'John 3:16', 'probable' ),
            $this->ref( 17, 'John 3:17', 'probable' ),
        ) );

        $report = $this->reportUnderFloor( DEC::FLOOR_DERIVED_EXACT );

        $this->assertSame( 2, $report['withheld']['low-confidence'] );
        $this->assertSame( 0, $report['withheld']['cold-unwarmed'] );
    }

    public function test_compound_probable_promotes_per_segment_under_perseg(): void {
        // PERSEG recall regression: the SAME compound now clears L2 per segment → BOTH
        // reach cold-unwarmed (the old audit normalized perseg → exact and promoted none).
        $this->sermonWithRefs( array(
            $this->ref( 16, 'John 3:16', 'probable' ),
            $this->ref( 17, 'John 3:17', 'probable' ),
        ) );

        $report = $this->reportUnderFloor( DEC::FLOOR_DERIVED_EXACT_PERSEG );

        $this->assertSame( 0, $report['withheld']['low-confidence'] );
        $this->assertSame( 2, $report['withheld']['cold-unwarmed'] );
    }

    public function test_exact_floor_withholds_probable_at_l2(): void {
        // The conservative default promotes nothing: a clean `probable` is low-confidence.
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $report = $this->reportUnderFloor( DEC::FLOOR_EXACT );

        $this->assertSame( 1, $report['withheld']['low-confidence'] );
        $this->assertSame( 0, $report['withheld']['cold-unwarmed'] );
    }

    public function test_unknown_legacy_floor_normalizes_to_exact(): void {
        // A stale/legacy floor value (e.g. the old stored `probable`) must normalize to
        // the conservative `exact` — promoting nothing — not crash or over-promote.
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $report = $this->reportUnderFloor( 'probable' );

        $this->assertSame( DEC::FLOOR_EXACT, $report['floor'] );
        $this->assertSame( 1, $report['withheld']['low-confidence'] );
        $this->assertSame( 0, $report['withheld']['cold-unwarmed'] );
    }
}
