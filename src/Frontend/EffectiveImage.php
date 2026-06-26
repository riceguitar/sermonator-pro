<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\DisplayDefaults;
use Sermonator\Schema\Identifiers as ID;

/**
 * Shared resolver for the effective featured-image attachment id of a sermon
 * (Bundle 4, spec §1.7 / Task 5): the REAL post thumbnail when one is set,
 * otherwise the configured site-wide default image — restoring legacy
 * `default_image` parity (Sermon Manager rendered a fallback when a sermon had no
 * thumbnail). The single resolver is reused by BOTH the single-sermon
 * featured-image fallback and the term images grid, so the two never drift.
 *
 * IMPURITY IS DELIBERATELY HERE, NOT IN THE RENDERER. The default id is read from
 * the live {@see ID::OPTION_DEFAULT_IMAGE_ID}; a STORED value is honored verbatim
 * (including an explicit `0` meaning "no fallback image" — a deliberate admin
 * choice the write boundary persists, which must never be resurrected over). Only
 * when the live row is genuinely ABSENT does this class fall through to the
 * id-keyed {@see DisplayDefaults::defaultImageId()} seed — `register_setting()`'s
 * registered default is absent at `init@5` and on the front end, so that explicit
 * seed is the only thing that seeds the value on this path.
 *
 * ONE-TIME, ADMIN/CRON-ONLY URL→ID RESOLUTION. A migrated CMB2 `default_image` may
 * have been stored only as a URL (its companion `default_image_id` absent), which
 * {@see DisplayDefaults::defaultImageId()} deliberately skips (a URL is not an id).
 * When neither the live key nor the id-keyed seed yields an attachment, this class
 * resolves that legacy URL to an attachment id via `attachment_url_to_postid()`
 * exactly ONCE and PERSISTS the result into the live id key — so it can never
 * become a per-render lookup. That scan + persist is GATED to a privileged context
 * (`is_admin() || wp_doing_cron()`, mirroring `SlugRewriteFlusher`); a front-end
 * visitor never triggers the postmeta scan or the DB write. Persisting only a
 * POSITIVE resolution leaves the live key untouched when nothing resolves, so a
 * later migration can still seed it.
 */
final class EffectiveImage {
    /** Legacy (pre-migration) Sermon Manager option-name prefix. */
    private const LEGACY_PREFIX = 'sermonmanager_';

    /** The CMB2 settings-tab container sub-key holding `default_image`. */
    private const CONTAINER_DISPLAY = 'display';

    /** The bare (URL-valued) CMB2 image field key inside the display container. */
    private const KEY_DEFAULT_IMAGE = 'default_image';

    /**
     * The effective featured-image attachment id for a sermon: the real post
     * thumbnail id when set (it always wins), otherwise the configured default
     * image id, otherwise 0.
     *
     * @param int $realThumbnailId The sermon's actual `_thumbnail_id` (0 when none).
     */
    public function resolve( int $realThumbnailId ): int {
        if ( $realThumbnailId > 0 ) {
            return $realThumbnailId;
        }

        return $this->defaultImageId();
    }

    /**
     * The configured site-wide default image attachment id (0 when none).
     *
     * A STORED live value is honored verbatim — including an explicit `0`, which
     * the {@see \Sermonator\Admin\DisplaySettingsRegistrar::sanitizeImageId()}
     * write boundary persists to mean "no fallback image" (a deliberate admin
     * choice that must never be overridden — #1 data preservation). `get_option`
     * returns the `false` sentinel ONLY when the option row is genuinely ABSENT,
     * so testing against `false` (not non-positivity) is what distinguishes a
     * deliberately-cleared `0` from an unset key.
     *
     * Only when the live key is genuinely unset do we consult the id-keyed
     * migration/legacy seed and, failing that, the one-time legacy-URL→id
     * resolution (itself gated to a privileged context — the front end never
     * pays for the scan or the persist).
     */
    public function defaultImageId(): int {
        $raw = get_option( ID::OPTION_DEFAULT_IMAGE_ID, false );

        if ( false !== $raw ) {
            return max( 0, (int) $raw );
        }

        $seed = DisplayDefaults::defaultImageId();

        if ( $seed > 0 ) {
            return $seed;
        }

        return $this->resolveAndPersistLegacyImageUrl();
    }

    /**
     * Resolve a migrated/legacy URL-valued `default_image` to an attachment id ONCE
     * and persist it into the live id key. Walks the migrated display container
     * first, then the legacy one; returns the first positive resolution (after
     * persisting it) or 0 when nothing resolves (leaving the live key unset so a
     * later migration can still seed it).
     *
     * FRONT END NEVER PAYS (mirrors {@see \Sermonator\Frontend\SlugRewriteFlusher}'s
     * `is_admin() || wp_doing_cron()` scope). `TemplateData::sermon()` runs on the
     * public render path — the single template, SeoHead, every block, AND once per
     * sermon in archive/taxonomy card loops — so an unscoped write here would let an
     * unauthenticated visitor trigger an `attachment_url_to_postid()` postmeta scan
     * plus a persistent `update_option()` on every cache-miss request. The scan +
     * persist are therefore deferred to the next admin/cron pass; a front-end
     * visitor reads only the live key + id-keyed seed (no scan, no write).
     */
    private function resolveAndPersistLegacyImageUrl(): int {
        if ( ! is_admin() && ! wp_doing_cron() ) {
            return 0;
        }

        $containers = array(
            ID::OPTION_PREFIX . self::CONTAINER_DISPLAY,
            self::LEGACY_PREFIX . self::CONTAINER_DISPLAY,
        );

        foreach ( $containers as $optionName ) {
            $container = get_option( $optionName );

            if ( ! is_array( $container ) || ! array_key_exists( self::KEY_DEFAULT_IMAGE, $container ) ) {
                continue;
            }

            $url = $container[ self::KEY_DEFAULT_IMAGE ];

            if ( ! is_string( $url ) || '' === trim( $url ) ) {
                continue;
            }

            $id = (int) attachment_url_to_postid( $url );

            if ( $id > 0 ) {
                update_option( ID::OPTION_DEFAULT_IMAGE_ID, $id );

                return $id;
            }
        }

        return 0;
    }
}
