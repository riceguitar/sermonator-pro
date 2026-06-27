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
 *   2. {@see self::keys()} is the ONE typed allowlist of the known podcast-identity keys
 *      (channel identity PLUS the subscribe-link URLs the
 *      {@see \Sermonator\Frontend\Blocks\PodcastSubscribeBlock} reads). It is shared: the
 *      factory (reader) intersects raw stored meta against it, and the admin Form-2 writer
 *      ({@see \Sermonator\Admin\PodcastIdentityController}) sanitizes through
 *      {@see self::sanitize()} — so reader and writer can never drift.
 *   3. {@see self::sanitize()} sanitizes recognized keys per type (email→sanitize_email,
 *      explicit→bool, url/image→esc_url_raw, category→Apple-taxonomy-normalized to the MOST
 *      SPECIFIC valid term, text→sanitize_text_field, multiline→sanitize_textarea_field).
 *
 * CRITICAL — this blob is NOT identity-only. Migration stores per-taxonomy term-filter SCOPE
 * keys inside it ({@see \Sermonator\Migration\PodcastWriter::remapSettingsTerms()} writes
 * `sermonator_preacher`/`series`/`topic`/`book`/`service_type` => term id(s)) that decide WHICH
 * sermons the feed carries. Because the `sanitize_callback` fires on EVERY write — including the
 * migration's own `add_post_meta()` — DROPPING unrecognized keys here would silently wipe the
 * feed's term-scoping and the subscribe URLs on every (supported) migration re-run: a #1-standard
 * data-preservation clobber. So {@see self::sanitize()} PRESERVES unrecognized keys (hardening
 * them recursively, never dropping). Feed *injection* of an unknown key is independently blocked
 * at READ: {@see \Sermonator\Frontend\Feed\PodcastConfigFactory} intersects stored meta against
 * {@see self::keys()} before emitting, so an unrecognized key can never reach the public channel.
 *
 * This clears the §5.D audio-backfill bar for the podcast-settings write path: sanitize-at-write,
 * reversible (it only ever narrows/cleans values; it never drops a key or invents data), and gated
 * behind `manage_options`.
 *
 * WIRING (Bundle 4 Task 7): {@see self::register()} is hooked on `init` UNCONDITIONALLY in
 * {@see \Sermonator\Plugin::boot()} (NOT the admin-only registerAdmin()), mirroring
 * {@see \Sermonator\Admin\Authoring\SermonMetaRegistrar} via AuthoringServiceProvider. It must run
 * in front-end + cron + admin contexts because the governance guards the feed read path and the
 * migration's own add_post_meta() — neither runs under is_admin() — so the admin-only SettingsPage
 * (add_submenu_page-scoped) cannot host it.
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
     * `copyright`, `language`), the channel-level companions the factory derives or a feed may
     * carry (`description`, `subcategory`, `link`), and the subscribe-link URLs
     * {@see \Sermonator\Frontend\Blocks\PodcastSubscribeBlock} reads from this same blob
     * (`apple_url`, `spotify_url`). Recognized keys are sanitized per type; any OTHER key
     * (e.g. a migration term-filter scope key) is preserved verbatim-but-hardened, never dropped.
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
        'apple_url'   => self::T_URL,
        'spotify_url' => self::T_URL,
    );

    /**
     * The shared key catalog — the SINGLE source of truth for which podcast-identity keys are
     * recognized. Consumed by both the reader (factory, which intersects stored meta against it)
     * and the writer (admin controller) so a key added here is honored everywhere. A key NOT here
     * is not "identity" — the factory never emits it, but the write path preserves it (see
     * {@see self::sanitize()}).
     *
     * @return list<string>
     */
    public static function keys(): array {
        return array_keys( self::KEYS );
    }

    /**
     * Register the podcast-settings post meta with auth + sanitize governance. Hooked on `init`
     * unconditionally in {@see \Sermonator\Plugin::boot()} (mirroring the sermon meta registration
     * via AuthoringServiceProvider), so it runs in admin, front-end, cron, and CLI contexts — the
     * governance guards the feed read path and the migration's own add_post_meta(), neither of which
     * runs under is_admin(). Idempotent: WordPress treats a second registration of the same key as
     * an overwrite.
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
     * Sanitize a podcast-settings array: recognized keys are cleaned per their declared type;
     * UNRECOGNIZED keys are PRESERVED (recursively hardened, never dropped) because this same blob
     * carries migration term-filter scope keys that decide which sermons the feed serves —
     * dropping them on a write would be a #1-standard data clobber. See the class docblock for why
     * preservation here does not open a feed-injection hole (the factory intersects on read).
     *
     * A non-array input (the meta was never set, or was corrupted) sanitizes to an empty array
     * rather than throwing — the factory's own fallbacks then produce a valid empty channel.
     *
     * @param mixed $value
     * @return array<array-key,mixed>
     */
    public static function sanitize( $value ): array {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $clean = array();
        foreach ( $value as $key => $raw ) {
            if ( is_string( $key ) && isset( self::KEYS[ $key ] ) ) {
                $clean[ $key ] = self::sanitizeValue( self::KEYS[ $key ], $raw );
                continue;
            }
            // Not an identity key (e.g. a migration term-filter scope key): preserve, hardened.
            $clean[ $key ] = self::sanitizeUnknown( $raw );
        }

        return $clean;
    }

    /**
     * Recursively harden a preserved-but-unrecognized value WITHOUT altering its shape. Integer /
     * float term ids (the migration's term-filter scope values) and booleans pass through
     * verbatim; strings run through `sanitize_text_field` (idempotent for the slug/id values these
     * keys actually hold); arrays recurse. A non-scalar leaf (object/resource) is dropped to null.
     *
     * @param mixed $raw
     * @return mixed
     */
    private static function sanitizeUnknown( $raw ) {
        if ( is_array( $raw ) ) {
            $out = array();
            foreach ( $raw as $key => $value ) {
                $cleanKey         = is_string( $key ) ? sanitize_text_field( $key ) : $key;
                $out[ $cleanKey ] = self::sanitizeUnknown( $value );
            }
            return $out;
        }
        if ( is_bool( $raw ) || is_int( $raw ) || is_float( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) ) {
            return sanitize_text_field( $raw );
        }
        return null;
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
                // Apple taxonomy term so the feed is never rejected.
                if ( '' === $value ) {
                    return '';
                }
                $normalized = ItunesCategory::normalize( $value );
                // Persist the MOST SPECIFIC valid term. PodcastConfigFactory stores identity in a
                // single `category` string and RE-RUNS ItunesCategory::normalize() on it to derive
                // the nested <itunes:category> subcategory; a subcategory term is itself a valid
                // normalize() input that recovers its parent category, so persisting it round-trips
                // both levels losslessly. Returning only the top-level category (as before) would
                // drop the subcategory — the dominant 'Christianity' case for a sermon feed — a
                // feed-fidelity regression. Only an unrecognized input collapses to the default
                // top-level category.
                return $normalized['subcategory'] ?? $normalized['category'];

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
