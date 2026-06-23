<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Schema\Identifiers as ID;

/**
 * Builds a {@see PodcastConfig} from a sermonator_podcast post: its
 * `sermonator_podcast_settings` (migrated from the legacy Pro podcast settings, with known
 * keys read defensively), the post fields, and the featured image — with sensible fallbacks
 * so an incompletely-configured podcast still produces a valid feed.
 */
final class PodcastConfigFactory {
    public function fromPost( int $podcastId, string $feedUrl ): PodcastConfig {
        $settings = get_post_meta( $podcastId, ID::META_PODCAST_SETTINGS, true );
        $settings = is_array( $settings ) ? $settings : array();
        $get      = static fn( string $key, string $default = '' ): string =>
            isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : $default;

        $title   = $get( 'title' ) !== '' ? $get( 'title' ) : (string) get_the_title( $podcastId );
        $summary = $get( 'summary' );
        $author  = $get( 'author' ) !== '' ? $get( 'author' ) : (string) get_bloginfo( 'name' );

        $image = $get( 'image' );
        if ( $image === '' ) {
            $thumb = get_the_post_thumbnail_url( $podcastId, 'full' );
            $image = is_string( $thumb ) ? $thumb : '';
        }

        $category = ItunesCategory::normalize( $get( 'category' ) );

        return new PodcastConfig(
            title:       $title,
            link:        home_url( '/' ),
            description: $summary !== '' ? $summary : (string) get_post_field( 'post_content', $podcastId ),
            language:    $get( 'language' ) !== '' ? $get( 'language' ) : (string) get_bloginfo( 'language' ),
            author:      $author,
            summary:     $summary,
            ownerName:   $get( 'owner_name' ) !== '' ? $get( 'owner_name' ) : $author,
            ownerEmail:  $get( 'owner_email' ),
            imageUrl:    $image,
            category:    $category['category'],
            subcategory: $category['subcategory'],
            explicit:    in_array( strtolower( $get( 'explicit', 'no' ) ), array( 'yes', 'true', '1', 'explicit' ), true ),
            copyright:   $get( 'copyright' ),
            feedUrl:     $feedUrl
        );
    }

    public function emptyConfig( string $feedUrl ): PodcastConfig {
        return new PodcastConfig(
            title:       (string) get_bloginfo( 'name' ),
            link:        home_url( '/' ),
            description: (string) get_bloginfo( 'description' ),
            language:    (string) get_bloginfo( 'language' ),
            author:      (string) get_bloginfo( 'name' ),
            summary:     '',
            ownerName:   '',
            ownerEmail:  '',
            imageUrl:    '',
            category:    ItunesCategory::DEFAULT_CATEGORY,
            subcategory: null,
            explicit:    false,
            copyright:   '',
            feedUrl:     $feedUrl
        );
    }
}
