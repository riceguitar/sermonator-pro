<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Frontend\Feed\AudioHeadProbe;
use Sermonator\Schema\Identifiers;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint for resolving an audio attachment or URL into duration, size, and MIME.
 *
 * The endpoint is read-only with respect to the database: it never writes post meta. The
 * editor writes the returned values as ordinary meta on save, which keeps the write path
 * uniform and auditable.
 */
final class AudioMetaController {
    public const ROUTE = '/audio-metadata';

    public function register(): void {
        register_rest_route(
            'sermonator/v1',
            self::ROUTE,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'resolve' ),
                    'permission_callback' => array( $this, 'canResolve' ),
                    'args'                => array(
                        'attachmentId' => array(
                            'type'              => 'integer',
                            'default'           => 0,
                            'validate_callback' => static function ( $value ): bool {
                                return is_numeric( $value ) && (int) $value >= 0;
                            },
                        ),
                        'url'          => array(
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

    public function canResolve(): bool {
        return MigrationGuard::editingAllowed()
            && current_user_can( 'edit_' . Identifiers::POST_TYPE_SERMON . 's' );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function resolve( WP_REST_Request $request ) {
        $attachment_id = (int) $request->get_param( 'attachmentId' );
        $url           = (string) $request->get_param( 'url' );

        if ( $attachment_id > 0 ) {
            return $this->resolveAttachment( $attachment_id );
        }

        if ( $url !== '' ) {
            return $this->resolveUrl( $url );
        }

        return new WP_Error(
            'sermonator_audio_metadata_missing_input',
            __( 'Provide an attachmentId or a URL.', 'sermonator' ),
            array( 'status' => 400 )
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    private function resolveAttachment( int $attachment_id ) {
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! is_array( $metadata ) ) {
            // Fix 3: returning HTTP 200 with zeros here causes the editor to clobber
            // whatever the user previously stored. Return 404 so the editor knows the
            // attachment is genuinely missing and leaves stored values intact.
            return new WP_Error(
                'sermonator_attachment_not_found',
                __( 'Attachment not found or has no metadata.', 'sermonator' ),
                array( 'status' => 404 )
            );
        }

        $duration = is_string( $metadata['length_formatted'] ?? null ) ? $metadata['length_formatted'] : '';
        $size     = isset( $metadata['filesize'] ) ? (int) $metadata['filesize'] : 0;
        $mime     = is_string( $metadata['mime_type'] ?? null ) ? $metadata['mime_type'] : '';

        return new WP_REST_Response(
            array(
                'duration' => $duration,
                'size'     => $size,
                'mime'     => $mime,
            ),
            200
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    private function resolveUrl( string $url ) {
        $probed = AudioHeadProbe::probe( $url );

        // Fix 3: both null means the probe completely failed (bad scheme, network
        // error, or a wp_remote_head WP_Error). Return 502 so the editor knows the
        // probe failed and leaves the stored size/mime values intact rather than
        // coercing nulls to 0/'' and clobbering them.
        if ( null === $probed['size'] && null === $probed['mime'] ) {
            return new WP_Error(
                'sermonator_audio_probe_failed',
                __( 'Audio URL could not be probed.', 'sermonator' ),
                array( 'status' => 502 )
            );
        }

        return new WP_REST_Response(
            array(
                'duration' => '',
                'size'     => $probed['size'] ?? 0,
                'mime'     => $probed['mime'] ?? '',
            ),
            200
        );
    }
}
