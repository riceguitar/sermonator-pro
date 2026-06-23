<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\SermonMetaMapper;
use Sermonator\Schema\Identifiers;

final class SermonMetaMapperTest extends TestCase {
    public function test_renames_known_keys(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_date'       => array( '1612137600' ),
            'sermon_video_link' => array( 'https://youtu.be/x' ),
            '_wpfc_sermon_duration' => array( '00:32:10' ),
        ) );
        $this->assertSame( array( '1612137600' ), $out['meta'][ Identifiers::META_DATE ] );
        $this->assertSame( array( 'https://youtu.be/x' ), $out['meta'][ Identifiers::META_VIDEO_URL ] );
        $this->assertSame( array( '00:32:10' ), $out['meta'][ Identifiers::META_AUDIO_DURATION ] );
    }

    public function test_extracts_description_and_does_not_keep_it_as_meta(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_description' => array( '<p>The body</p>' ),
        ) );
        $this->assertSame( '<p>The body</p>', $out['description'] );
        $this->assertArrayNotHasKey( 'sermonator_description', $out['meta'] );
        $this->assertArrayNotHasKey( 'sermon_description', $out['meta'] );
    }

    public function test_description_null_when_absent(): void {
        $out = SermonMetaMapper::map( array() );
        $this->assertNull( $out['description'] );
    }

    public function test_drops_denormalized_service_type_meta(): void {
        $out = SermonMetaMapper::map( array(
            'wpfc_service_type' => array( '42' ),
        ) );
        $this->assertArrayNotHasKey( 'wpfc_service_type', $out['meta'] );
        $this->assertArrayNotHasKey( Identifiers::TAX_SERVICE_TYPE, $out['meta'] );
    }

    public function test_passes_through_unknown_meta_verbatim(): void {
        $out = SermonMetaMapper::map( array(
            '_yoast_wpseo_title' => array( 'SEO title' ),
            'custom_field'       => array( 'a', 'b' ),
        ) );
        $this->assertSame( array( 'SEO title' ), $out['meta']['_yoast_wpseo_title'] );
        $this->assertSame( array( 'a', 'b' ), $out['meta']['custom_field'] );
    }

    public function test_preserves_multiple_values_for_a_key(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_notes' => array( 'a.pdf', 'b.pdf' ),
        ) );
        $this->assertSame( array( 'a.pdf', 'b.pdf' ), $out['meta'][ Identifiers::META_NOTES ] );
    }

    public function test_flags_nonnumeric_legacy_date(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_date' => array( '01/05/2021' ),
        ) );
        $this->assertContains( 'legacy_nonnumeric_date', $out['flags'] );
        // Raw value is still carried verbatim — never guessed-away.
        $this->assertSame( array( '01/05/2021' ), $out['meta'][ Identifiers::META_DATE ] );
    }

    public function test_no_flag_for_numeric_date(): void {
        $out = SermonMetaMapper::map( array( 'sermon_date' => array( '1612137600' ) ) );
        $this->assertNotContains( 'legacy_nonnumeric_date', $out['flags'] );
    }

    public function test_flags_when_a_later_date_row_is_nonnumeric(): void {
        // First row numeric, later row non-numeric: the flag must still fire (scan
        // EVERY row, not just $values[0]), to agree with the per-row companion set.
        $out = SermonMetaMapper::map( array(
            'sermon_date' => array( '1612137600', '01/05/2021' ),
        ) );
        $this->assertContains( 'legacy_nonnumeric_date', $out['flags'] );
    }

    public function test_flag_not_duplicated_for_multiple_nonnumeric_rows(): void {
        $out = SermonMetaMapper::map( array(
            'sermon_date' => array( '01/05/2021', '03/14/2022' ),
        ) );
        $this->assertSame(
            array( 'legacy_nonnumeric_date' ),
            array_values( array_filter( $out['flags'], static fn ( $f ) => 'legacy_nonnumeric_date' === $f ) )
        );
    }
}
