<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Blocks;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Blocks\SermonImagesBlock;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the sermon-images term-image-grid block (Bundle 4, Task 10).
 *
 * Proves the two load-bearing contracts:
 *   1. OPTION_TERM_IMAGES is keyed STRICTLY by term_taxonomy_id (tt_id) — matching
 *      ArtworkWriter — NOT term_id. A term whose term_id collides with a map key but whose
 *      tt_id does not must NOT resolve an image.
 *   2. Absent/empty option OR zero resolved images → the safe sermon list + the editor
 *      "needs review" notice, never a blank grid (#1 data preservation).
 *
 * get_option / get_terms / wp_get_attachment_image are mocked; the pure Renderer runs for
 * real (esc_* are pass-throughs), so the assertions cover the real resolution path.
 */
final class SermonImagesBlockTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Pure-renderer escaping helpers: identity pass-throughs.
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( '__' )->returnArg();
        Functions\when( 'is_wp_error' )->justReturn( false );

        // Stub the global WP_Block the render() signature requires (unit context has none).
        if ( ! class_exists( 'WP_Block' ) ) {
            eval( 'class WP_Block { public function __construct( $b = array() ) {} }' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return \WP_Block */
    private function block(): \WP_Block {
        return new \WP_Block();
    }

    private function term( int $termId, int $ttId, string $name, string $description = '' ): object {
        return (object) array(
            'term_id'          => $termId,
            'term_taxonomy_id' => $ttId,
            'name'             => $name,
            'description'      => $description,
        );
    }

    public function test_image_option_is_keyed_by_tt_id_not_term_id(): void {
        // The map key 55 is a tt_id. Term A has tt_id 55 (matches). Term B has term_id 55
        // but tt_id 77 (must NOT match — proves tt_id keying, not term_id).
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                ID::OPTION_TERM_IMAGES === $name ? array( 55 => 900 ) : $default
        );
        Functions\when( 'get_terms' )->justReturn( array(
            $this->term( 10, 55, 'Grace Series' ),
            $this->term( 55, 77, 'Hope Series' ),
        ) );
        Functions\when( 'wp_get_attachment_image' )->alias(
            static fn( int $id, $size = 'medium' ) =>
                900 === $id ? '<img src="http://x/grace.jpg" alt="Grace" />' : ''
        );
        Functions\when( 'get_term_link' )->justReturn( 'http://x/series/grace' );

        $html = ( new SermonImagesBlock() )->render( array(), '', $this->block() );

        // Rendered the real image grid (not the fallback).
        $this->assertStringContainsString( 'sermonator-image-grid', $html );
        $this->assertStringContainsString( '<img src="http://x/grace.jpg" alt="Grace" />', $html );
        $this->assertStringContainsString( 'Grace Series', $html );
        // The term whose term_id (not tt_id) equals the map key must NOT appear.
        $this->assertStringNotContainsString( 'Hope Series', $html );
        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
    }

    public function test_size_attribute_is_passed_to_attachment_image(): void {
        $captured = null;
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                ID::OPTION_TERM_IMAGES === $name ? array( 55 => 900 ) : $default
        );
        Functions\when( 'get_terms' )->justReturn( array( $this->term( 10, 55, 'Grace' ) ) );
        Functions\when( 'get_term_link' )->justReturn( 'http://x/series/grace' );
        Functions\when( 'wp_get_attachment_image' )->alias(
            static function ( int $id, $size = 'medium' ) use ( &$captured ) {
                $captured = $size;
                return '<img alt="Grace" />';
            }
        );

        ( new SermonImagesBlock() )->render( array( 'size' => 'large' ), '', $this->block() );

        $this->assertSame( 'large', $captured );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_empty_option_falls_back_to_safe_list_with_notice(): void {
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) => $default
        );
        // get_terms must never run on the empty-option fast path.
        Functions\expect( 'get_terms' )->never();
        $this->stubSafeListDeps();

        $html = ( new SermonImagesBlock() )->render( array(), '', $this->block() );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringNotContainsString( 'sermonator-image-grid', $html );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_zero_resolved_images_falls_back_to_safe_list_with_notice(): void {
        // Option is populated but NO term tt_id matches a map key → zero images → fallback,
        // never a blank grid.
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                ID::OPTION_TERM_IMAGES === $name ? array( 999 => 900 ) : $default
        );
        Functions\when( 'get_terms' )->justReturn( array(
            $this->term( 10, 55, 'Grace Series' ),
        ) );
        Functions\when( 'wp_get_attachment_image' )->justReturn( '' );
        $this->stubSafeListDeps();

        $html = ( new SermonImagesBlock() )->render( array(), '', $this->block() );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringNotContainsString( 'sermonator-image-grid', $html );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_missing_attachment_is_skipped_then_falls_back(): void {
        // tt_id matches but the attachment is gone (wp_get_attachment_image → '') → the term
        // is skipped; with no other images the block falls back rather than render an empty cell.
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                ID::OPTION_TERM_IMAGES === $name ? array( 55 => 900 ) : $default
        );
        Functions\when( 'get_terms' )->justReturn( array(
            $this->term( 10, 55, 'Grace Series' ),
        ) );
        Functions\when( 'wp_get_attachment_image' )->justReturn( '' );
        Functions\when( 'get_term_link' )->justReturn( 'http://x/series/grace' );
        $this->stubSafeListDeps();

        $html = ( new SermonImagesBlock() )->render( array(), '', $this->block() );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringNotContainsString( 'sermonator-image-grid', $html );
    }

    /**
     * Stub the dependencies of the safe-list fallback path (Shortcode::render → SermonQuery →
     * Renderer::grid empty-state) plus LegacyShortcodes::needsReviewNotice so the fallback
     * resolves without a live WordPress.
     */
    private function stubSafeListDeps(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_style_is' )->justReturn( false );
        Functions\when( 'shortcode_atts' )->returnArg();
        Functions\when( 'get_query_var' )->justReturn( 0 );

        // SermonQuery instantiates WP_Query; return an empty result set so Renderer::grid
        // emits its empty-state markup (no DB needed).
        if ( ! class_exists( 'WP_Query' ) ) {
            eval(
                'class WP_Query { public $posts = array(); public $found_posts = 0;'
                . ' public $max_num_pages = 0; public function __construct( $args = array() ) {} }'
            );
        }
    }
}
