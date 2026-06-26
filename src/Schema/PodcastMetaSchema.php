<?php

declare(strict_types=1);

namespace Sermonator\Schema;

use Sermonator\Frontend\Feed\ItunesCategory;

/**
 * Governance for the {@see Identifiers::META_PODCAST_SETTINGS} post meta — the single
 * canonical source of podcast identity that {@see \Sermonator\Frontend\Feed\PodcastConfigFactory}
 * reads and {@see \Sermonator\Frontend\Feed\PodcastFeed} emits straight to Apple / Spotify.
 *
 * Until Bundle 4 this meta was registered NOWHERE: any code (or, post-write-path, a future
 * REST/admin surface) could persist arbitrary unsanitized values that the feed would inject
 * verbatim into the public RSS channel. This schema closes that gap with three guarantees:
 *
 *   1. {@see self::register()} calls `register_post_meta()` on the podcast CPT with an
 *      `auth_callback` of `current_user_can('manage_options')` and a `sanitize_callback`
 *      wired to {@see self::sanitize()}, so even a direct `update_post_meta()` is hardened.
 *   2. {@see self::keys()} is the ONE typed allowlist of the ~13 known podcast-identity keys.
 *      It is shared: the factory (reader) intersects raw stored meta against it, and the
 *      admin Form-2 writer ({@see \Sermonator\Admin\PodcastIdentityController}) sanitizes
 *      through {@see self::sanitize()} — so reader and writer can never drift.
 *   3. {@see self::sanitize()} sanitizes per key by type (email→sanitize_email,
 *      explicit→bool, url/image→esc_url_raw, category→Apple-taxonomy-normalized,
 *      text→sanitize_text_field, multiline→sanitize_textarea_field) and DROPS any key not in
 *      the allowlist — so an unknown / injected key never reaches the feed.
 *
 * This clears the §5.D audio-backfill bar for the podcast-settings write path: sanitize-at-write,
 * reversible (it only ever narrows/cleans known keys; it never invents data), and gated behind
 * `manage_options`.
 */
final class PodcastMetaSchema {
    /** A free-text single-line value (sanitize_text_field). */
    private const T_TEXT = 'text';

    /** A multi-line value such as a summary/description (sanitize_textarea_field). */
    private const T_MULTILINE = 'multiline';

    /** An e-mail address (sanitize_email). */
    private const T_EMAIL = 'email';

    /** A URL (esc_url_raw). */
    private const T_URL = 'url';

    /** A boolean explicit flag. */
    private const T_BOOL = 'bool';

    /** An Apple iTunes category, normalized against the fixed taxonomy. */
    private const T_CATEGORY = 'category';

    /**
     * The typed allowlist of every known podcast-identity key. The key names are EXACTLY the
     * ones {@see \Sermonator\Frontend\Feed\PodcastConfigFactory::fromPost()} reads (`title`,
     * `summary`, `author`, `owner_name`, `owner_email`, `image`, `category`, `explicit`,
     * `copyright`, `language`) plus the channel-level companions the factory derives or a feed
     * may carry (`description`, `subcategory`, `link`). Any key NOT here is dropped at write.
     *
     * @var array<string,self::T_*>
     */
    private const KEYS = array(
        'title'       => self::T_TEXT,
        'summary'     => self::T_MULTILINE,
        'description' => self::T_MULTILINE,
        'author'      => self::T_TEXT,
        'owner_name'  => self::T_TEXT,
        'owner_email' => self::T_EMAIL,
        'image'       => self::T_URL,
        'category'    => self::T_CATEGORY,
        'subcategory' => self::T_TEXT,
        'explicit'    => self::T_BOOL,
        'copyright'   => self::T_TEXT,
        'language'    => self::T_TEXT,
        'link'        => self::T_URL,
    );

    /**
     * The shared key catalog — the SINGLE source of truth for which podcast-identity keys are
     * recognized. Consumed by both the reader (factory) and the writer (admin controller) so a
     * key added here is honored everywhere, and a key absent here is dropped everywhere.
     *
     * @return list<string>
     */
    public static function keys(): array {
        return array_keys( self::KEYS );
    }

    /**
     * Register the podcast-settings post meta with auth + sanitize governance. Intended to be
     * hooked on `init` (mirroring the sermon meta registration). Idempotent: WordPress treats a
     * second registration of the same key as an overwrite.
     */
    public static function register(): void {
        register_post_meta(
            Identifiers::POST_TYPE_PODCAST,
            Identifiers::META_PODCAST_SETTINGS,
            array(
                'single'            => true,
                'type'              => 'object',
                'default'           => array(),
                // Admin Form-2 (admin-post.php) owns the write surface, not the block editor.
                'show_in_rest'      => false,
                'sanitize_callback' => static fn( $value ): array => self::sanitize( $value ),
                'auth_callback'     => static fn(): bool => current_user_can( 'manage_options' ),
            )
        );
    }

    /**
     * Sanitize a podcast-settings array per the typed allowlist, DROPPING any unknown key.
     *
     * A non-array input (the meta was never set, or was corrupted) sanitizes to an empty array
     * rather than throwing — the factory's own fallbacks then produce a valid empty channel.
     *
     * @param mixed $value
     * @return array<string,string|bool>
     */
    public static function sanitize( $value ): array {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $clean = array();
        foreach ( self::KEYS as $key => $type ) {
            if ( ! array_key_exists( $key, $value ) ) {
                continue;
            }
            $clean[ $key ] = self::sanitizeValue( $type, $value[ $key ] );
        }

        return $clean;
    }

    /**
     * @param self::T_* $type
     * @param mixed     $raw
     * @return string|bool
     */
    private static function sanitizeValue( string $type, $raw ) {
        switch ( $type ) {
            case self::T_BOOL:
                return self::toBool( $raw );

            case self::T_EMAIL:
                return sanitize_email( self::toScalarString( $raw ) );

            case self::T_URL:
                return esc_url_raw( self::toScalarString( $raw ) );

            case self::T_MULTILINE:
                return sanitize_textarea_field( self::toScalarString( $raw ) );

            case self::T_CATEGORY:
                $value = trim( self::toScalarString( $raw ) );
                // Preserve "left blank" (the factory defaults it); otherwise pin to a valid
                // Apple category so the feed is never rejected.
                if ( '' === $value ) {
                    return '';
                }
                $normalized = ItunesCategory::normalize( $value );
                return $normalized['category'];

            case self::T_TEXT:
            default:
                return sanitize_text_field( self::toScalarString( $raw ) );
        }
    }

    /**
     * Coerce the legacy/string-flavored explicit value to a real bool. Accepts the historical
     * string encodings (`yes`/`true`/`1`/`explicit`) the factory already understood, plus a real
     * bool, and treats everything else (including `no`/empty) as false.
     *
     * @param mixed $raw
     */
    private static function toBool( $raw ): bool {
        if ( is_bool( $raw ) ) {
            return $raw;
        }
        if ( ! is_scalar( $raw ) ) {
            return false;
        }
        return in_array( strtolower( (string) $raw ), array( 'yes', 'true', '1', 'explicit' ), true );
    }

    /**
     * @param mixed $raw
     */
    private static function toScalarString( $raw ): string {
        return is_scalar( $raw ) ? (string) $raw : '';
    }
}
