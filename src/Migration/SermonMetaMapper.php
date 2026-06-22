<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure transform of a legacy sermon's post meta into the new namespace.
 * Renames known keys, extracts sermon_description (→ post_content, returned
 * separately), drops the denormalized service-type meta, passes through every
 * unknown key verbatim, and flags non-numeric legacy dates without altering
 * the raw value. No WordPress, no side effects.
 */
final class SermonMetaMapper {
    /**
     * @param array<string, list<string>> $legacyMeta Key → list of raw values (as get_post_meta($id) returns).
     * @return array{meta: array<string, list<string>>, description: ?string, flags: list<string>}
     */
    public static function map( array $legacyMeta ): array {
        $keyMap  = MappingContract::metaKeyMap();
        $dropped = MappingContract::droppedMetaKeys();

        $meta        = array();
        $description = null;
        $flags       = array();

        foreach ( $legacyMeta as $key => $values ) {
            if ( LegacyIdentifiers::META_DESCRIPTION === $key ) {
                $description = $values[0] ?? '';
                continue;
            }

            if ( in_array( $key, $dropped, true ) ) {
                continue; // e.g. wpfc_service_type denormalized copy
            }

            if ( LegacyIdentifiers::META_DATE === $key && isset( $values[0] ) && ! self::isUnixTimestamp( $values[0] ) ) {
                $flags[] = 'legacy_nonnumeric_date';
            }

            $newKey          = $keyMap[ $key ] ?? $key; // known → renamed; unknown → verbatim
            $meta[ $newKey ] = $values;
        }

        return array(
            'meta'        => $meta,
            'description' => $description,
            'flags'       => $flags,
        );
    }

    private static function isUnixTimestamp( string $value ): bool {
        return '' !== $value && ctype_digit( ltrim( $value, '-' ) ) && '' !== ltrim( $value, '-' );
    }

    private function __construct() {}
}
