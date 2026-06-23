<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure transform of term artwork mappings and settings.
 * - remapImages: remaps legacy tt_id keys (with numeric-string key casting) to new tt_ids via crosswalk,
 *   keeping attachment_id verbatim. Records dropped (no crosswalk entry) and conflicts (multiple legacy
 *   tt_ids → same new tt_id). Never overwrites.
 * - remapSettings: re-keys taxonomy-name keys via MappingContract::taxonomyMap(), passes through all
 *   other (global) keys verbatim.
 * No WordPress, no side effects.
 */
final class TermArtworkMapper {
    /**
     * Remap legacy term_taxonomy_id keys to new tt_ids via crosswalk.
     *
     * @param array<int|string, int> $legacyImages Legacy tt_id (int or numeric-string) → attachment_id.
     * @param array<int|string, int> $ttIdCrosswalk Legacy tt_id (int or numeric-string) → new tt_id (int).
     * @return array{images: array<int, int>, dropped: list<int>, conflicts: list<int>, conflict_details: list<array{new_tt_id: int, legacy_tt_id: int, discarded_attachment_id: int, winning_attachment_id: int}>} New tt_id → attachment_id; dropped legacy tt_ids; new tt_ids with collisions; per-collision detail preserving the LOSING attachment_id for admin recovery.
     */
    public static function remapImages( array $legacyImages, array $ttIdCrosswalk ): array {
        $images   = array();
        $dropped  = array();
        $conflicts = array();
        // IMPORTANT #8: the FIRST-WINS collision branch below would otherwise discard
        // the losing attachment_id unrecoverably — only the winning new tt_id was
        // recorded. Capture the full detail (losing legacy tt_id + discarded
        // attachment_id + the winner that kept the slot) so an admin can recover the
        // dropped term artwork from the migration progress record.
        $conflictDetails = array();

        foreach ( $legacyImages as $legacyTtId => $attachmentId ) {
            $legacyTtIdInt = (int) $legacyTtId;

            // Check if this legacy tt_id has a crosswalk entry.
            if ( ! isset( $ttIdCrosswalk[ $legacyTtIdInt ] ) ) {
                $dropped[] = $legacyTtIdInt;
                continue;
            }

            $newTtId = $ttIdCrosswalk[ $legacyTtIdInt ];

            // Detect collision: if newTtId already exists in images, record conflict and don't overwrite.
            if ( isset( $images[ $newTtId ] ) ) {
                if ( ! in_array( $newTtId, $conflicts, true ) ) {
                    $conflicts[] = $newTtId;
                }
                // Preserve the losing association so it is recoverable, not silently lost.
                $conflictDetails[] = array(
                    'new_tt_id'               => (int) $newTtId,
                    'legacy_tt_id'            => $legacyTtIdInt,
                    'discarded_attachment_id' => (int) $attachmentId,
                    'winning_attachment_id'   => (int) $images[ $newTtId ],
                );
                continue;
            }

            $images[ $newTtId ] = $attachmentId;
        }

        return array(
            'images'           => $images,
            'dropped'          => $dropped,
            'conflicts'        => $conflicts,
            'conflict_details' => $conflictDetails,
        );
    }

    /**
     * Re-key settings: taxonomy names → sermonator_ equivalents; pass through globals.
     *
     * @param array<string, mixed> $legacySettings Legacy settings (top-level keys may match taxonomy names or be global).
     * @return array<string, mixed> Re-keyed settings.
     */
    public static function remapSettings( array $legacySettings ): array {
        $taxonomyMap = MappingContract::taxonomyMap();
        $out         = array();

        foreach ( $legacySettings as $key => $value ) {
            // If key is a legacy taxonomy name, re-key it; otherwise pass through verbatim.
            $newKey      = $taxonomyMap[ $key ] ?? $key;
            $out[ $newKey ] = $value;
        }

        return $out;
    }

    private function __construct() {}
}
