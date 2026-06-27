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
                'class WP_Query { public $posts = array(); public $found_posts = 0; public $max_num_pages = 1;'
                . ' public function __construct( $args = array() ) {'
                . ' $GLOBALS["__sermonator_ls_args"] = $args;'
                . ' $this->posts = $GLOBALS["__sermonator_ls_posts"] ?? array();'
                . ' $this->found_posts = $GLOBALS["__sermonator_ls_found"] ?? count( $this->posts );'
                . ' $this->max_num_pages = $GLOBALS["__sermonator_ls_pages"] ?? 1; } }'
            );
        }
        $GLOBALS['__sermonator_ls_posts'] = array();
        $GLOBALS['__sermonator_ls_args']  = array();
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['__sermonator_ls_posts'],
            $GLOBALS['__sermonator_ls_args'],
            $GLOBALS['__sermonator_ls_found'],
            $GLOBALS['__sermonator_ls_pages']
        );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub the WP surface the attribute-faithful [sermons] render() touches OUTSIDE the mapper:
     * the option-driven mapper defaults, the registered `sermon_page` read, the pager base URL,
     * and the asset-enqueue guard. The heavy WP_Query/TemplateData stack is mocked via the
     * faked WP_Query (empty posts → no TemplateData) so these unit tests exercise the T6 WIRING
     * (precise notice + truncation action + base URL), with full rendering pinned by integration.
     */
    private function stubRenderEnv( bool $isEditor ): void {
        $options = array(
            'posts_per_page'           => 10,
            ID::OPTION_ARCHIVE_ORDER   => 'desc',
            ID::OPTION_ARCHIVE_ORDERBY => 'date_preached',
        );
        Functions\when( 'get_option' )->alias(
            static function ( $key, $default = false ) use ( $options ) {
                return $options[ $key ] ?? ( $default !== false ? $default : '' );
            }
        );
        Functions\when( 'get_query_var' )->justReturn( 1 );
        Functions\when( 'get_queried_object_id' )->justReturn( 0 );
        Functions\when( 'home_url' )->justReturn( 'http://example.test/' );
        Functions\when( 'wp_style_is' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( $isEditor );
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

    // ---------------------------------------------------------------- [sermons] render() (T6)

    /**
     * A faithful-only [sermons] (every attribute reproducible) renders the mapped query through
     * the engine and shows NO review notice — the earned end-state. The output is real HTML, never
     * the raw shortcode text.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_faithful_only_sermons_renders_query_with_no_notice(): void {
        $this->stubRenderEnv( isEditor: true );

        $html = ( new LegacyShortcodes() )->render( array() );

        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html,
            'a faithful-only [sermons] must drop the review notice (empty unfaithful set)' );
        $this->assertStringContainsString( 'sermonator-grid', $html );
        $this->assertStringNotContainsString( '[sermons]', $html );
    }

    /**
     * An [sermons] carrying an UNKNOWN attribute renders the query AND a precise notice naming
     * ONLY that attribute — never the faithful ones (order is faithful, so it is not named).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_unknown_attr_renders_query_plus_notice_naming_only_it(): void {
        $this->stubRenderEnv( isEditor: true );

        $html = ( new LegacyShortcodes() )->render( array( 'frobnicate' => 'yes', 'order' => 'asc' ) );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html );
        $this->assertStringContainsString( 'frobnicate', $html, 'the unknown attr must be named' );
        $this->assertStringNotContainsString( 'order', $html, 'a FAITHFUL attr must NOT be named' );
        $this->assertStringContainsString( 'sermonator-grid', $html );
    }

    /**
     * image_size is the signed §63 no-op (presentation-only; the sermon SET is unchanged), so it
     * earns NO notice — proving the ledger cell shipped faithful, not a false alarm.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_image_size_is_a_noop_with_no_notice(): void {
        $this->stubRenderEnv( isEditor: true );

        $html = ( new LegacyShortcodes() )->render( array( 'image_size' => 'large' ) );

        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html,
            'image_size is §63 presentation-only — the sermon set is unchanged, so no notice is owed' );
        $this->assertStringContainsString( 'sermonator-grid', $html );
    }

    /**
     * A logged-out visitor sees the rendered content but NOT the editor-facing notice, while the
     * truncation do_action ALWAYS fires when the list spans more than one page — so the
     * silent-tail-drop risk is observable independent of login state.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_visitor_sees_content_no_notice_but_truncation_action_fires(): void {
        $this->stubRenderEnv( isEditor: false );
        // 50 found, default per_page 10 → total > perPage → the truncation signal must fire.
        $GLOBALS['__sermonator_ls_found'] = 50;

        $fired = array();
        Functions\when( 'do_action' )->alias(
            static function ( $hook, ...$args ) use ( &$fired ) {
                if ( $hook === LegacyShortcodes::TRUNCATED_ACTION ) {
                    $fired[] = $args;
                }
            }
        );

        // An unknown attr is present, but the visitor must never see the editor notice.
        $html = ( new LegacyShortcodes() )->render( array( 'frobnicate' => 'yes' ) );

        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html,
            'the precise notice is editor-facing — a visitor never sees it' );
        $this->assertStringContainsString( 'sermonator-grid', $html, 'the visitor still sees rendered content' );
        $this->assertSame( array( array( 50, 10 ) ), $fired,
            'sermonator_list_truncated must fire once with (total, perPage), regardless of login' );
    }

    /**
     * The converse pin: a single-page list (total <= perPage) does NOT fire the truncation
     * signal — nothing is dropped, so no false alarm.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_truncation_action_does_not_fire_within_a_single_page(): void {
        $this->stubRenderEnv( isEditor: true );
        $GLOBALS['__sermonator_ls_found'] = 3; // < default per_page 10

        $fired = false;
        Functions\when( 'do_action' )->alias(
            static function ( $hook ) use ( &$fired ) {
                if ( $hook === LegacyShortcodes::TRUNCATED_ACTION ) {
                    $fired = true;
                }
            }
        );

        ( new LegacyShortcodes() )->render( array() );

        $this->assertFalse( $fired, 'no truncation when the whole list fits one page' );
    }
}
