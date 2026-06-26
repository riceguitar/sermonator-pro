<?php
declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Persists the legacy podcast feed's per-episode GUID, captured at detect/migrate time before
 * Finalize, keyed by legacy post ID. Replayed by the feed layer so already-subscribed apps do
 * not re-download or drop episodes after the switch (rollback story 1). Read-only against
 * legacy data; the stored map is the only writable artifact and is reversible (delete option).
 */
final class LegacyFeedSnapshot {
    public const OPTION = 'sermonator_legacy_feed_snapshot';

    /** @param array<int,string> $guidByLegacyPostId */
    public function store( array $guidByLegacyPostId ): void {
        $clean = array();
        foreach ( $guidByLegacyPostId as $id => $guid ) {
            if ( (int) $id > 0 && is_string( $guid ) && $guid !== '' ) {
                $clean[ (int) $id ] = $guid;
            }
        }
        update_option( self::OPTION, $clean, false );
    }

    public function guidFor( int $legacyPostId ): ?string {
        $map = get_option( self::OPTION, array() );
        if ( ! is_array( $map ) ) {
            return null;
        }
        $guid = $map[ $legacyPostId ] ?? null;
        return is_string( $guid ) && $guid !== '' ? $guid : null;
    }
}
