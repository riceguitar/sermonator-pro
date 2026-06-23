<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\ClassicTemplates;
use Sermonator\Frontend\Shortcode;
use Sermonator\Schema\Identifiers as ID;

/**
 * Phase 2: archive/taxonomy rendering, the sermon-grid + sermon-card + taxonomy-filter
 * blocks, the [sermonator_sermons] shortcode, archive ordering, and template registration.
 */
final class Phase2Test extends WP_UnitTestCase {
    private function sermon( int $tsOffsetDays, string $title = 'S' ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => $title,
        ) );
        update_post_meta( $id, ID::META_DATE, (string) ( 1_700_000_000 + $tsOffsetDays * 86400 ) );
        return $id;
    }

    // --- Blocks --------------------------------------------------------------

    public function test_sermon_grid_block_renders_cards(): void {
        $this->sermon( 1, 'Alpha' );
        $this->sermon( 2, 'Beta' );

        $html = do_blocks( '<!-- wp:sermonator/sermon-grid {"perPage":10} /-->' );

        $this->assertStringContainsString( 'sermonator-grid', $html );
        $this->assertSame( 2, substr_count( $html, 'sermonator-card"' ) );
        $this->assertStringContainsString( 'Alpha', $html );
    }

    public function test_sermon_grid_block_filters_by_taxonomy(): void {
        $a    = $this->sermon( 1, 'Has Term' );
        $this->sermon( 2, 'No Term' );
        $term = (int) self::factory()->term->create( array( 'taxonomy' => ID::TAX_SERIES, 'name' => 'Lent', 'slug' => 'lent' ) );
        wp_set_object_terms( $a, array( $term ), ID::TAX_SERIES );

        $html = do_blocks( '<!-- wp:sermonator/sermon-grid {"series":"lent"} /-->' );

        $this->assertStringContainsString( 'Has Term', $html );
        $this->assertStringNotContainsString( 'No Term', $html );
    }

    public function test_sermon_card_block_renders_card_for_context_post(): void {
        $id   = $this->sermon( 1, 'Carded' );
        $html = do_blocks( '<!-- wp:sermonator/sermon-card {"postId":' . $id . '} /-->' );
        $this->assertStringContainsString( 'sermonator-card', $html );
        $this->assertStringContainsString( 'Carded', $html );
    }

    public function test_taxonomy_filter_block_lists_terms(): void {
        $a    = $this->sermon( 1 );
        $term = (int) self::factory()->term->create( array( 'taxonomy' => ID::TAX_PREACHER, 'name' => 'Pastor Zed', 'slug' => 'pastor-zed' ) );
        wp_set_object_terms( $a, array( $term ), ID::TAX_PREACHER );

        $html = do_blocks( '<!-- wp:sermonator/taxonomy-filter {"taxonomy":"' . ID::TAX_PREACHER . '"} /-->' );

        $this->assertStringContainsString( 'sermonator-termlist', $html );
        $this->assertStringContainsString( 'Pastor Zed', $html );
    }

    public function test_taxonomy_filter_block_rejects_unknown_taxonomy(): void {
        $html = trim( do_blocks( '<!-- wp:sermonator/taxonomy-filter {"taxonomy":"category"} /-->' ) );
        $this->assertSame( '', $html );
    }

    // --- Shortcode -----------------------------------------------------------

    public function test_shortcode_renders_grid(): void {
        $this->sermon( 1, 'ShortcodeOne' );
        $html = do_shortcode( '[sermonator_sermons count="5" columns="2"]' );
        $this->assertStringContainsString( 'sermonator-grid', $html );
        $this->assertStringContainsString( 'data-columns="2"', $html );
        $this->assertStringContainsString( 'ShortcodeOne', $html );
    }

    public function test_shortcode_filters_by_taxonomy(): void {
        $a    = $this->sermon( 1, 'Keep' );
        $this->sermon( 2, 'Drop' );
        $term = (int) self::factory()->term->create( array( 'taxonomy' => ID::TAX_TOPIC, 'name' => 'Grace', 'slug' => 'grace' ) );
        wp_set_object_terms( $a, array( $term ), ID::TAX_TOPIC );

        $html = do_shortcode( '[sermonator_sermons topic="grace"]' );

        $this->assertStringContainsString( 'Keep', $html );
        $this->assertStringNotContainsString( 'Drop', $html );
    }

    // --- Archive ordering ----------------------------------------------------

    public function test_archive_main_query_ordered_by_preached_date(): void {
        $older = $this->sermon( 0, 'Older' );
        $newer = $this->sermon( 30, 'Newer' );

        $this->go_to( home_url( '/?post_type=' . ID::POST_TYPE_SERMON ) );

        $this->assertTrue( is_post_type_archive( ID::POST_TYPE_SERMON ) );
        global $wp_query;
        $ids = wp_list_pluck( $wp_query->posts, 'ID' );
        $this->assertSame( $newer, (int) $ids[0], 'Newest preached date first on the archive.' );
        $this->assertSame( $older, (int) $ids[1] );
    }

    // --- Templates -----------------------------------------------------------

    public function test_archive_and_taxonomy_block_templates_registered(): void {
        $all   = get_block_templates( array(), 'wp_template' );
        $slugs = array_map( static fn( $t ) => $t->slug, $all );

        $this->assertContains( 'archive-' . ID::POST_TYPE_SERMON, $slugs );
        $this->assertContains( 'taxonomy-' . ID::TAX_PREACHER, $slugs );
        $this->assertContains( 'taxonomy-' . ID::TAX_SERIES, $slugs );
    }

    public function test_classic_archive_template_points_at_plugin_file(): void {
        $this->sermon( 1 );
        $this->go_to( home_url( '/?post_type=' . ID::POST_TYPE_SERMON ) );

        $resolved = ( new ClassicTemplates() )->archiveTemplate( '/themes/x/archive.php' );
        $this->assertStringContainsString( 'archive-sermonator-sermon.php', $resolved );
    }
}
