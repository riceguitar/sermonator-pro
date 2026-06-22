<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\MappingContract;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;

final class MappingContractTest extends TestCase {
    public function test_post_type_map(): void {
        $this->assertSame(
            array(
                LegacyIdentifiers::POST_TYPE_SERMON  => Identifiers::POST_TYPE_SERMON,
                LegacyIdentifiers::POST_TYPE_PODCAST => Identifiers::POST_TYPE_PODCAST,
            ),
            MappingContract::postTypeMap()
        );
    }

    public function test_taxonomy_map_covers_all_five_in_order(): void {
        $map = MappingContract::taxonomyMap();
        $this->assertCount( 5, $map );
        $this->assertSame( Identifiers::TAX_PREACHER, $map[ LegacyIdentifiers::TAX_PREACHER ] );
        $this->assertSame( Identifiers::TAX_BOOK, $map[ LegacyIdentifiers::TAX_BOOK ] );
        $this->assertSame( Identifiers::TAX_SERVICE_TYPE, $map[ LegacyIdentifiers::TAX_SERVICE_TYPE ] );
    }

    public function test_meta_key_map_renames_known_keys(): void {
        $map = MappingContract::metaKeyMap();
        $this->assertSame( Identifiers::META_DATE, $map['sermon_date'] );
        $this->assertSame( Identifiers::META_VIDEO_EMBED, $map['sermon_video'] );
        $this->assertSame( Identifiers::META_VIDEO_URL, $map['sermon_video_link'] );
        $this->assertSame( Identifiers::META_AUDIO_DURATION, $map['_wpfc_sermon_duration'] );
        $this->assertSame( Identifiers::META_VIEWS, $map['Views'] );
    }

    public function test_meta_key_map_excludes_dropped_and_special_keys(): void {
        $map = MappingContract::metaKeyMap();
        $this->assertArrayNotHasKey( 'wpfc_service_type', $map );
        $this->assertArrayNotHasKey( 'sermon_description', $map );
    }

    public function test_dropped_meta_keys(): void {
        $dropped = MappingContract::droppedMetaKeys();
        $this->assertContains( 'wpfc_service_type', $dropped );
        $this->assertContains( 'sermon_description', $dropped );
    }

    public function test_map_option_name_swaps_prefix(): void {
        $this->assertSame( 'sermonator_player', MappingContract::mapOptionName( 'sermonmanager_player' ) );
        $this->assertSame( 'sermonator_archive_slug', MappingContract::mapOptionName( 'sermonmanager_archive_slug' ) );
    }

    public function test_map_option_name_returns_null_for_non_sermonmanager(): void {
        $this->assertNull( MappingContract::mapOptionName( 'some_other_option' ) );
        $this->assertNull( MappingContract::mapOptionName( 'sermonmanagerX' ) ); // no underscore boundary
    }
}
