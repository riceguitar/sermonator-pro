<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Compat;

use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers;

/**
 * Shared legacy-post-id -> NEW post id resolver for the `[sermons]` mapper's
 * `include`/`exclude` (aliases `id`/`sermon`/`sermons`) axes (T4).
 *
 * Resolution is via Migration\Crosswalk::findNewByLegacyId, the LEGACY_POST_ID
 * back-ref. That back-ref is strippable (Crosswalk::strippableBackRefs()), so
 * this works PRE-Finalize only; post-Finalize it returns a miss rather than
 * passing the legacy id through as if it were a new id (passing legacy id N
 * through would address a DIFFERENT new post N — the canonical fail-wrong).
 *
 * Non-resolution returns LegacyResolution::miss() with a precise reason; the
 * caller drops that id from the set and names it in the editor notice.
 *
 * NOTE: the durable, post-Finalize-safe path for legacy PODCAST ids is the
 * separate OPTION_LEGACY_PODCAST_MAP (see Frontend\Feed\LegacyPodcastId); this
 * resolver is for the back-ref-based sermon include/exclude axes.
 *
 * Pure-ish: WP-API (the crosswalk reads $wpdb) only, no Renderer.
 */
final class LegacyPostResolver {
    /**
     * Resolve a legacy post id to its NEW post id, scoped to a post type
     * (default: the sermon post type). Pre-Finalize only.
     */
    public function resolveByLegacyId(
        int $legacyPostId,
        string $postType = Identifiers::POST_TYPE_SERMON
    ): LegacyResolution {
        if ( $legacyPostId <= 0 ) {
            return LegacyResolution::miss( 'invalid_legacy_post_id:' . $legacyPostId );
        }

        $newId = Crosswalk::findNewByLegacyId( $legacyPostId, $postType );

        if ( $newId === null || $newId <= 0 ) {
            return LegacyResolution::miss( 'legacy_post_id_unresolved:' . $legacyPostId );
        }

        return LegacyResolution::hit( $newId );
    }
}
