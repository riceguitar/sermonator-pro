<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Bible\DerivedExactClassifier as DEC;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the READ-ONLY would-promote PREVIEW in
 * {@see CoverageAudit::promotionPreview()} (design §4 / spec T-E). NOT run in this
 * environment (no Docker / wp-env) — authored to run under wp-env later.
 *
 * Drives the real `get_post_meta` / `get_option` / `WP_Query` stack (no Brain Monkey) to
 * prove the preview spans all three floors in one corpus pass, surfaces the assume-attested
 * ceiling, fires the heterogeneity canary, returns the axis-2 sample, and WRITES NOTHING.
 *
 * ## The chapter-free L2 oracle (same trick as {@see CoverageAuditL2LockstepTest})
 *
 * Chapters are NOT vendored under wp-env, so the L8 offline read misses and a ref that
 * CLEARS L2 (and L3–L7) lands in `cold-unwarmed`, while a ref WITHHELD at L2 lands in
 * `low-confidence`. That split is a precise oracle for the per-floor promotion decision
 * WITHOUT vendored text. The {@see self::test_sample_*} case injects a warm chapter
 * resolver (the constructor's second arg) so a promoted ref becomes fully inline-eligible
 * and therefore sample-able.
 */
final class CoveragePromotionPreviewTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option( ID::OPTION_BIBLE_STATS );
        delete_option( ID::OPTION_BIBLE_INLINE_CONFIDENCE_FLOOR );
        delete_option( ID::OPTION_BIBLE_INLINE_ATTESTATION );
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
            'post_title'  => 'preview sermon',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'passage' );
        update_post_meta( $id, ID::META_BIBLE_REFS, (string) wp_json_encode( array( 'v' => 1, 'refs' => $refs ) ) );

        return $id;
    }

    /**
     * A chapter resolver that reports every requested chapter as carrying verses 1..40
     * (so a promoted ref clears L8/L9 and becomes inline-eligible).
     *
     * @return callable(string,string,int,bool):array<int,mixed>
     */
    private function warmChapter(): callable {
        return static function ( $t, $b, $c, $w ): array {
            $verses = array();
            for ( $n = 1; $n <= 40; $n++ ) {
                $verses[] = array( 'number' => $n, 'nodes' => array( array( 'type' => 'text', 'text' => 'word' ) ) );
            }
            return $verses;
        };
    }

    public function test_three_floors_in_one_pass_with_strict_vs_perseg_delta(): void {
        // Compound (2 clean probable refs) — promotes per-segment but NOT under strict.
        $this->sermonWithRefs( array(
            $this->ref( 16, 'John 3:16', 'probable' ),
            $this->ref( 17, 'John 3:17', 'probable' ),
        ) );
        // Lone clean probable — promotes under strict and perseg.
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $preview = ( new CoverageAudit() )->promotionPreview( true );

        $this->assertSame( 3, $preview['refs_total'] );

        // would-promote: exact 0, strict 1 (the lone), perseg 3 (compound 2 + lone 1).
        $this->assertSame( 0, $preview['floors']['exact']['would_promote'] );
        $this->assertSame( 1, $preview['floors']['derived-exact']['would_promote'] );
        $this->assertSame( 3, $preview['floors']['derived-exact-perseg']['would_promote'] );

        // L2 oracle (chapters unvendored): a withheld L2 ref is low-confidence; a promoted
        // one reaches cold-unwarmed.
        $this->assertSame( 3, $preview['floors']['exact']['withheld']['low-confidence'] );
        $this->assertSame( 0, $preview['floors']['exact']['withheld']['cold-unwarmed'] );

        $this->assertSame( 2, $preview['floors']['derived-exact']['withheld']['low-confidence'] );
        $this->assertSame( 1, $preview['floors']['derived-exact']['withheld']['cold-unwarmed'] );

        $this->assertSame( 0, $preview['floors']['derived-exact-perseg']['withheld']['low-confidence'] );
        $this->assertSame( 3, $preview['floors']['derived-exact-perseg']['withheld']['cold-unwarmed'] );
    }

    public function test_assume_attested_ceiling_surfaces_unattested_when_false(): void {
        // A lone EXACT, site-default ESV ref. Attestation OFF in the DB; the ceiling lever
        // (assumeAttested) decides whether L6 is assumed passed.
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact' ) ) );

        $audit = new CoverageAudit();

        // assume-attested TRUE: clears L6 → reaches cold-unwarmed (L8), never unattested.
        $ceiling = $audit->promotionPreview( true );
        $this->assertTrue( $ceiling['assume_attested'] );
        $this->assertSame( 1, $ceiling['floors']['exact']['withheld']['cold-unwarmed'] );
        $this->assertSame( 0, $ceiling['floors']['exact']['withheld']['src-versification-unattested'] );

        // assume-attested FALSE: the real pre-attestation state — withheld at L6.
        $actual = $audit->promotionPreview( false );
        $this->assertFalse( $actual['assume_attested'] );
        $this->assertSame( 1, $actual['floors']['exact']['withheld']['src-versification-unattested'] );
        $this->assertSame( 0, $actual['floors']['exact']['withheld']['cold-unwarmed'] );
    }

    public function test_heterogeneous_canary_fires_on_mixed_family_corpus(): void {
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact', array( 'srcVersification' => 'ESV' ) ) ) );
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact', array( 'srcVersification' => 'RVR1960' ) ) ) );

        $preview = ( new CoverageAudit() )->promotionPreview( true );

        $this->assertTrue( $preview['heterogeneous'] );
        $this->assertArrayHasKey( 'eng-protestant', $preview['families'] );
        $this->assertArrayHasKey( 'unknown', $preview['families'] );
    }

    public function test_sample_returns_promoted_refs_with_raw(): void {
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );
        $this->sermonWithRefs( array( $this->ref( 17, 'John 3:17', 'probable' ) ) );

        // Inject a warm chapter resolver so the promoted refs become inline-eligible (and
        // therefore enter the axis-2 sample) without vendored text.
        $preview = ( new CoverageAudit( null, $this->warmChapter() ) )->promotionPreview( true, 1 );

        $this->assertCount( 1, $preview['sample'] );
        $entry = $preview['sample'][0];
        $this->assertArrayHasKey( 'raw', $entry );
        $this->assertNotSame( '', $entry['raw'] );
        $this->assertSame( 'JHN', $entry['bookUSFM'] );
    }

    public function test_preview_writes_nothing(): void {
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        ( new CoverageAudit() )->promotionPreview( true, 5 );

        // No write-on-GET: the read-only preview must not persist the rollup option.
        $this->assertFalse( get_option( ID::OPTION_BIBLE_STATS, false ) );
    }
}
