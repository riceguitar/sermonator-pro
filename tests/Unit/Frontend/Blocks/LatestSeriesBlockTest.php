<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Blocks;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Blocks\LatestSeriesBlock;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the latest-series block (Bundle 4, Task 11).
 *
 * Proves the resolution contract:
 *   1. "latest" = the TAX_SERIES term of the most-recently-preached sermon — a 1-post
 *      WP_Query on POST_TYPE_SERMON ordered by META_DATE (meta_value_num DESC), optionally
 *      constrained by a serviceType (TAX_SERVICE_TYPE tax_query).
 *   2. The term image is resolved from OPTION_TERM_IMAGES keyed STRICTLY by term_taxonomy_id
 *      (tt_id) — matching ArtworkWriter — NOT term_id.
 *   3. Empty / no-series (no sermon, sermon has no series term) → '' (empty-state).
 *
 * WP_Query / get_the_terms / get_option / wp_get_attachment_image / get_term_link are
 * mocked; the pure Renderer runs for real (esc_* are pass-throughs).
 */
final class LatestSeriesBlockTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'get_term_link' )->justReturn( 'http://x/series/grace' );

        if ( ! class_exists( 'WP_Block' ) ) {
            eval( 'class WP_Block { public function __construct( $b = array() ) {} }' );
        }
        if ( ! class_exists( 'WP_Term' ) ) {
            eval(
                'class WP_Term { public $term_id = 0; public $term_taxonomy_id = 0;'
                . ' public $name = ""; public $description = ""; }'
            );
        }
        // WP_Query stub: captures the constructor args and serves posts from a global so each
        // test can drive resolution without a live WordPress.
        if ( ! class_exists( 'WP_Query' ) ) {
            eval(
                'class WP_Query { public $posts = array(); public function __construct( $args = array() ) {'
                . ' $GLOBALS["__sermonator_lsb_args"] = $args;'
                . ' $this->posts = $GLOBALS["__sermonator_lsb_posts"] ?? array(); } }'
            );
        }
        $GLOBALS['__sermonator_lsb_posts'] = array();
        $GLOBALS['__sermonator_lsb_args']  = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['__sermonator_lsb_posts'], $GLOBALS['__sermonator_lsb_args'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function block(): \WP_Block {
        return new \WP_Block();
    }

    private function term( int $termId, int $ttId, string $name, string $description = '' ): \WP_Term {
        $t                   = new \WP_Term();
        $t->term_id          = $termId;
        $t->term_taxonomy_id = $ttId;
        $t->name             = $name;
        $t->description      = $description;
        return $t;
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_resolves_latest_series_image_keyed_by_tt_id(): void {
        // term_id 99 differs from tt_id 55; the map is keyed by tt_id 55 → the image resolves,
        // proving tt_id keying (not term_id).
        $GLOBALS['__sermonator_lsb_posts'] = array( 123 );
        Functions\when( 'get_the_terms' )->justReturn( array(
            $this->term( 99, 55, 'Grace Series', 'A series on grace.' ),
        ) );
        Functions\when( 'get_option' )->alias(
            static fn( string $name, $default = false ) =>
                ID::OPTION_TERM_IMAGES === $name ? array( 55 => 900 ) : $default
        );
        $captured = null;
        Functions\when( 'wp_get_attachment_image' )->alias(
            static function ( int $id, $size = 'medium' ) use ( &$captured ) {
                $captured = $size;
                return 900 === $id ? '<img src="http://x/grace.jpg" alt="Grace" />' : '';
            }
        );

        $html = ( new LatestSeriesBlock() )->render( array(), '', $this->block() );

        $this->assertStringContainsString( 'sermonator-latest-series', $html );
        $this->assertStringContainsString( '<img src="http://x/grace.jpg" alt="Grace" />', $html );
        $this->assertStringContainsString( 'Grace Series', $html );
        $this->assertStringContainsString( 'A series on grace.', $html );
        // size attribute default 'large' is passed through to wp_get_attachment_image.
        $this->assertSame( 'large', $captured );

        // The query asks for the single most-recently-preached published sermon.
        $args = $GLOBALS['__sermonator_lsb_args'];
        $this->assertSame( ID::POST_TYPE_SERMON, $args['post_type'] );
        $this->assertSame( 1, $args['posts_per_page'] );
        $this->assertSame( ID::META_DATE, $args['meta_key'] );
        $this->assertSame( 'meta_value_num', $args['orderby'] );
        $this->assertSame( 'DESC', $args['order'] );
        $this->assertArrayNotHasKey( 'tax_query', $args );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_service_type_constrains_query_with_tax_query(): void {
        $GLOBALS['__sermonator_lsb_posts'] = array( 123 );
        Functions\when( 'get_the_terms' )->justReturn( array(
            $this->term( 99, 55, 'Grace Series' ),
        ) );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_get_attachment_image' )->justReturn( '' );

        ( new LatestSeriesBlock() )->render( array( 'serviceType' => 'sunday-am' ), '', $this->block() );

        $args = $GLOBALS['__sermonator_lsb_args'];
        $this->assertArrayHasKey( 'tax_query', $args );
        $this->assertSame( ID::TAX_SERVICE_TYPE, $args['tax_query'][0]['taxonomy'] );
        $this->assertSame( 'slug', $args['tax_query'][0]['field'] );
        $this->assertSame( 'sunday-am', $args['tax_query'][0]['terms'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_renders_without_image_when_no_term_image_configured(): void {
        // No configured image → the card still renders the title/description (image omitted),
        // never errors.
        $GLOBALS['__sermonator_lsb_posts'] = array( 123 );
        Functions\when( 'get_the_terms' )->justReturn( array(
            $this->term( 99, 55, 'Grace Series' ),
        ) );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_get_attachment_image' )->justReturn( '' );

        $html = ( new LatestSeriesBlock() )->render( array(), '', $this->block() );

        $this->assertStringContainsString( 'sermonator-latest-series', $html );
        $this->assertStringContainsString( 'Grace Series', $html );
        $this->assertStringNotContainsString( '<img', $html );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_no_sermon_returns_empty_string(): void {
        $GLOBALS['__sermonator_lsb_posts'] = array();
        Functions\expect( 'get_the_terms' )->never();
        Functions\when( 'get_option' )->justReturn( array() );

        $html = ( new LatestSeriesBlock() )->render( array(), '', $this->block() );

        $this->assertSame( '', $html );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sermon_without_series_term_returns_empty_string(): void {
        $GLOBALS['__sermonator_lsb_posts'] = array( 123 );
        Functions\when( 'get_the_terms' )->justReturn( false );
        Functions\when( 'get_option' )->justReturn( array() );

        $html = ( new LatestSeriesBlock() )->render( array(), '', $this->block() );

        $this->assertSame( '', $html );
    }
}
