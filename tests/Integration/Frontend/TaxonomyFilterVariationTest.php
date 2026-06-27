<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use WP_Block_Type_Registry;

/**
 * Task 12 (spec decision 8): list_sermons reuse. The existing taxonomy-filter block carries a
 * "Sermon List" editor variation so editors searching "list sermons" discover it — no redundant
 * block. The variation is declared in blocks/taxonomy-filter/block.json and surfaces through
 * register_block_type_from_metadata, so we assert it on the registered block type.
 */
final class TaxonomyFilterVariationTest extends WP_UnitTestCase {
    private function variations(): array {
        $type = WP_Block_Type_Registry::get_instance()->get_registered( 'sermonator/taxonomy-filter' );
        $this->assertNotNull( $type, 'taxonomy-filter block should be registered on init' );
        return is_array( $type->variations ) ? $type->variations : array();
    }

    private function sermonListVariation(): array {
        foreach ( $this->variations() as $variation ) {
            if ( isset( $variation['name'] ) && 'sermon-list' === $variation['name'] ) {
                return $variation;
            }
        }
        $this->fail( 'taxonomy-filter block should expose a "sermon-list" variation' );
    }

    public function test_block_exposes_sermon_list_variation(): void {
        $variation = $this->sermonListVariation();
        $this->assertSame( 'Sermon List', $variation['title'] );
    }

    public function test_variation_is_inserter_scoped_for_discoverability(): void {
        $variation = $this->sermonListVariation();
        $this->assertContains( 'inserter', (array) ( $variation['scope'] ?? array() ) );
    }

    public function test_variation_keywords_cover_list_sermons_search(): void {
        $variation = $this->sermonListVariation();
        $keywords  = array_map( 'strtolower', (array) ( $variation['keywords'] ?? array() ) );
        $this->assertContains( 'list sermons', $keywords );
        $this->assertContains( 'list_sermons', $keywords );
    }

    public function test_variation_preset_renders_a_taxonomy_term_list(): void {
        $variation = $this->sermonListVariation();
        // The preset must carry attributes that the existing render path understands — i.e. a
        // valid sermon taxonomy — so placing the variation renders a term list, not an empty block.
        $this->assertArrayHasKey( 'taxonomy', $variation['attributes'] );
        $this->assertContains(
            $variation['attributes']['taxonomy'],
            \Sermonator\Schema\Identifiers::sermonTaxonomies()
        );
    }
}
