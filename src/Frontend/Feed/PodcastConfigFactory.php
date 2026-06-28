<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Schema\Identifiers as ID;
use Sermonator\Schema\PodcastMetaSchema;

/**
 * Builds a {@see PodcastConfig} from a sermonator_podcast post: its
 * `sermonator_podcast_settings` (migrated from the legacy Pro podcast settings, with known
 * keys read defensively), the post fields, and the featured image — with sensible fallbacks
 * so an incompletely-configured podcast still produces a valid feed.
 *
 * The recognized identity keys come from the shared {@see PodcastMetaSchema::keys()} catalog —
 * the SAME allowlist the write path sanitizes against — so the reader and the writer can never
 * drift on which keys exist. Raw stored meta is intersected against the catalog before use, so a
 * stray/unknown key can never leak into the channel even on an unsanitized legacy row.
 */
final class PodcastConfigFactory {
    public function fromPost( int $podcastId, string $feedUrl ): PodcastConfig {
        $settings = get_post_meta( $podcastId, ID::META_PODCAST_SETTINGS, true );
        $settings = is_array( $settings )
            ? array_intersect_key( $settings, array_flip( PodcastMetaSchema::keys() ) )
            : array();
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

    /**
     * The migrated SM-Free option-podcast config keys, in priority order. Their PRESENCE (any one
     * non-empty) is what {@see self::hasOptionPodcast()} treats as "this site had an SM-Free podcast".
     *
     * @var list<string>
     */
    private const OPTION_KEYS = array(
        ID::OPTION_PODCAST_TITLE,
        ID::OPTION_PODCAST_DESCRIPTION,
        ID::OPTION_PODCAST_WEBSITE_LINK,
        ID::OPTION_PODCAST_LANGUAGE,
        ID::OPTION_PODCAST_COPYRIGHT,
        ID::OPTION_PODCAST_ITUNES_AUTHOR,
        ID::OPTION_PODCAST_ITUNES_SUBTITLE,
        ID::OPTION_PODCAST_ITUNES_SUMMARY,
        ID::OPTION_PODCAST_ITUNES_OWNER_NAME,
        ID::OPTION_PODCAST_ITUNES_OWNER_EMAIL,
        ID::OPTION_PODCAST_ITUNES_COVER_IMAGE,
        ID::OPTION_PODCAST_ITUNES_SUB_CATEGORY,
    );

    /**
     * Build a default podcast config from the migrated SM-Free option-podcast settings (no podcast
     * post). Mirrors {@see self::fromPost()}'s field mapping + fallbacks, reading the flat
     * `sermonator_*` options instead of the post-meta blob. Used by {@see PodcastFeed} to keep a
     * migrated SM-Free church's single implicit podcast (all sermons) serving after the switch.
     */
    public function fromOptions( string $feedUrl ): PodcastConfig {
        $get = static fn( string $optionKey ): string =>
            is_string( $v = get_option( $optionKey, '' ) ) ? $v : '';

        $title   = $get( ID::OPTION_PODCAST_TITLE ) !== '' ? $get( ID::OPTION_PODCAST_TITLE ) : (string) get_bloginfo( 'name' );
        $summary = $get( ID::OPTION_PODCAST_ITUNES_SUMMARY );
        $author  = $get( ID::OPTION_PODCAST_ITUNES_AUTHOR ) !== '' ? $get( ID::OPTION_PODCAST_ITUNES_AUTHOR ) : (string) get_bloginfo( 'name' );
        $link    = $get( ID::OPTION_PODCAST_WEBSITE_LINK ) !== '' ? $get( ID::OPTION_PODCAST_WEBSITE_LINK ) : home_url( '/' );

        // description: prefer iTunes summary, then the description option, then the blog tagline.
        $description = $summary;
        if ( $description === '' ) {
            $description = $get( ID::OPTION_PODCAST_DESCRIPTION );
        }
        if ( $description === '' ) {
            $description = (string) get_bloginfo( 'description' );
        }

        $category = ItunesCategory::normalize( $get( ID::OPTION_PODCAST_ITUNES_SUB_CATEGORY ) );

        return new PodcastConfig(
            title:       $title,
            link:        $link,
            description: $description,
            language:    $get( ID::OPTION_PODCAST_LANGUAGE ) !== '' ? $get( ID::OPTION_PODCAST_LANGUAGE ) : (string) get_bloginfo( 'language' ),
            author:      $author,
            summary:     $summary,
            ownerName:   $get( ID::OPTION_PODCAST_ITUNES_OWNER_NAME ) !== '' ? $get( ID::OPTION_PODCAST_ITUNES_OWNER_NAME ) : $author,
            ownerEmail:  $get( ID::OPTION_PODCAST_ITUNES_OWNER_EMAIL ),
            imageUrl:    $get( ID::OPTION_PODCAST_ITUNES_COVER_IMAGE ),
            category:    $category['category'],
            subcategory: $category['subcategory'],
            explicit:    false, // SM-Free has no explicit field.
            copyright:   $get( ID::OPTION_PODCAST_COPYRIGHT ),
            feedUrl:     $feedUrl
        );
    }

    /**
     * True iff at least one migrated SM-Free podcast option is a non-empty string — the GATE that
     * tells {@see PodcastFeed} a site had an option-podcast (vs a fresh sermonator install), so the
     * default-podcast-from-options feed is served only where there is a legacy podcast to continue.
     */
    public function hasOptionPodcast(): bool {
        foreach ( self::OPTION_KEYS as $key ) {
            $value = get_option( $key, '' );
            if ( is_string( $value ) && $value !== '' ) {
                return true;
            }
        }
        return false;
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
