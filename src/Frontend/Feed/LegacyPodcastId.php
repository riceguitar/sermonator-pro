<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Feed;

use Sermonator\Migration\Crosswalk;
use Sermonator\Schema\Identifiers as ID;

/**
 * Resolves a legacy podcast id (from a legacy ?id=<n> feed URL) to the migrated
 * sermonator_podcast id, so legacy podcast feed URLs keep working after migration.
 *
 * Layered, durable resolution:
 *   1. legacyId <= 0 -> the default podcast (legacy feeds with no id targeted it).
 *   2. The durable OPTION_LEGACY_PODCAST_MAP -> post-Finalize-safe (survives the
 *      Finalizer, which strips the Crosswalk back-ref meta).
 *   3. Crosswalk::findNewByLegacyId() -> works PRE-Finalize via the LEGACY_POST_ID
 *      back-ref (stripped at Finalize, hence layer 2 above for durability).
 *   4. The default podcast -> last-resort fallback so the feed never 404s.
 */
final class LegacyPodcastId {
    public function resolve( int $legacyId ): int {
        if ( $legacyId <= 0 ) {
            return (int) get_option( ID::OPTION_DEFAULT_PODCAST, 0 );
        }

        // Durable map: the only post-Finalize-safe source.
        $map = get_option( ID::OPTION_LEGACY_PODCAST_MAP, array() );
        if ( is_array( $map ) && isset( $map[ $legacyId ] ) && (int) $map[ $legacyId ] > 0 ) {
            return (int) $map[ $legacyId ];
        }

        // Pre-Finalize fallback via the Crosswalk back-ref meta. (Reads $wpdb;
        // not unit-testable under Brain Monkey — covered by integration/inspection.)
        $new = Crosswalk::findNewByLegacyId( $legacyId, ID::POST_TYPE_PODCAST );
        if ( is_int( $new ) && $new > 0 ) {
            return $new;
        }

        return (int) get_option( ID::OPTION_DEFAULT_PODCAST, 0 );
    }
}
