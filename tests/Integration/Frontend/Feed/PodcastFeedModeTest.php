<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Frontend\Feed\PodcastModeResolver;
use Sermonator\Frontend\SermonQuery;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for Bundle 2 / T9: the audio/video `sermons_to_show` MODE fail-visible signal,
 * wired through the real PodcastFeed::render() against the WordPress test harness.
 *
 * Requires wp-env / WP_UnitTestCase. Do NOT run under the Brain Monkey unit suite. (At authoring time
 * wp-env was unavailable — these are written, not run.)
 *
 * The §2.10 contract pinned here:
 *  - a podcast requesting a non-audio mode (video / *_priority) fires `sermonator_feed_mode_unsupported`
 *    (carrying the podcast id + the requested mode) AND keeps its review notice — WHILE the feed STILL
 *    serves the audio-only item set (today's behavior is unchanged; only the signal is added);
 *  - an audio-only / unset / absent mode fires NOTHING (faithful, earned silence).
 */
final class PodcastFeedModeTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        unset( $_GET['podcast'] );
        set_query_var( SermonQuery::PAGE_QUERY_VAR, '' );
        remove_all_actions( 'sermonator_feed_mode_unsupported' );
        parent::tearDown();
    }

    /** Create a published, default podcast with the given settings blob. */
    private function podcast( array $extraSettings = array() ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_title'  => 'Sunday Sermons',
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, array_merge( array(
            'author'      => 'Example Church',
            'summary'     => 'Weekly teaching.',
            'owner_email' => 'podcast@example.com',
            'category'    => 'Christianity',
            'explicit'    => 'no',
        ), $extraSettings ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $id );
        return $id;
    }

    /** Create a published sermon with a resolvable enclosure (audio + persisted size + date). */
    private function sermon( string $title ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_DATE, '1700000000' );
        update_post_meta( $id, ID::META_AUDIO, 'http://example.com/' . $id . '.mp3' );
        update_post_meta( $id, ID::META_AUDIO_SIZE, '1000' );
        return $id;
    }

    private function render(): string {
        ob_start();
        ( new PodcastFeed() )->render();
        return (string) ob_get_clean();
    }

    /**
     * A video-mode podcast: the signal fires with (id, 'video'), the resolver keeps its review notice,
     * and the feed STILL serves the audio-only set (the deferral does not change today's item set).
     */
    public function test_video_mode_fires_signal_keeps_notice_and_still_serves_audio(): void {
        $this->sermon( 'AudioEpisode' );
        $podcastId = $this->podcast( array( ID::PODCAST_SETTING_FEED_MODE => 'video' ) );

        $fired = array();
        add_action(
            'sermonator_feed_mode_unsupported',
            static function ( $id, $mode ) use ( &$fired ): void {
                $fired[] = array( $id, $mode );
            },
            10,
            2
        );

        $xml = $this->render();

        $this->assertSame(
            array( array( $podcastId, 'video' ) ),
            $fired,
            'A non-audio mode must fire sermonator_feed_mode_unsupported once, carrying the id + mode.'
        );
        $this->assertTrue(
            ( new PodcastModeResolver() )->keepsReviewNotice( $podcastId ),
            'A video-mode podcast keeps its per-feed review notice (mode faithfulness is unbuilt).'
        );
        // Today's behavior is UNCHANGED: audio-only is still served (the deferral adds a signal, not
        // a different item set).
        $this->assertSame( 1, substr_count( $xml, '<item>' ) );
        $this->assertStringContainsString( 'AudioEpisode', $xml );
    }

    /**
     * Each non-audio mode is fail-visible (video / audio_priority / video_priority).
     *
     * @dataProvider nonAudioModes
     */
    public function test_each_nonaudio_mode_fires_the_signal( string $mode ): void {
        $this->sermon( 'Episode' );
        $podcastId = $this->podcast( array( ID::PODCAST_SETTING_FEED_MODE => $mode ) );

        $fired = array();
        add_action(
            'sermonator_feed_mode_unsupported',
            static function ( $id, $m ) use ( &$fired ): void {
                $fired[] = array( $id, $m );
            },
            10,
            2
        );

        $this->render();

        $this->assertSame( array( array( $podcastId, $mode ) ), $fired );
    }

    /** @return array<string,array{0:string}> */
    public static function nonAudioModes(): array {
        return array(
            'video'          => array( 'video' ),
            'audio_priority' => array( 'audio_priority' ),
            'video_priority' => array( 'video_priority' ),
        );
    }

    /** An audio-only / unset / absent mode is faithful — the signal NEVER fires. */
    public function test_audio_only_and_unset_modes_fire_no_signal(): void {
        $this->sermon( 'Episode' );

        $fired = false;
        add_action(
            'sermonator_feed_mode_unsupported',
            static function () use ( &$fired ): void {
                $fired = true;
            }
        );

        // (i) explicit empty value
        $this->podcast( array( ID::PODCAST_SETTING_FEED_MODE => '' ) );
        $this->render();
        $this->assertFalse( $fired, 'An empty (audio-only) mode must fire no signal.' );

        // (ii) key entirely absent (the common pre-option default)
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        $this->podcast();
        $this->render();
        $this->assertFalse( $fired, 'An absent mode key must fire no signal.' );
    }
}
