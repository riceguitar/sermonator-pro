<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * Pure transform of legacy settings options into the new namespace. Only
 * `sermonmanager_*` options are mapped (prefix swap); values are copied
 * verbatim, preserving type. No WordPress, no side effects.
 */
final class OptionMapper {
    /**
     * @param array<string,mixed> $legacyOptions Legacy option name → value.
     * @return array<string,mixed> New option name → value.
     */
    public static function map( array $legacyOptions ): array {
        $out = array();
        foreach ( $legacyOptions as $name => $value ) {
            $newName = MappingContract::mapOptionName( $name );
            if ( null === $newName ) {
                continue;
            }
            $out[ $newName ] = $value;
        }
        return $out;
    }

    private function __construct() {}
}
