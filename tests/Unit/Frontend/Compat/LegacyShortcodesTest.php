<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Compat;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Compat\LegacyShortcodes;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the legacy-shortcode shims (Bundle 4, Task 13).
 *
 * Proves the upgrade: [list_sermons]/[sermon_images]/[latest_series] now delegate to the
 * FAITHFUL Bundle 4 display blocks (term list / image grid / latest-series card) rather than
 * the wrong-type safe sermon list, and each still PREPENDS a reworded per-tag "needs review"
 * notice — visible to a logged-in editor, absent for a visitor (the Contract's fail-visible
 * rule). The pure Renderer runs for real (esc_* are pass-throughs); WP term/query primitives
 * are mocked.
 */
final class LegacyShortcodesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'is_wp_error' )->justReturn( false );

        if ( ! class_exists( 'WP_Block' ) ) {
            eval( 'class WP_Block { public function __construct( $b = array() ) {} }' );
        }
        if ( ! class_exists( 'WP_Term' ) ) {
            eval(
                'class WP_Term { public $term_id = 0; public $term_taxonomy_id = 0;'
                . ' public $name = ""; public $description = ""; }'
            );
        }
        if ( ! class_exists( 'WP_Query' ) ) {
            eval(
                'class WP_Query { public $posts = array(); public function __construct( $args = array() ) {'
                . ' $GLOBALS["__sermonator_ls_args"] = $args;'
                . ' $this->posts = $GLOBALS["__sermonator_ls_posts"] ?? array(); } }'
            );
        }
        $GLOBALS['__sermonator_ls_posts'] = array();
        $GLOBALS['__sermonator_ls_args']  = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['__sermonator_ls_posts'], $GLOBALS['__sermonator_ls_args'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function term( int $ttId, string $name, string $description = '' ): \WP_Term {
        $t                   = new \WP_Term();
        $t->term_taxonomy_id = $ttId;
        $t->name             = $name;
        $t->description      = $description;
        return $t;
    }

    public function test_generic_notice_renders_only_for_editors(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertStringContainsString( 'sermonator-compat-notice', LegacyShortcodes::needsReviewNotice() );
    }

    public function test_generic_notice_is_empty_for_visitors(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertSame( '', LegacyShortcodes::needsReviewNotice() );
    }

    /** Each faithful tag carries a DISTINCT, reworded per-tag review notice for an editor. */
    public function test_per_tag_notices_are_distinct_and_editor_only(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $listSermons = LegacyShortcodes::listSermonsNotice();
        $latest      = LegacyShortcodes::latestSeriesNotice();
        $images      = LegacyShortcodes::sermonImagesNotice();

        foreach ( array( $listSermons, $latest, $images ) as $notice ) {
            $this->assertStringContainsString( 'sermonator-compat-notice', $notice );
        }
        $this->assertStringContainsString( 'list_sermons', $listSermons );
        $this->assertStringContainsString( 'latest_series', $latest );
        $this->assertStringContainsString( 'sermon_images', $images );
        // Reworded — not the generic listing notice, and distinct from each other.
        $this->assertNotSame( LegacyShortcodes::needsReviewNotice(), $listSermons );
        $this->assertNotSame( LegacyShortcodes::needsReviewNotice(), $latest );
        $this->assertNotSame( LegacyShortcodes::needsReviewNotice(), $images );
        $this->assertNotSame( $listSermons, $latest );
        $this->assertNotSame( $latest, $images );
    }

    public function test_per_tag_notices_are_empty_for_visitors(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->assertSame( '', LegacyShortcodes::listSermonsNotice() );
        $this->assertSame( '', LegacyShortcodes::latestSeriesNotice() );
        $this->assertSame( '', LegacyShortcodes::sermonImagesNotice() );
    }

    /**
     * [list_sermons] delegates to the FAITHFUL taxonomy term-list block (not the safe sermon
     * grid) and prepends the per-tag notice for an editor.
     */
    public function test_list_sermons_delegates_to_taxonomy_block_with_notice(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_terms' )->justReturn( array(
            (object) array( 'name' => 'Grace', 'count' => 2 ),
        ) );
        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/series/grace/' );
        Functions\when( 'get_taxonomy' )->justReturn(
            (object) array( 'labels' => (object) array( 'name' => 'Series' ) )
        );

        $html = ( new LegacyShortcodes() )->renderListSermons( array( 'taxonomy' => ID::TAX_SERIES ) );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringContainsString( 'sermonator-termlist', $html );
        $this->assertStringContainsString( 'Grace', $html );
        $this->assertStringNotContainsString( 'sermonator-grid', $html );
        // Notice is prepended before the faithful render.
        $this->assertLessThan(
            strpos( $html, 'sermonator-termlist' ),
            strpos( $html, 'sermonator-compat-notice' ),
            'Per-tag notice must be prepended before the faithful render.'
        );
    }

    public function test_list_sermons_omits_notice_for_visitor_but_still_renders_block(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'get_terms' )->justReturn( array(
            (object) array( 'name' => 'Grace', 'count' => 2 ),
        ) );
        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/series/grace/' );
        Functions\when( 'get_taxonomy' )->justReturn(
            (object) array( 'labels' => (object) array( 'name' => 'Series' ) )
        );

        $html = ( new LegacyShortcodes() )->renderListSermons( array( 'taxonomy' => ID::TAX_SERIES ) );

        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringContainsString( 'sermonator-termlist', $html );
    }

    /**
     * [latest_series] delegates to the faithful latest-series card (most-recently-preached
     * series) and prepends the per-tag notice.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_latest_series_delegates_to_latest_series_block_with_notice(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/series/advent/' );
        Functions\when( 'wp_get_attachment_image' )->justReturn( '' );
        $GLOBALS['__sermonator_ls_posts'] = array( 123 );
        Functions\when( 'get_the_terms' )->justReturn( array(
            $this->term( 9, 'Advent', '' ),
        ) );

        $html = ( new LegacyShortcodes() )->renderLatestSeries( array() );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringContainsString( 'sermonator-latest-series', $html );
        $this->assertStringContainsString( 'Advent', $html );
        $this->assertStringNotContainsString( 'sermonator-grid', $html );
    }

    /**
     * [sermon_images] delegates to the faithful term-image grid and prepends the per-tag
     * notice. With resolvable artwork it renders the grid (never the sermon list).
     */
    public function test_sermon_images_delegates_to_image_grid_with_notice(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array( 9 => 123 ) );
        Functions\when( 'get_terms' )->justReturn( array(
            $this->term( 9, 'Advent', '' ),
        ) );
        Functions\when( 'wp_get_attachment_image' )->justReturn( '<img src="advent.jpg" />' );
        Functions\when( 'get_term_link' )->justReturn( 'https://example.test/series/advent/' );

        $html = ( new LegacyShortcodes() )->renderSermonImages( array( 'taxonomy' => ID::TAX_SERIES ) );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringContainsString( 'sermonator-image-grid', $html );
        $this->assertStringContainsString( 'advent.jpg', $html );
    }
}
