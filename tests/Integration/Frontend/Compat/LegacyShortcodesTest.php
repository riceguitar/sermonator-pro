<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Compat;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * Legacy tags are registered by FrontendServiceProvider on init (booted by the plugin), so
 * do_shortcode() exercises the end-to-end shim. The Compatibility Contract's load-bearing rule
 * is fail-visible: a migrated page must never print raw "[sermons]" text, and the editor notice
 * must be invisible to visitors.
 *
 * Bundle 4 (Task 13): [list_sermons]/[sermon_images]/[latest_series] now delegate to the
 * FAITHFUL display blocks (term list / image grid / latest-series card) rather than the safe
 * sermon list, while KEEPING a reworded per-tag review notice. [sermons]/[sermons_sm] stay on
 * the generic safe list (Bundle 2, unchanged).
 */
final class LegacyShortcodesTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        delete_option( ID::OPTION_TERM_IMAGES );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function makeSermon( ?int $seriesTermId = null ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => 'The Light Has Come',
        ) );
        update_post_meta( $id, ID::META_DATE, '1700000000' );
        if ( null !== $seriesTermId ) {
            wp_set_object_terms( $id, array( $seriesTermId ), ID::TAX_SERIES );
        }
        return $id;
    }

    /** The series term + its tt_id, so OPTION_TERM_IMAGES can be keyed exactly like ArtworkWriter. */
    private function makeSeriesTerm( string $name = 'Advent' ): array {
        $term = wp_insert_term( $name, ID::TAX_SERIES );
        $this->assertIsArray( $term );
        $termId = (int) $term['term_id'];
        $ttId   = (int) $term['term_taxonomy_id'];
        return array( $termId, $ttId );
    }

    public function test_sermons_tag_resolves_to_the_sermon_list_not_raw_text(): void {
        $this->makeSermon();

        $html = do_shortcode( '[sermons]' );

        $this->assertStringContainsString( 'sermonator-grid', $html );
        $this->assertStringNotContainsString( '[sermons]', $html );
    }

    /** Bundle 2 tags still render the generic safe sermon grid. */
    public function test_bundle2_tags_resolve_to_the_sermon_grid(): void {
        $this->makeSermon();

        foreach ( array( '[sermons]', '[sermons_sm]' ) as $tag ) {
            $html = do_shortcode( $tag );
            $this->assertStringNotContainsString( $tag, $html, "$tag rendered as raw text" );
            $this->assertStringContainsString( 'sermonator-grid', $html, "$tag did not render the sermon grid" );
        }
    }

    /** None of the faithful tags print raw shortcode text. */
    public function test_faithful_tags_never_render_as_raw_text(): void {
        $this->makeSermon();

        foreach ( array( '[list_sermons]', '[latest_series]', '[sermon_images]' ) as $tag ) {
            $html = do_shortcode( $tag );
            $this->assertStringNotContainsString( $tag, $html, "$tag rendered as raw text" );
        }
    }

    /**
     * [list_sermons] delegates to the faithful taxonomy term-list block (not the sermon grid),
     * with the per-tag notice for an editor.
     */
    public function test_list_sermons_renders_the_term_list_with_notice(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        list( $termId ) = $this->makeSeriesTerm( 'Grace' );
        $this->makeSermon( $termId );

        $html = do_shortcode( '[list_sermons]' );

        $this->assertStringContainsString( 'sermonator-termlist', $html );
        $this->assertStringContainsString( 'Grace', $html );
        $this->assertStringNotContainsString( 'sermonator-grid', $html );
        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
    }

    public function test_list_sermons_omits_notice_for_visitor(): void {
        wp_set_current_user( 0 );
        list( $termId ) = $this->makeSeriesTerm( 'Grace' );
        $this->makeSermon( $termId );

        $html = do_shortcode( '[list_sermons]' );

        $this->assertStringContainsString( 'sermonator-termlist', $html );
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
    }

    /**
     * [sermon_images] delegates to the faithful term-image grid keyed by term_taxonomy_id
     * (matching ArtworkWriter), with the per-tag notice for an editor.
     */
    public function test_sermon_images_renders_the_image_grid_with_notice(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );

        list( $termId, $ttId ) = $this->makeSeriesTerm( 'Advent' );
        $this->makeSermon( $termId );
        $attachment = (int) self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );
        // OPTION_TERM_IMAGES is keyed by tt_id, NOT term_id (the #1-data-preservation key).
        update_option( ID::OPTION_TERM_IMAGES, array( $ttId => $attachment ) );

        $html = do_shortcode( '[sermon_images]' );

        $this->assertStringContainsString( 'sermonator-image-grid', $html );
        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringNotContainsString( '[sermon_images]', $html );
    }

    /**
     * [sermon_images] with no migrated artwork falls back to the safe sermon list (never a
     * blank grid) and is fail-visible to an editor.
     */
    public function test_sermon_images_falls_back_to_safe_list_when_no_artwork(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        $this->makeSermon();

        $html = do_shortcode( '[sermon_images]' );

        $this->assertStringNotContainsString( '[sermon_images]', $html );
        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        // Empty option → safe sermon list fallback, not a blank grid.
        $this->assertStringContainsString( 'sermonator-grid', $html );
    }

    /**
     * [latest_series] delegates to the faithful latest-series card (the most-recently-preached
     * sermon's series), with the per-tag notice for an editor.
     */
    public function test_latest_series_renders_the_card_with_notice(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );

        list( $termId ) = $this->makeSeriesTerm( 'Easter' );
        $this->makeSermon( $termId );

        $html = do_shortcode( '[latest_series]' );

        $this->assertStringContainsString( 'sermonator-latest-series', $html );
        $this->assertStringContainsString( 'Easter', $html );
        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringNotContainsString( 'sermonator-grid', $html );
    }

    public function test_latest_series_omits_notice_for_visitor(): void {
        wp_set_current_user( 0 );
        list( $termId ) = $this->makeSeriesTerm( 'Easter' );
        $this->makeSermon( $termId );

        $html = do_shortcode( '[latest_series]' );

        $this->assertStringContainsString( 'sermonator-latest-series', $html );
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
    }

    public function test_list_podcasts_renders_subscribe_links_not_the_sermon_list(): void {
        $podcast = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_status' => 'publish',
        ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $podcast );

        $html = do_shortcode( '[list_podcasts]' );

        $this->assertStringContainsString( 'sermonator-subscribe', $html );
        $this->assertStringNotContainsString( 'sermonator-grid', $html );
        $this->assertStringNotContainsString( '[list_podcasts]', $html );
        // Subscribe surface is faithful at Tier A, so it carries no review notice.
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
    }
}
