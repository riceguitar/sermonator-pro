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
     * @return array{images: array<int, int>, dropped: list<int>, conflicts: list<int>} New tt_id → attachment_id; dropped legacy tt_ids; new tt_ids with collisions.
     */
    public static function remapImages( array $legacyImages, array $ttIdCrosswalk ): array {
        $images   = array();
        $dropped  = array();
        $conflicts = array();

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
                continue;
            }

            $images[ $newTtId ] = $attachmentId;
        }

        return array(
            'images'    => $images,
            'dropped'   => $dropped,
            'conflicts' => $conflicts,
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
