<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\RefsCapture;
use Sermonator\Bible\VersificationGate;
use Sermonator\Schema\BibleTranslations;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for {@see VersificationGate} composed with the REAL stored
 * artefacts it gates against — a decoded {@see ID::META_BIBLE_REFS} envelope and the
 * live {@see RefsCapture::srcVersificationConfidence()} accessor — under a real
 * post-meta round-trip (no Brain Monkey).
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). Pins the
 * end-to-end never-fail-WRONG contract the pure unit test cannot: that a backfill
 * producer's site-default envelope is correctly WITHHELD until the admin attests,
 * and that the headline correction (the Psalter is NOT blanket-divergent for the
 * English↔English pair) holds for a ref read straight off a stored post.
 *
 * The gate itself is pure; this exercises it against bytes that actually reach
 * production (the producer's stamp, the v1 envelope shape, the back-compat default).
 */
final class VersificationGateTest extends WP_UnitTestCase {
    private const TARGET = BibleTranslations::DEFAULT_INLINE; // ENGWEBP

    protected function setUp(): void {
        parent::setUp();
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );
    }

    protected function tearDown(): void {
        delete_option( ID::OPTION_BIBLE_LINK_VERSION );
        parent::tearDown();
    }

    private function sermon( string $passage ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, $passage );
        return $id;
    }

    /** @return array<string,mixed> the first decoded ref stored on a post */
    private function firstRef( int $id ): array {
        $decoded = json_decode( (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ), true );
        $refs    = is_array( $decoded ) && isset( $decoded['refs'] ) ? $decoded['refs'] : array();
        return is_array( $refs[0] ?? null ) ? $refs[0] : array();
    }

    public function test_backfilled_site_default_ref_is_withheld_until_attested(): void {
        // A real producer pass stamps source=backfill, srcVersification=ESV,
        // srcVersificationConfidence=site-default.
        $id = $this->sermon( 'John 3:16' );
        ( new RefsCapture() )->captureForPost( $id, 'backfill' );

        $ref = $this->firstRef( $id );
        $this->assertSame( 'ESV', $ref['srcVersification'] );
        $this->assertSame(
            RefsCapture::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT,
            RefsCapture::srcVersificationConfidence( $ref )
        );

        // Not attested → withheld for provenance (L6).
        $withheld = VersificationGate::eligible( $ref, self::TARGET, false );
        $this->assertFalse( $withheld['eligible'] );
        $this->assertSame( VersificationGate::REASON_UNATTESTED, $withheld['reason'] );

        // Admin attests → the same stored ref now clears the gate.
        $attested = VersificationGate::eligible( $ref, self::TARGET, true );
        $this->assertTrue( $attested['eligible'] );
        $this->assertNull( $attested['reason'] );
    }

    public function test_pre_3b_envelope_without_confidence_field_is_withheld_until_attested(): void {
        // A pre-3b row whose refs omit srcVersificationConfidence must read as the
        // conservative site-default and therefore require attestation.
        $id     = $this->sermon( 'John 3:16' );
        $legacy = wp_json_encode( array(
            'v'    => 1,
            'refs' => array(
                array(
                    'bookUSFM'         => 'JHN',
                    'chapterStart'     => 3,
                    'verseStart'       => 16,
                    'source'           => 'backfill',
                    'srcVersification' => 'ESV',
                ),
            ),
        ) );
        update_post_meta( $id, ID::META_BIBLE_REFS, $legacy );

        $ref = $this->firstRef( $id );
        $this->assertArrayNotHasKey( 'srcVersificationConfidence', $ref );

        $result = VersificationGate::eligible( $ref, self::TARGET, false );
        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_UNATTESTED, $result['reason'] );
    }

    public function test_backfilled_psalm_is_inline_eligible_once_attested(): void {
        // The headline correction, end-to-end: a stored Psalm ref (the 3a unary
        // table darkened the WHOLE Psalter) is NOT divergent for the English↔English
        // pair and clears once attested.
        $id = $this->sermon( 'Psalm 23:1' );
        ( new RefsCapture() )->captureForPost( $id, 'backfill' );

        $ref    = $this->firstRef( $id );
        $this->assertSame( 'PSA', $ref['bookUSFM'] );

        $result = VersificationGate::eligible( $ref, self::TARGET, true );
        $this->assertTrue( $result['eligible'], 'Psalms are eligible for the English↔English pair.' );
        $this->assertNull( $result['reason'] );
    }

    public function test_backfilled_foreign_source_version_is_unsupported(): void {
        // A church whose legacy verse_bible_version is Spanish → every backfilled
        // ref carries a foreign srcVersification → L4 withholds, regardless of
        // attestation.
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'RVR1960' );

        $id = $this->sermon( 'John 3:16' );
        ( new RefsCapture() )->captureForPost( $id, 'backfill' );

        $ref    = $this->firstRef( $id );
        $this->assertSame( 'RVR1960', $ref['srcVersification'] );

        $result = VersificationGate::eligible( $ref, self::TARGET, true );
        $this->assertFalse( $result['eligible'] );
        $this->assertSame( VersificationGate::REASON_SRC_UNSUPPORTED, $result['reason'] );
    }
}
