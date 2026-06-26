<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Read-only capture of each legacy sermon's podcast-feed GUID, taken at DETECT time
 * (while the legacy posts still exist, before Finalize destroys them) so the rewritten
 * podcast feed can replay the exact GUID a subscriber's app already stored — preventing
 * a full back-catalogue re-download after the switch (rollback story 1).
 *
 * The legacy feed emits `<guid isPermaLink="false"><?php the_guid(); ?></guid>`
 * (Sermon-Manager-2.15.15/views/wpfc-podcast-feed.php:352), so the per-episode GUID is
 * the legacy post's WordPress `guid`. We reproduce `the_guid()` EXACTLY — get_the_guid()
 * passed through the `the_guid` output filter — so the captured string matches what every
 * legacy subscriber's app recorded. Performs no writes; the caller persists the map via
 * {@see LegacyFeedSnapshot::store()}.
 */
final class LegacyFeedGuidCapturer {
    /** @return array<int,string> legacy sermon post id → the exact legacy feed GUID */
    public function capture(): array {
        // The legacy plugin is normally DEACTIVATED at migration time; re-register so the
        // wpfc_sermon rows are queryable (no-op when active). Mirrors Detector::detect().
        LegacySchemaRegistrar::ensureRegistered();

        $ids = get_posts( array(
            'post_type'      => LegacyIdentifiers::POST_TYPE_SERMON,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        $map = array();
        foreach ( $ids as $id ) {
            $id   = (int) $id;
            $guid = (string) apply_filters( 'the_guid', get_the_guid( $id ), $id );
            if ( $guid !== '' ) {
                $map[ $id ] = $guid;
            }
        }

        return $map;
    }
}
