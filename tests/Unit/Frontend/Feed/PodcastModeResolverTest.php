<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Feed;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionMethod;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Frontend\Feed\PodcastModeResolver;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for Bundle 2 / T9: the audio/video `sermons_to_show` MODE fail-visible decision.
 *
 * Two seams are exercised:
 *  - {@see PodcastModeResolver} (the classifier): only ever calls get_post_meta() for the settings
 *    blob, which is stubbed;
 *  - {@see PodcastFeed::signalUnsupportedMode()} (the feed wiring): touches only the resolver and
 *    do_action, invoked directly via reflection (mirroring PodcastFeedTest's feedScope() seam) so it
 *    is immune to the WP_Query stack the render path needs.
 *
 * The §2.10 contract: a non-audio mode (video / *_priority) FIRES the signal AND keeps the per-feed
 * review notice; an audio-only / unset / absent mode does NEITHER (faithful — today's behavior).
 */
final class PodcastModeResolverTest extends TestCase {
    /** @var list<array{hook:string,args:array}> */
    private array $actions = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->actions = array();

        Functions\when( 'do_action' )->alias(
            function ( $hook, ...$args ): void {
                $this->actions[] = array( 'hook' => (string) $hook, 'args' => $args );
            }
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub get_post_meta( $id, META_PODCAST_SETTINGS, true ) to return the given settings blob.
     *
     * @param mixed $settings
     */
    private function stubSettings( $settings ): void {
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key, $single = false ) use ( $settings ) {
                if ( $key === ID::META_PODCAST_SETTINGS ) {
                    return $settings;
                }
                return $single ? '' : array();
            }
        );
    }

    /** Invoke the private feed seam PodcastFeed::signalUnsupportedMode() for the given podcast id. */
    private function fireFeedSignal( int $podcastId ): void {
        $method = new ReflectionMethod( PodcastFeed::class, 'signalUnsupportedMode' );
        $method->setAccessible( true );
        $method->invoke( new PodcastFeed(), $podcastId, new PodcastModeResolver() );
    }

    /** @return list<string> */
    private function firedHooks(): array {
        return array_column( $this->actions, 'hook' );
    }

    // ---- non-audio modes: UNSUPPORTED → signal fires + notice kept ---------------------------

    public function test_video_mode_is_unsupported_and_keeps_the_review_notice(): void {
        $this->stubSettings( array(
            'title'                      => 'Sunday Service',
            ID::PODCAST_SETTING_FEED_MODE => 'video',
        ) );

        $resolver = new PodcastModeResolver();

        $this->assertSame( 'video', $resolver->unsupportedMode( 100 ) );
        $this->assertTrue(
            $resolver->keepsReviewNotice( 100 ),
            'A video-mode podcast must KEEP its per-feed review notice (mode faithfulness is unbuilt).'
        );
    }

    public function test_video_mode_fires_the_unsupported_signal_with_podcast_id_and_mode(): void {
        $this->stubSettings( array( ID::PODCAST_SETTING_FEED_MODE => 'video' ) );

        $this->fireFeedSignal( 100 );

        $this->assertContains( 'sermonator_feed_mode_unsupported', $this->firedHooks() );

        $fired = array_values( array_filter(
            $this->actions,
            static fn( $a ) => $a['hook'] === 'sermonator_feed_mode_unsupported'
        ) );
        $this->assertSame(
            array( 100, 'video' ),
            $fired[0]['args'],
            'The signal must carry the podcast id AND the requested (unsupported) mode.'
        );
    }

    public function test_audio_priority_mode_is_unsupported(): void {
        $this->stubSettings( array( ID::PODCAST_SETTING_FEED_MODE => 'audio_priority' ) );

        $this->assertSame( 'audio_priority', ( new PodcastModeResolver() )->unsupportedMode( 100 ) );
    }

    public function test_video_priority_mode_is_unsupported(): void {
        $this->stubSettings( array( ID::PODCAST_SETTING_FEED_MODE => 'video_priority' ) );

        $this->assertSame( 'video_priority', ( new PodcastModeResolver() )->unsupportedMode( 100 ) );
    }

    public function test_unknown_nonaudio_value_is_treated_as_unsupported(): void {
        // Pro keys the whole video branch off a non-empty value (podcasting_manager.php :143), so
        // ANY non-audio value changes the item set → fail-visible, not silent.
        $this->stubSettings( array( ID::PODCAST_SETTING_FEED_MODE => 'VIDEO' ) ); // case-insensitive

        $this->assertSame( 'video', ( new PodcastModeResolver() )->unsupportedMode( 100 ) );
    }

    // ---- audio-only / unset / absent: FAITHFUL → no signal, no notice ------------------------

    public function test_empty_mode_is_audio_only_and_fires_no_signal(): void {
        $this->stubSettings( array( ID::PODCAST_SETTING_FEED_MODE => '' ) );

        $resolver = new PodcastModeResolver();

        $this->assertNull( $resolver->unsupportedMode( 100 ) );
        $this->assertFalse( $resolver->keepsReviewNotice( 100 ) );

        $this->fireFeedSignal( 100 );
        $this->assertSame( array(), $this->firedHooks(), 'Audio-only (empty) mode is faithful — no signal.' );
    }

    public function test_zero_string_mode_is_audio_only_per_legacy_empty_semantics(): void {
        // Pro's empty() treats '0' as audio-only.
        $this->stubSettings( array( ID::PODCAST_SETTING_FEED_MODE => '0' ) );

        $this->assertNull( ( new PodcastModeResolver() )->unsupportedMode( 100 ) );
    }

    public function test_explicit_audio_label_is_audio_only(): void {
        $this->stubSettings( array( ID::PODCAST_SETTING_FEED_MODE => 'audio' ) );

        $this->assertNull( ( new PodcastModeResolver() )->unsupportedMode( 100 ) );
    }

    public function test_absent_mode_key_fires_no_signal(): void {
        // The common case: a podcast configured before the option existed / never touched. Gate on
        // the key's presence — an absent key is the audio-only default, never a signal.
        $this->stubSettings( array( 'title' => 'Sunday Service', 'author' => 'Pastor' ) );

        $resolver = new PodcastModeResolver();

        $this->assertNull( $resolver->unsupportedMode( 100 ) );
        $this->assertFalse( $resolver->keepsReviewNotice( 100 ) );

        $this->fireFeedSignal( 100 );
        $this->assertSame( array(), $this->firedHooks() );
    }

    public function test_absent_or_corrupt_settings_blob_fires_no_signal(): void {
        $this->stubSettings( '' ); // never set

        $this->assertNull( ( new PodcastModeResolver() )->unsupportedMode( 100 ) );

        $this->fireFeedSignal( 100 );
        $this->assertSame( array(), $this->firedHooks() );
    }

    public function test_nonscalar_mode_value_is_treated_as_the_safe_default(): void {
        $this->stubSettings( array( ID::PODCAST_SETTING_FEED_MODE => array( 'video' ) ) );

        $this->assertNull( ( new PodcastModeResolver() )->unsupportedMode( 100 ) );

        $this->fireFeedSignal( 100 );
        $this->assertSame( array(), $this->firedHooks() );
    }

    /**
     * Contract pin: the migrated mode sub-key is the SOLE token linking the legacy Pro setting to
     * this resolver. If it drifts from the verbatim-migrated legacy name, every podcast silently
     * reports audio-only (no signal) even when Pro requested video — the silent fail this exists to
     * prevent. The value MUST equal the legacy `sermons_to_show` key.
     */
    public function test_feed_mode_key_token_is_stable(): void {
        $this->assertSame( 'sermons_to_show', ID::PODCAST_SETTING_FEED_MODE );
    }
}
