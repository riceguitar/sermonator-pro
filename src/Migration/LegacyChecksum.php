<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * The single source of truth for a legacy post's content fixity hash.
 *
 * This is the exact digest the Detector historically computed inline
 * (`md5(post_content . encode(ksort'd raw get_post_meta($id)))`). It is extracted
 * here so the Detector and the Verifier (B2) share ONE implementation and cannot
 * drift. The encoding step is hardened: `wp_json_encode` can return `false` for
 * meta containing invalid UTF-8, which would silently drop that meta from the
 * digest; we fall back to PHP `serialize()` in that case so every byte of every
 * meta value still contributes to the hash.
 *
 * Read-only: never mutates legacy data.
 */
final class LegacyChecksum {
    public static function forPost( int $legacyId ): string {
        $post = get_post( $legacyId );
        $meta = get_post_meta( $legacyId );
        ksort( $meta );

        $encoded = wp_json_encode( $meta, JSON_INVALID_UTF8_SUBSTITUTE );
        if ( ! is_string( $encoded ) || '' === $encoded ) {
            // Invalid-UTF-8 (or otherwise unencodable) meta: fall back to PHP
            // serialization so the bytes still fold into the digest.
            $encoded = serialize( $meta );
        }

        return md5( ( $post ? $post->post_content : '' ) . $encoded );
    }
}
