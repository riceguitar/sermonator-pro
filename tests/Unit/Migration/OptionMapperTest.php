<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\OptionMapper;

final class OptionMapperTest extends TestCase {
    public function test_maps_prefixed_options_and_preserves_values(): void {
        $out = OptionMapper::map( array(
            'sermonmanager_player'       => 'plyr',
            'sermonmanager_archive_slug' => 'sermons',
            'sermonmanager_date_format'  => '0',
        ) );
        $this->assertSame( 'plyr', $out['sermonator_player'] );
        $this->assertSame( 'sermons', $out['sermonator_archive_slug'] );
        $this->assertSame( '0', $out['sermonator_date_format'] );
    }

    public function test_ignores_non_sermonmanager_options(): void {
        $out = OptionMapper::map( array(
            'sermonmanager_player' => 'plyr',
            'siteurl'              => 'https://example.org',
            'some_plugin_setting'  => 'x',
        ) );
        $this->assertArrayHasKey( 'sermonator_player', $out );
        $this->assertArrayNotHasKey( 'siteurl', $out );
        $this->assertArrayNotHasKey( 'some_plugin_setting', $out );
        $this->assertCount( 1, $out );
    }

    public function test_preserves_array_and_bool_values(): void {
        $out = OptionMapper::map( array(
            'sermonmanager_itunes_categories' => array( 'Religion', 'Christianity' ),
            'sermonmanager_podtrac'           => false,
        ) );
        $this->assertSame( array( 'Religion', 'Christianity' ), $out['sermonator_itunes_categories'] );
        $this->assertFalse( $out['sermonator_podtrac'] );
    }
}
