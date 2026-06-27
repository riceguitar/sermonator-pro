<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\RefsCapture;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the Phase 3b `srcVersificationConfidence` envelope field
 * back-compat contract (design §3.2, task T1) against real post-meta and a real
 * decode round-trip.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env). Pins:
 *  - the backfill/auto-parse producer stamps the conservative `site-default`;
 *  - a v1 envelope written WITHOUT the field (a pre-3b row) survives untouched and
 *    reads as `site-default` through the single accessor — proving we did NOT need to
 *    bump the envelope version or rewrite existing envelopes for back-compat.
 */
final class SrcVersificationConfidenceBackCompatTest extends WP_UnitTestCase {
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

    /** @return list<array<string,mixed>> the decoded refs stored on a post */
    private function refs( int $id ): array {
        $decoded = json_decode( (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ), true );
        return is_array( $decoded ) && isset( $decoded['refs'] ) ? $decoded['refs'] : array();
    }

    public function test_producer_stamps_site_default_and_keeps_envelope_v1(): void {
        $id = $this->sermon( 'John 3:16' );

        ( new RefsCapture() )->captureForPost( $id, 'backfill' );

        $envelope = json_decode( (string) get_post_meta( $id, ID::META_BIBLE_REFS, true ), true );
        $this->assertSame( 1, $envelope['v'], 'Envelope version is NOT bumped by the new optional field.' );

        $ref = $this->refs( $id )[0];
        $this->assertSame(
            RefsCapture::SRC_VERSIFICATION_CONFIDENCE_SITE_DEFAULT,
            $ref['srcVersificationConfidence']
        );
        $this->assertSame(
            'site-default',
            RefsCapture::srcVersificationConfidence( $ref )
        );
    }

    public function test_pre_3b_envelope_without_the_field_reads_as_site_default(): void {
        // Simulate a row written before the field existed: a valid v1 envelope whose
        // refs omit srcVersificationConfidence entirely. It must NOT be rewritten and
        // must read as the conservative default through the single accessor.
        $id      = $this->sermon( 'John 3:16' );
        $legacy  = wp_json_encode( array(
            'v'    => 1,
            'refs' => array(
                array(
                    'bookUSFM'         => 'JHN',
                    'chapterStart'     => 3,
                    'verseStart'       => 16,
                    'source'           => 'backfill',
                    'confidence'       => 'probable',
                    'srcVersification' => 'ESV',
                ),
            ),
        ) );
        update_post_meta( $id, ID::META_BIBLE_REFS, $legacy );

        // Fill-missing: re-running the producer must NOT overwrite the legacy envelope.
        ( new RefsCapture() )->captureForPost( $id, 'backfill' );

        $ref = $this->refs( $id )[0];
        $this->assertArrayNotHasKey(
            'srcVersificationConfidence',
            $ref,
            'A pre-3b envelope is left byte-stable — not back-filled with the new field.'
        );
        $this->assertSame(
            'site-default',
            RefsCapture::srcVersificationConfidence( $ref ),
            'Absent field reads as the conservative site-default.'
        );
    }
}
