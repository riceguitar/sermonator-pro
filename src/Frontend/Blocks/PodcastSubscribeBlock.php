<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\Feed\PodcastFeed;
use Sermonator\Schema\Identifiers as ID;

/** Subscribe links (RSS / Apple / Spotify) for the default or a chosen podcast. */
final class PodcastSubscribeBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/podcast-subscribe';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $podcastId = ! empty( $attributes['podcastId'] )
            ? (int) $attributes['podcastId']
            : (int) get_option( ID::OPTION_DEFAULT_PODCAST, 0 );

        $isDefault = $podcastId === (int) get_option( ID::OPTION_DEFAULT_PODCAST, 0 );
        $feedUrl   = get_feed_link( PodcastFeed::FEED );
        $feedUrl   = is_string( $feedUrl ) ? $feedUrl : home_url( '/feed/' . PodcastFeed::FEED . '/' );
        if ( ! $isDefault && $podcastId > 0 ) {
            $feedUrl = add_query_arg( 'podcast', $podcastId, $feedUrl );
        }

        $settings = $podcastId > 0 ? get_post_meta( $podcastId, ID::META_PODCAST_SETTINGS, true ) : array();
        $settings = is_array( $settings ) ? $settings : array();

        $links     = array();
        $showRss   = ! isset( $attributes['showRss'] ) || (bool) $attributes['showRss'];
        $showApple = ! isset( $attributes['showApple'] ) || (bool) $attributes['showApple'];
        $showSpot  = ! isset( $attributes['showSpotify'] ) || (bool) $attributes['showSpotify'];

        if ( $showApple && ! empty( $settings['apple_url'] ) ) {
            $links[] = array( 'label' => __( 'Apple Podcasts', 'sermonator' ), 'url' => (string) $settings['apple_url'], 'service' => 'apple' );
        }
        if ( $showSpot && ! empty( $settings['spotify_url'] ) ) {
            $links[] = array( 'label' => __( 'Spotify', 'sermonator' ), 'url' => (string) $settings['spotify_url'], 'service' => 'spotify' );
        }
        if ( $showRss && $feedUrl !== '' ) {
            $links[] = array( 'label' => __( 'RSS', 'sermonator' ), 'url' => $feedUrl, 'service' => 'rss' );
        }

        return ( new Renderer() )->subscribeLinks( $links, __( 'Subscribe', 'sermonator' ) );
    }
}
