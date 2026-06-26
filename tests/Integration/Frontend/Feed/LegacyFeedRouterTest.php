<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\LegacyFeedRouter;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the legacy podcast feed-URL router.
 *
 * Requires wp-env / the WordPress test harness (WP_UnitTestCase). Do NOT run
 * under the Brain Monkey unit suite.
 */
final class LegacyFeedRouterTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        delete_option( ID::OPTION_LEGACY_PODCAST_MAP );
        unset( $_GET['podcast'], $_GET['post_type'], $_GET['id'], $_GET['feed'] );
        parent::tearDown();
    }

    private function podcast( string $title ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, array(
            'author'      => 'Example Church',
            'summary'     => 'Weekly teaching.',
            'owner_email' => 'podcast@example.com',
            'category'    => 'Christianity',
            'explicit'    => 'no',
        ) );
        return $id;
    }

    public function test_does_not_touch_non_feed_requests(): void {
        $vars = ( new LegacyFeedRouter() )->route( array( 'post_type' => LegacyIdentifiers::POST_TYPE_SERMON ) );
        $this->assertArrayNotHasKey( 'feed', $vars );
        $this->assertArrayNotHasKey( 'podcast', $_GET );
    }

    public function test_passes_through_feed_for_other_post_types(): void {
        $vars = ( new LegacyFeedRouter() )->route( array( 'feed' => 'rss2', 'post_type' => 'post' ) );
        $this->assertSame( 'rss2', $vars['feed'] );
        $this->assertArrayNotHasKey( 'podcast', $_GET );
    }

    public function test_rewrites_legacy_sermon_feed_to_sermonator_feed_via_query_var(): void {
        $new = $this->podcast( 'Sunday Sermons' );
        update_option( ID::OPTION_DEFAULT_PODCAST, $new );

        $vars = ( new LegacyFeedRouter() )->route( array(
            'feed'      => 'rss2',
            'post_type' => LegacyIdentifiers::POST_TYPE_SERMON,
        ) );

        $this->assertSame( PodcastFeed::FEED, $vars['feed'] );
        $this->assertSame( $new, (int) $_GET['podcast'] );
        // The stale legacy scoping vars must be cleared so the main query is not
        // scoped to the unregistered wpfc_sermon post type (which would 404).
        $this->assertArrayNotHasKey( 'post_type', $vars );
        $this->assertArrayNotHasKey( 'id', $vars );
    }

    public function test_clears_legacy_id_query_var_so_main_query_does_not_404(): void {
        $new = $this->podcast( 'Sunday Sermons' );
        update_option( ID::OPTION_DEFAULT_PODCAST, $new );

        $vars = ( new LegacyFeedRouter() )->route( array(
            'feed'      => 'rss2',
            'post_type' => LegacyIdentifiers::POST_TYPE_SERMON,
            'id'        => 5,
        ) );

        $this->assertSame( PodcastFeed::FEED, $vars['feed'] );
        $this->assertArrayNotHasKey( 'post_type', $vars );
        $this->assertArrayNotHasKey( 'id', $vars );
    }

    public function test_rewrites_legacy_sermon_feed_detected_via_raw_query_string(): void {
        $new = $this->podcast( 'Sunday Sermons' );
        update_option( ID::OPTION_DEFAULT_PODCAST, $new );

        $_GET['post_type'] = LegacyIdentifiers::POST_TYPE_SERMON;

        $vars = ( new LegacyFeedRouter() )->route( array( 'feed' => 'rss2' ) );

        $this->assertSame( PodcastFeed::FEED, $vars['feed'] );
        $this->assertSame( $new, (int) $_GET['podcast'] );
    }

    public function test_durable_map_resolves_legacy_id_to_specific_podcast(): void {
        $defaultCast = $this->podcast( 'Default Cast' );
        $mappedCast  = $this->podcast( 'Mapped Cast' );
        update_option( ID::OPTION_DEFAULT_PODCAST, $defaultCast );
        update_option( ID::OPTION_LEGACY_PODCAST_MAP, array( 42 => $mappedCast ) );

        $_GET['id'] = '42';

        ( new LegacyFeedRouter() )->route( array(
            'feed'      => 'rss2',
            'post_type' => LegacyIdentifiers::POST_TYPE_SERMON,
        ) );

        $this->assertSame( $mappedCast, (int) $_GET['podcast'] );
    }

    public function test_crosswalk_backref_resolves_legacy_id_pre_finalize(): void {
        $mappedCast = $this->podcast( 'Mapped Cast' );
        update_option( ID::OPTION_DEFAULT_PODCAST, $this->podcast( 'Default Cast' ) );

        // Simulate the pre-Finalize state: the migrated podcast carries the
        // legacy back-ref but no durable map exists yet.
        Crosswalk::markLegacy( $mappedCast, 77 );

        $_GET['id'] = '77';

        ( new LegacyFeedRouter() )->route( array(
            'feed'      => 'rss2',
            'post_type' => LegacyIdentifiers::POST_TYPE_SERMON,
        ) );

        $this->assertSame( $mappedCast, (int) $_GET['podcast'] );
    }

    public function test_end_to_end_render_produces_rss_with_podcast_title(): void {
        $new = $this->podcast( 'Sunday Sermons' );
        update_option( ID::OPTION_DEFAULT_PODCAST, $new );

        $vars = ( new LegacyFeedRouter() )->route( array(
            'feed'      => 'rss2',
            'post_type' => LegacyIdentifiers::POST_TYPE_SERMON,
        ) );
        $this->assertSame( PodcastFeed::FEED, $vars['feed'] );

        ob_start();
        ( new PodcastFeed() )->render();
        $xml = (string) ob_get_clean();

        $this->assertNotFalse( simplexml_load_string( $xml ), 'Feed must be well-formed XML.' );
        $this->assertStringContainsString( '<title>Sunday Sermons</title>', $xml );
    }

    /**
     * The load-bearing invariant: the ACTUAL legacy GET request, driven through the
     * REAL WP routing pipeline (parse_request → the `request` filter → WP_Query →
     * handle_404 → do_feed), must NOT 404 and must dispatch OUR feed handler.
     *
     * This exercises the most error-prone seam — that rewriting query_vars['feed']
     * (and clearing the stale wpfc_sermon scoping vars) causes do_feed() to fire our
     * registered handler and yields a 200 RSS response rather than a 404 on the
     * unregistered legacy post type.
     */
    public function test_legacy_get_request_dispatches_feed_via_real_pipeline_without_404(): void {
        // The legacy post type is intentionally NOT registered post-migration — that is
        // exactly what would 404 the URL if the router did not clear the scoping vars.
        if ( ! post_type_exists( ID::POST_TYPE_PODCAST ) ) {
            register_post_type( ID::POST_TYPE_PODCAST, array( 'public' => true ) );
        }

        // Register the REAL feed handler (add_feed + content type + pre_handle_404) and
        // the legacy router (the `request` filter) onto WP's pipeline.
        // The booted plugin already registered this feed; remove any existing do_feed
        // callback so re-registering yields EXACTLY ONE — a second new-instance add_feed
        // would hook do_feed_<feed> twice and render the RSS body twice (two <?xml).
        remove_all_actions( 'do_feed_' . PodcastFeed::FEED );
        ( new PodcastFeed() )->register();
        ( new LegacyFeedRouter() )->hook();

        $new = $this->podcast( 'Sunday Sermons' );
        update_option( ID::OPTION_DEFAULT_PODCAST, $new );

        // Drive the actual legacy URL through the full pipeline. go_to() runs
        // WP::main(): parse_request (our `request` filter rewrites feed + clears
        // post_type/id), the main WP_Query, and handle_404 (our pre_handle_404 fires).
        $this->go_to( home_url( '/?feed=rss2&post_type=wpfc_sermon&id=5' ) );

        // (a) The legacy URL must NOT 404 — the single most load-bearing invariant.
        $this->assertFalse( $GLOBALS['wp_query']->is_404(), 'Legacy feed URL must not 404.' );
        $this->assertTrue( is_feed(), 'Request must be dispatched as a feed.' );
        $this->assertSame( PodcastFeed::FEED, get_query_var( 'feed' ), 'Feed must be rewritten to ours.' );

        // (b) The content type negotiated through WP must be RSS, not octet-stream.
        $this->assertSame(
            'application/rss+xml',
            apply_filters( 'feed_content_type', 'application/octet-stream', get_query_var( 'feed' ) )
        );

        // (c) do_feed() must dispatch OUR registered handler and emit the migrated title.
        ob_start();
        do_feed();
        $xml = (string) ob_get_clean();

        // The WP test env can emit notices / error_log lines into the buffer before the
        // body; the production response (verified via curl) is a clean single RSS document.
        // Parse from the XML declaration so we still assert real well-formedness — a second
        // <?xml (double render) would remain and still fail — without tripping on test noise.
        $start = strpos( $xml, '<?xml' );
        $this->assertNotFalse( $start, 'Feed output must contain an XML declaration.' );
        $xml = substr( $xml, $start );

        $this->assertNotFalse( simplexml_load_string( $xml ), 'Feed must be well-formed XML.' );
        $this->assertStringContainsString( '<title>Sunday Sermons</title>', $xml );
    }
}
