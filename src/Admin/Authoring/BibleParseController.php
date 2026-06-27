<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Bible\RefValidator;
use Sermonator\Bible\ReferenceParser;
use Sermonator\Schema\Identifiers;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Read-only REST endpoint backing the confirm-chip authoring UI.
 *
 * Given a submitted free-text passage it returns the {@see ReferenceParser} segments
 * and the structurally-valid candidate refs (each annotated with the pure
 * {@see RefValidator} flags) so the editor can render LIVE confirm chips with per-chip
 * eligibility labels as the author types — WITHOUT a save and WITHOUT touching the
 * database. It mirrors {@see AudioMetaController}: same `sermonator/v1` namespace, same
 * GET-only / permission_callback shape, and the same write-nothing contract. The author
 * confirming chips and saving is what promotes a ref to `exact`; this endpoint only
 * previews the parse, so it is gated by the authoring guard but writes nothing.
 *
 * It is NOT a versification gate and makes NO claim that a candidate ref will render
 * inline — `inlineEligible` here is only the pure structural pre-filter (L1/L3); the
 * source→target versification relation (L4–L9) is resolved off the render path. The UI
 * uses these flags only to label chips, never to decide what gets stored.
 */
final class BibleParseController {
    public const ROUTE = '/bible-parse';

    public function register(): void {
        register_rest_route(
            'sermonator/v1',
            self::ROUTE,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'parse' ),
                    'permission_callback' => array( $this, 'canParse' ),
                    'args'                => array(
                        'passage' => array(
                            'type'              => 'string',
                            'default'           => '',
                            'validate_callback' => static function ( $value ): bool {
                                return is_string( $value );
                            },
                        ),
                    ),
                ),
            )
        );
    }

    public function canParse(): bool {
        return MigrationGuard::editingAllowed()
            && current_user_can( 'edit_' . Identifiers::POST_TYPE_SERMON . 's' );
    }

    /**
     * Parse the submitted passage into segments + candidate refs. Pure read: the
     * ReferenceParser and RefValidator are both side-effect-free and this method never
     * writes meta, terms, options, or transients.
     */
    public function parse( WP_REST_Request $request ): WP_REST_Response {
        $passage = (string) $request->get_param( 'passage' );

        $parsed   = ReferenceParser::parse( $passage );
        $segments = array();
        $refs     = array();

        foreach ( $parsed['segments'] as $segment ) {
            $segmentRefs = array();

            foreach ( $segment['refs'] as $ref ) {
                if ( ! is_array( $ref ) ) {
                    continue;
                }

                $annotated = $ref;
                $annotated['validation'] = RefValidator::validate( $ref );
                $segmentRefs[]           = $annotated;

                // The flat `refs` list is the confirmable-chip candidate set: only
                // in-canon, structurally-valid refs the author could confirm.
                if ( $annotated['validation']['inCanon'] && $annotated['validation']['structurallyValid'] ) {
                    $refs[] = $annotated;
                }
            }

            $segments[] = array(
                'raw'    => $segment['raw'],
                'status' => $segment['status'],
                'refs'   => $segmentRefs,
            );
        }

        return new WP_REST_Response(
            array(
                'passage'  => $passage,
                'segments' => $segments,
                'refs'     => $refs,
            ),
            200
        );
    }
}
