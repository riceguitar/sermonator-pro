<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * The ONLY front-end class that reads post meta and term relationships. Hydrates an
 * immutable {@see SermonView} from a post ID. Read-only: it never writes. Missing meta
 * becomes '' / null / 0 / [] — never the literal string "0" leaking into the view.
 */
final class TemplateData {
    public function sermon( int $postId ): SermonView {
        $str = static fn( string $key ): string => (string) get_post_meta( $postId, $key, true );

        $rawDate = $str( ID::META_DATE );

        return new SermonView(
            id:                $postId,
            title:             (string) get_the_title( $postId ),
            permalink:         (string) get_permalink( $postId ),
            preachedTimestamp: $this->parseTimestamp( $rawDate ),
            biblePassage:      $str( ID::META_BIBLE_PASSAGE ),
            audioUrl:          $str( ID::META_AUDIO ),
            audioDuration:     $str( ID::META_AUDIO_DURATION ),
            audioSize:         (int) $str( ID::META_AUDIO_SIZE ),
            videoEmbed:        $str( ID::META_VIDEO_EMBED ),
            videoUrl:          $str( ID::META_VIDEO_URL ),
            views:             (int) $str( ID::META_VIEWS ),
            imageId:           (int) $str( '_thumbnail_id' ),
            bulletinUrl:       $str( ID::META_BULLETIN ),
            notes:             $str( ID::META_NOTES ),
            preachers:         $this->terms( $postId, ID::TAX_PREACHER ),
            series:            $this->terms( $postId, ID::TAX_SERIES ),
            topics:            $this->terms( $postId, ID::TAX_TOPIC ),
            books:             $this->terms( $postId, ID::TAX_BOOK ),
            serviceTypes:      $this->terms( $postId, ID::TAX_SERVICE_TYPE ),
        );
    }

    /**
     * Parse a stored sermon_date (a SIGNED Unix timestamp — sermons preached before
     * 1970-01-01 are negative). Mirrors the migration layer's ctype_digit( ltrim( …, '-' ) )
     * check so a pre-1970 date is preserved here exactly as the migration writes it, instead
     * of being silently dropped to null (which would omit the date row entirely).
     */
    private function parseTimestamp( string $raw ): ?int {
        if ( $raw === '' ) {
            return null;
        }
        $digits = ltrim( $raw, '-' );
        return ( $digits !== '' && ctype_digit( $digits ) ) ? (int) $raw : null;
    }

    /** @return list<array{name:string,url:string}> */
    private function terms( int $postId, string $taxonomy ): array {
        $terms = get_the_terms( $postId, $taxonomy );
        if ( ! is_array( $terms ) ) {
            return array();
        }

        $out = array();
        foreach ( $terms as $term ) {
            $link  = get_term_link( $term );
            $out[] = array(
                'name' => (string) $term->name,
                'url'  => is_wp_error( $link ) ? '' : (string) $link,
            );
        }
        return $out;
    }
}
