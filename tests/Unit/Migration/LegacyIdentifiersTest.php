<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers;

final class LegacyIdentifiersTest extends TestCase {
    public function test_legacy_post_types(): void {
        $this->assertSame( 'wpfc_sermon', LegacyIdentifiers::POST_TYPE_SERMON );
        $this->assertSame( 'wpfc_sm_podcast', LegacyIdentifiers::POST_TYPE_PODCAST );
    }

    public function test_legacy_taxonomies_in_canonical_order(): void {
        $this->assertSame(
            array( 'wpfc_preacher', 'wpfc_sermon_series', 'wpfc_sermon_topics', 'wpfc_bible_book', 'wpfc_service_type' ),
            LegacyIdentifiers::sermonTaxonomies()
        );
    }

    public function test_legacy_taxonomies_align_one_to_one_with_target(): void {
        // Position i in the legacy list must map to position i in the target list.
        $this->assertSame(
            count( LegacyIdentifiers::sermonTaxonomies() ),
            count( Identifiers::sermonTaxonomies() )
        );
    }

    public function test_legacy_meta_keys_include_body_and_denormalized_service_type(): void {
        $keys = LegacyIdentifiers::sermonMetaKeys();
        $this->assertContains( 'sermon_description', $keys );
        $this->assertContains( 'wpfc_service_type', $keys );
        $this->assertContains( 'Views', $keys );
        $this->assertContains( '_wpfc_sermon_duration', $keys );
    }

    public function test_legacy_option_prefix(): void {
        $this->assertSame( 'sermonmanager_', LegacyIdentifiers::OPTION_PREFIX );
    }

    public function test_artwork_option_names(): void {
        $this->assertSame( 'sermon_image_plugin', LegacyIdentifiers::OPTION_TERM_IMAGES );
        $this->assertSame( 'wpfc_sm_default_podcast', LegacyIdentifiers::OPTION_DEFAULT_PODCAST );
        $this->assertSame( 'sm_podcast_settings', LegacyIdentifiers::META_PODCAST_SETTINGS );
    }

    public function test_crosswalk_keys_are_prefixed_hidden_meta(): void {
        $this->assertSame( '_sermonator_legacy_id', Crosswalk::LEGACY_POST_ID );
        $this->assertSame( '_sermonator_legacy_term_id', Crosswalk::LEGACY_TERM_ID );
    }

    public function test_target_additions_on_identifiers(): void {
        $this->assertSame( 'sermonator_', Identifiers::OPTION_PREFIX );
        $this->assertSame( 'sermonator_podcast_settings', Identifiers::META_PODCAST_SETTINGS );
        $this->assertSame( 'sermonator_default_podcast', Identifiers::OPTION_DEFAULT_PODCAST );
        $this->assertSame( 'sermonator_term_images', Identifiers::OPTION_TERM_IMAGES );
    }
}
