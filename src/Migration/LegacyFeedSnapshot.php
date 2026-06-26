<?php
declare(strict_types=1);

namespace Sermonator\Migration;

use Sermonator\Schema\Identifiers;

/**
 * Persists the legacy podcast feed's per-episode GUID, captured at detect/migrate time before
 * Finalize, keyed by legacy post ID. Replayed by the feed layer so already-subscribed apps do
 * not re-download or drop episodes after the switch (rollback story 1). Read-only against
 * legacy data; the stored map is the only writable artifact and is reversible (delete option).
 */
final class LegacyFeedSnapshot {
    public const OPTION = Identifiers::OPTION_LEGACY_FEED_SNAPSHOT;

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

    /**
     * Stamp the legacy GUID durably onto the NEW post id so the replay survives
     * Finalize, which strips the Crosswalk LEGACY_POST_ID back-ref the pre-Finalize
     * translation path depends on. Written by the Finalizer for every verified
     * counterpart BEFORE the strip, so the new-id-keyed source exists before its
     * legacy-id bridge is destroyed. Idempotent (update_post_meta); a no-op when no
     * snapshot GUID exists for the legacy id (e.g. podcasts, or a never-captured id).
     */
    public function makeDurable( int $newPostId, int $legacyPostId ): void {
        $guid = $this->guidFor( $legacyPostId );
        if ( $guid !== null ) {
            update_post_meta( $newPostId, Identifiers::META_LEGACY_GUID, $guid );
        }
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
