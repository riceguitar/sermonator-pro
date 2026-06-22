<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\Detector;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

final class DetectorTest extends WP_UnitTestCase {
    private LegacyFixture $fx;

    public function set_up(): void {
        parent::set_up();
        $this->fx = new LegacyFixture();
        $this->fx->registerLegacySchema();
    }

    public function test_has_legacy_data_false_on_empty(): void {
        $this->assertFalse( ( new Detector() )->hasLegacyData() );
    }

    public function test_counts_sermons_terms_podcasts(): void {
        $this->fx->createSermon();
        $this->fx->createSermon();
        $this->fx->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor A' );
        $this->fx->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor B' );
        $this->fx->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );
        $this->fx->createPodcast();

        $manifest = ( new Detector() )->detect();

        $this->assertTrue( ( new Detector() )->hasLegacyData() );
        $this->assertSame( 2, $manifest->count( 'sermons' ) );
        $this->assertSame( 2, $manifest->count( 'terms_wpfc_preacher' ) );
        $this->assertSame( 1, $manifest->count( 'terms_wpfc_sermon_series' ) );
        $this->assertSame( 1, $manifest->count( 'podcasts' ) );
    }

    public function test_counts_options_and_artwork(): void {
        $this->fx->createSermon();
        $this->fx->setOption( 'sermonmanager_player', 'plyr' );
        $this->fx->setOption( 'sermonmanager_archive_slug', 'sermons' );
        $this->fx->setOption( LegacyIdentifiers::OPTION_TERM_IMAGES, array( 101 => 555, 102 => 556 ) );

        $manifest = ( new Detector() )->detect();
        $this->assertSame( 2, $manifest->count( 'options' ) );
        $this->assertSame( 2, $manifest->count( 'artwork' ) );
    }

    public function test_records_a_checksum_per_sermon(): void {
        $id = $this->fx->createSermon();
        $manifest = ( new Detector() )->detect();
        $this->assertNotNull( $manifest->checksum( $id ) );
    }

    public function test_detect_does_not_write(): void {
        $id = $this->fx->createSermon();
        $before = get_post_meta( $id );
        ( new Detector() )->detect();
        $this->assertEquals( $before, get_post_meta( $id ), 'detect() must not modify legacy data' );
    }
}
