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
 * the live {@see ID::OPTION_DEFAULT_IMAGE_ID} with an EXPLICIT
 * {@see DisplayDefaults::defaultImageId()} fallback — `register_setting()`'s
 * registered default is absent at `init@5` and on the front end, so the explicit
 * fallback is the only thing that seeds the value on this path.
 *
 * ONE-TIME URL→ID RESOLUTION. A migrated CMB2 `default_image` may have been stored
 * only as a URL (its companion `default_image_id` absent), which
 * {@see DisplayDefaults::defaultImageId()} deliberately skips (a URL is not an id).
 * When neither the live key nor the id-keyed seed yields an attachment, this class
 * resolves that legacy URL to an attachment id via `attachment_url_to_postid()`
 * exactly ONCE and PERSISTS the result into the live id key — so it can never
 * become a per-render lookup. Persisting only a POSITIVE resolution leaves the
 * live key untouched when nothing resolves, so a later migration can still seed it.
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
     * Reads the live key with an explicit {@see DisplayDefaults::defaultImageId()}
     * fallback; on a miss, performs the one-time legacy-URL→id resolution.
     */
    public function defaultImageId(): int {
        $live = (int) get_option( ID::OPTION_DEFAULT_IMAGE_ID, DisplayDefaults::defaultImageId() );

        if ( $live > 0 ) {
            return $live;
        }

        return $this->resolveAndPersistLegacyImageUrl();
    }

    /**
     * Resolve a migrated/legacy URL-valued `default_image` to an attachment id ONCE
     * and persist it into the live id key. Walks the migrated display container
     * first, then the legacy one; returns the first positive resolution (after
     * persisting it) or 0 when nothing resolves (leaving the live key unset so a
     * later migration can still seed it).
     */
    private function resolveAndPersistLegacyImageUrl(): int {
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
