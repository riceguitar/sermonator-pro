<?php

declare(strict_types=1);

namespace Sermonator\Migration;

/**
 * The SINGLE source of truth for the embedded-term-id remap applied to the CLOSED
 * set of id-bearing default-term options (sermonator_default_series / _preacher /
 * _service_type / _book / _topic).
 *
 * Both OptionWriter (which WRITES the remapped value) and Verifier (which must
 * compute the EXPECTED remapped value to compare against, never legacy==new) call
 * this helper so the two can never drift apart. The remap is:
 *
 *  - keyed by the NEW option name (the sermonator_* name the value lands under);
 *  - type-preserving: an int input yields an int output, a numeric-string input a
 *    string output;
 *  - recursive into arrays (a default-term option may store a scalar id OR an
 *    array of ids);
 *  - leaves an unresolvable positive id VERBATIM and records a
 *    missing_option_id_crosswalk:<newOptionName> flag for a later self-heal pass;
 *  - leaves a term id of 0 / non-positive / non-numeric scalar untouched (0 means
 *    "no default term", never a crosswalk lookup).
 *
 * Attachment ids embedded in OTHER options are SHARED globals (identical in both
 * schemas) and are intentionally NOT in TERM_ID_OPTIONS — they are never remapped.
 */
final class OptionIdRemapper {
    /**
     * The CLOSED set of NEW option names whose VALUES embed legacy TERM IDs.
     *
     * @var list<string>
     */
    public const TERM_ID_OPTIONS = array(
        'sermonator_default_series',
        'sermonator_default_preacher',
        'sermonator_default_service_type',
        'sermonator_default_book',
        'sermonator_default_topic',
    );

    /** Whether the NEW option name is one whose value embeds legacy term ids. */
    public static function isTermIdOption( string $newOptionName ): bool {
        return in_array( $newOptionName, self::TERM_ID_OPTIONS, true );
    }

    /**
     * Compute the remapped value for a (possibly) id-bearing option.
     *
     * For a non-TERM_ID_OPTIONS name the value is returned unchanged with no flags.
     * For a TERM_ID_OPTIONS name every embedded legacy term id is translated via
     * TermCrosswalk::newTermId(); an unresolvable positive id is left verbatim and
     * appends a missing_option_id_crosswalk:<newOptionName> flag.
     *
     * @return array{value: mixed, flags: list<string>}
     */
    public static function remap( string $newOptionName, mixed $value, TermCrosswalk $crosswalk ): array {
        if ( ! self::isTermIdOption( $newOptionName ) ) {
            return array( 'value' => $value, 'flags' => array() );
        }

        $flags = array();
        $value = self::remapValue( $newOptionName, $value, $crosswalk, $flags );

        return array( 'value' => $value, 'flags' => $flags );
    }

    /**
     * Recursively translate legacy term ids embedded in a value (scalar or array).
     *
     * @param list<string> $flags Accumulated by reference.
     * @return mixed The (possibly remapped) value.
     */
    private static function remapValue( string $optionName, mixed $value, TermCrosswalk $crosswalk, array &$flags ): mixed {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::remapValue( $optionName, $v, $crosswalk, $flags );
            }
            return $out;
        }

        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) && '' !== $value ) ) {
            $legacyTermId = (int) $value;
            if ( $legacyTermId > 0 ) {
                $newTermId = $crosswalk->newTermId( $legacyTermId );
                if ( null !== $newTermId ) {
                    // Type-preserving: if original was a string, return a string.
                    return is_string( $value ) ? (string) $newTermId : $newTermId;
                }
                // Crosswalk not yet available — leave verbatim and flag for self-heal.
                $flags[] = 'missing_option_id_crosswalk:' . $optionName;
            }
        }

        return $value;
    }

    private function __construct() {}
}
