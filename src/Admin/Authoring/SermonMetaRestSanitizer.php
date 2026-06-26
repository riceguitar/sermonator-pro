<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Schema\Identifiers;
use WP_Post;
use WP_REST_Request;

/**
 * REST-only sanitizer for the editable sermon meta keys.
 *
 * This is deliberately separate from the register_post_meta() sanitize_callback so the migration
 * engine's direct add_post_meta/update_post_meta calls remain byte-preserving. The block editor
 * writes through the REST API, so sanitizing here is sufficient.
 */
final class SermonMetaRestSanitizer {
    public function hook(): void {
        add_filter(
            'rest_pre_insert_' . Identifiers::POST_TYPE_SERMON,
            array( $this, 'sanitize' ),
            10,
            2
        );
    }

    /**
     * @param object          $prepared_post WP_Post or stdClass prepared by the controller.
     * @param WP_REST_Request $request
     * @return object
     */
    public function sanitize( $prepared_post, WP_REST_Request $request ): object {
        if ( ! MigrationGuard::editingAllowed() ) {
            // TODO(parity-followup): returning $prepared_post silently here (HTTP 200)
            // means the block editor cannot distinguish "saved" from "blocked by
            // migration". This should instead return a WP_Error (e.g. via
            // rest_pre_insert_ returning a WP_Error directly) so the editor surfaces
            // the migration-active state and does not silently discard the write.
            // Needs a migration-active edge test.
            return $prepared_post;
        }

        $meta = $request['meta'] ?? array();
        if ( ! is_array( $meta ) ) {
            return $prepared_post;
        }

        foreach ( $meta as $key => $value ) {
            if ( ! is_string( $key ) ) {
                continue;
            }
            $meta[ $key ] = $this->sanitizeValue( $key, $value );
        }

        $request->set_param( 'meta', $meta );
        return $prepared_post;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeValue( string $key, $value ) {
        return SermonMetaSanitizer::sanitize( $key, $value );
    }
}
