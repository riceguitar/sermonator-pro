<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyFeedSnapshot;
use Sermonator\Schema\Identifiers as ID;

/**
 * Resolves the RSS <guid> for an episode by its DURABLE new sermonator_sermon post
 * id, so already-subscribed podcast apps never re-download the back catalogue after
 * the switch (rollback story 1).
 *
 * The migration is non-destructive: SermonWriter inserts a FRESH sermonator_sermon
 * for each legacy wpfc_sermon, so the new post id != the legacy post id (linked only
 * via the Crosswalk LEGACY_POST_ID back-ref). The feed queries by — and therefore
 * only ever holds — the NEW post id, while LegacyFeedSnapshot is keyed by the LEGACY
 * post id. Resolving the GUID by the new id against a legacy-keyed map would miss for
 * every migrated episode and silently fall back to 'sermonator-<id>', re-churning the
 * whole back catalogue. This resolver bridges the id spaces durably.
 *
 * Layered, durable resolution (mirrors LegacyPodcastId):
 *   1. The durable META_LEGACY_GUID stamped on the new post -> post-Finalize-safe
 *      (survives the Finalizer, which strips the Crosswalk LEGACY_POST_ID back-ref).
 *   2. PRE-Finalize: translate the new id -> legacy id via the Crosswalk back-ref,
 *      then replay the legacy-keyed LegacyFeedSnapshot GUID. The back-ref is stripped
 *      at Finalize, hence layer 1 for durability.
 *   3. 'sermonator-<newId>' -> a brand-new (never-migrated) episode, or last resort.
 */
final class LegacyEpisodeGuid {
    private LegacyFeedSnapshot $snapshot;

    public function __construct( ?LegacyFeedSnapshot $snapshot = null ) {
        $this->snapshot = $snapshot ?? new LegacyFeedSnapshot();
    }

    public function resolve( int $newPostId ): string {
        // Layer 1 — durable, post-Finalize-safe: the legacy GUID stamped on the new post.
        $durable = get_post_meta( $newPostId, ID::META_LEGACY_GUID, true );
        if ( is_string( $durable ) && $durable !== '' ) {
            return $durable;
        }

        // Layer 2 — pre-Finalize: translate new id -> legacy id via the Crosswalk
        // back-ref, then replay the legacy-keyed snapshot GUID.
        $legacyId = (int) get_post_meta( $newPostId, Crosswalk::LEGACY_POST_ID, true );
        if ( $legacyId > 0 ) {
            $guid = $this->snapshot->guidFor( $legacyId );
            if ( $guid !== null ) {
                return $guid;
            }
        }

        // Layer 3 — never-migrated episode, or last resort.
        return 'sermonator-' . $newPostId;
    }
}
