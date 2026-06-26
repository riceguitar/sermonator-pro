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
}
