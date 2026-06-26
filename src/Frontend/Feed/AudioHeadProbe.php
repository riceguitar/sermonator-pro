<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

/**
 * Reusable, hardened HEAD probe for an audio URL.
 *
 * Extracted from AudioSizeBackfill so both the bulk backfill and the authoring-layer
 * audio-metadata endpoint share the exact same safety constraints (scheme whitelist,
 * reject_unsafe_urls, redirect limit, size cap). The probe never writes meta.
 */
final class AudioHeadProbe {
    /** Reject an absurd Content-Length (> 4 GB) — a sermon audio file is never this large. */
    public const MAX_SIZE_BYTES = 4_294_967_296;

    /**
     * Probe an http(s) audio URL and return its size + MIME type from the response headers.
     *
     * @return array{size:int|null,mime:string|null}
     */
    public static function probe( string $url ): array {
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            return array( 'size' => null, 'mime' => null );
        }

        $response = wp_remote_head(
            $url,
            array(
                'timeout'            => 10,
                'redirection'        => 3,
                'reject_unsafe_urls' => true,
            )
        );
        if ( is_wp_error( $response ) ) {
            // Fix 3: log the WP_Error so a network failure is not silently swallowed.
            error_log( sprintf(
                'Sermonator AudioHeadProbe: wp_remote_head failed for %s: %s',
                $url,
                $response->get_error_message()
            ) );
            return array( 'size' => null, 'mime' => null );
        }

        $length = wp_remote_retrieve_header( $response, 'content-length' );
        if ( is_array( $length ) ) {
            $length = end( $length );
        }
        $length = (int) $length;
        $size   = ( $length > 0 && $length <= self::MAX_SIZE_BYTES ) ? $length : null;

        $mime = wp_remote_retrieve_header( $response, 'content-type' );
        if ( is_array( $mime ) ) {
            $mime = end( $mime );
        }
        $mime = is_string( $mime ) && $mime !== '' ? $mime : null;

        return array( 'size' => $size, 'mime' => $mime );
    }
}
