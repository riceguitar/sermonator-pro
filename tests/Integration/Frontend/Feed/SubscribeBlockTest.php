<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Feed;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

final class SubscribeBlockTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_DEFAULT_PODCAST );
        parent::tearDown();
    }

    public function test_renders_rss_link_for_default_podcast(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_PODCAST, 'post_status' => 'publish' ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $id );

        $html = do_blocks( '<!-- wp:sermonator/podcast-subscribe /-->' );

        $this->assertStringContainsString( 'sermonator-subscribe', $html );
        $this->assertStringContainsString( 'sermonator-subscribe__link--rss', $html );
        $this->assertStringContainsString( 'sermonator-podcast', $html ); // the feed slug in the URL
    }

    public function test_renders_apple_and_spotify_when_configured(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_PODCAST, 'post_status' => 'publish' ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, array(
            'apple_url'   => 'https://podcasts.apple.com/x',
            'spotify_url' => 'https://open.spotify.com/show/x',
        ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $id );

        $html = do_blocks( '<!-- wp:sermonator/podcast-subscribe /-->' );

        $this->assertStringContainsString( 'sermonator-subscribe__link--apple', $html );
        $this->assertStringContainsString( 'sermonator-subscribe__link--spotify', $html );
    }

    public function test_apple_hidden_when_toggled_off(): void {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_PODCAST, 'post_status' => 'publish' ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, array( 'apple_url' => 'https://podcasts.apple.com/x' ) );
        update_option( ID::OPTION_DEFAULT_PODCAST, $id );

        $html = do_blocks( '<!-- wp:sermonator/podcast-subscribe {"showApple":false} /-->' );

        $this->assertStringNotContainsString( 'sermonator-subscribe__link--apple', $html );
    }
}
