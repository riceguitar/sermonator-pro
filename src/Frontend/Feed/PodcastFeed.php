<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Frontend\SermonQuery;
use Sermonator\Schema\Identifiers as ID;

/**
 * Registers and renders the Apple-Podcasts-compatible RSS feed at
 * /feed/sermonator-podcast/ (or ?feed=sermonator-podcast), for the default podcast or a
 * specific one via ?podcast=ID. Read-only: reads persisted enclosure sizes and never makes
 * a network call at render time. Episodes whose audio size is unknown are omitted (Apple
 * requires a correct enclosure length); the AudioBackfillCommand reports/fixes those.
 */
final class PodcastFeed {
    public const FEED = 'sermonator-podcast';

    /** Max episodes per feed (Apple/most clients read ~300; keep the query bounded). */
    private const MAX_ITEMS = 300;

    public function hook(): void {
        add_action( 'init', array( $this, 'register' ) );
    }

    public function register(): void {
        add_feed( self::FEED, array( $this, 'render' ) );
        // Belt-and-suspenders: if anything routes through feed_content_type() for our feed,
        // return RSS rather than the WP default of application/octet-stream.
        add_filter( 'feed_content_type', array( $this, 'contentType' ), 10, 2 );
    }

    public function contentType( string $type, string $feed ): string {
        return $feed === self::FEED ? 'application/rss+xml' : $type;
    }

    public function render(): void {
        // Send the content type as the very first action so it is emitted before any body
        // output (otherwise nginx falls back to application/octet-stream).
        $charset = (string) get_option( 'blog_charset' ) ?: 'UTF-8';
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/rss+xml; charset=' . $charset, true );
        }

        $feedUrl   = $this->feedUrl();
        $podcastId = $this->resolvePodcast();
        $factory   = new PodcastConfigFactory();

        if ( $podcastId <= 0 ) {
            echo ( new FeedBuilder() )->build( $factory->emptyConfig( $feedUrl ), array() ); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        $config = $factory->fromPost( $podcastId, $feedUrl );
        $items  = $this->items();
        echo ( new FeedBuilder() )->build( $config, $items ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /** @return list<FeedItem> */
    private function items(): array {
        $result   = ( new SermonQuery() )->run( array( 'perPage' => self::MAX_ITEMS ) );
        $resolver = new EnclosureResolver();

        $items = array();
        foreach ( $result->sermons as $view ) {
            $enc = $resolver->resolve( $view->id );
            if ( $enc === null ) {
                continue; // No audio or unknown size — omitted (see AudioBackfillCommand).
            }
            $items[] = new FeedItem(
                title:        $view->title,
                link:         $view->permalink,
                guid:         'sermonator-' . $view->id,
                description:  $this->description( $view->id ),
                pubTimestamp: $view->preachedTimestamp ?? (int) get_post_time( 'U', true, $view->id ),
                audioUrl:     $enc['url'],
                audioType:    $enc['type'],
                audioSize:    $enc['size'],
                duration:     $enc['duration'],
                explicit:     false
            );
        }
        return $items;
    }

    private function description( int $sermonId ): string {
        $excerpt = (string) get_the_excerpt( $sermonId );
        if ( $excerpt !== '' ) {
            return $excerpt;
        }
        return wp_trim_words( (string) wp_strip_all_tags( (string) get_post_field( 'post_content', $sermonId ) ), 60 );
    }

    private function resolvePodcast(): int {
        // Validate an explicit ?podcast=ID is a published podcast before trusting it.
        if ( isset( $_GET['podcast'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $candidate = (int) $_GET['podcast']; // phpcs:ignore WordPress.Security.NonceVerification
            if ( $candidate > 0
                && get_post_type( $candidate ) === ID::POST_TYPE_PODCAST
                && get_post_status( $candidate ) === 'publish' ) {
                return $candidate;
            }
        }
        return (int) get_option( ID::OPTION_DEFAULT_PODCAST, 0 );
    }

    private function feedUrl(): string {
        $url = get_feed_link( self::FEED );
        return is_string( $url ) ? $url : home_url( '/feed/' . self::FEED . '/' );
    }
}
