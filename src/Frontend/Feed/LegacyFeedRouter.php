<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Migration\LegacyIdentifiers;

/**
 * Keeps legacy Sermon Manager podcast feed URLs alive after migration.
 *
 * The legacy feed was served at /?feed=rss2&post_type=wpfc_sermon[&id=<legacy_podcast>].
 * Post-migration there is no wpfc_sermon post type, so WordPress would 404 that
 * request. This router intercepts the WP `request` query-vars, and when it sees a
 * feed request targeting the legacy sermon post type it rewrites the query to our
 * registered feed (PodcastFeed::FEED) and translates the legacy ?id into the
 * migrated podcast id via LegacyPodcastId, so WP dispatches our handler.
 */
final class LegacyFeedRouter {
    public function hook(): void {
        add_filter( 'request', array( $this, 'route' ) );
    }

    /**
     * @param array<string,mixed> $query_vars
     * @return array<string,mixed>
     */
    public function route( array $query_vars ): array {
        if ( ! isset( $query_vars['feed'] ) ) {
            return $query_vars;
        }

        if ( ! $this->targetsLegacySermon( $query_vars ) ) {
            return $query_vars;
        }

        $query_vars['feed'] = PodcastFeed::FEED;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $legacyId            = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $_GET['podcast']     = ( new LegacyPodcastId() )->resolve( $legacyId );

        return $query_vars;
    }

    /**
     * True when the request targets the legacy sermon post type, via either the
     * resolved query var or the raw legacy query string.
     *
     * @param array<string,mixed> $query_vars
     */
    private function targetsLegacySermon( array $query_vars ): bool {
        if ( isset( $query_vars['post_type'] ) && $query_vars['post_type'] === LegacyIdentifiers::POST_TYPE_SERMON ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['post_type'] ) && $_GET['post_type'] === LegacyIdentifiers::POST_TYPE_SERMON ) {
            return true;
        }

        return false;
    }
}
