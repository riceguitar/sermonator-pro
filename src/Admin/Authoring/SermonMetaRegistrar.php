<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Schema\Identifiers;

/**
 * Registers the native-Gutenberg write contract for every editable sermon field.
 *
 * Each field is registered via register_post_meta() with:
 *   - single => true
 *   - a typed show_in_rest schema
 *   - auth_callback => editingAllowed() AND current_user_can('edit_post', $id)
 *
 * Sanitization is intentionally NOT attached here as a global sanitize_callback: the migration
 * engine writes raw legacy values (non-numeric dates, arbitrary text, multi-value notes) directly
 * to the same keys, and a global sanitizer would corrupt them. The AuthoringServiceProvider adds
 * REST-only sanitization via rest_pre_insert so the block editor's meta writes are still hardened.
 *
 * The underscore-protected size/duration keys are explicitly exposed to REST so the editor can
 * persist them; do NOT copy that pattern for sensitive keys. All keys use the existing
 * Schema\Identifiers constants and storage formats so the front end, feed, and migration keep
 * working unchanged.
 */
final class SermonMetaRegistrar {
    public function register(): void {
        $this->registerInt( Identifiers::META_DATE, '0' );
        $this->registerFlag( Identifiers::META_DATE_AUTO );
        $this->registerString( Identifiers::META_BIBLE_PASSAGE );
        $this->registerString( Identifiers::META_AUDIO );
        $this->registerInt( Identifiers::META_AUDIO_ID, '0' );
        $this->registerProtectedString( Identifiers::META_AUDIO_DURATION );
        $this->registerProtectedInt( Identifiers::META_AUDIO_SIZE, '0' );
        $this->registerString( Identifiers::META_VIDEO_URL );
        $this->registerString( Identifiers::META_VIDEO_EMBED );
        $this->registerString( Identifiers::META_NOTES );
        $this->registerString( Identifiers::META_BULLETIN );
    }

    private function authCallback(): callable {
        return static function ( $allowed, string $meta_key, int $object_id ): bool {
            return MigrationGuard::editingAllowed() && current_user_can( 'edit_post', $object_id );
        };
    }

    private function registerInt( string $key, string $default ): void {
        register_post_meta(
            Identifiers::POST_TYPE_SERMON,
            $key,
            array(
                'single'            => true,
                'default'           => $default,
                'show_in_rest'      => array(
                    'schema' => array( 'type' => 'integer' ),
                ),
                'auth_callback'     => $this->authCallback(),
            )
        );
    }

    private function registerProtectedInt( string $key, string $default ): void {
        register_post_meta(
            Identifiers::POST_TYPE_SERMON,
            $key,
            array(
                'single'            => true,
                'default'           => $default,
                'show_in_rest'      => array(
                    'schema' => array( 'type' => 'integer' ),
                ),
                'auth_callback'     => $this->authCallback(),
            )
        );
    }

    private function registerFlag( string $key ): void {
        register_post_meta(
            Identifiers::POST_TYPE_SERMON,
            $key,
            array(
                'single'            => true,
                'default'           => '0',
                'show_in_rest'      => array(
                    'schema' => array( 'type' => 'integer' ),
                ),
                'auth_callback'     => $this->authCallback(),
            )
        );
    }

    private function registerString( string $key ): void {
        register_post_meta(
            Identifiers::POST_TYPE_SERMON,
            $key,
            array(
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'auth_callback'     => $this->authCallback(),
            )
        );
    }

    private function registerProtectedString( string $key ): void {
        register_post_meta(
            Identifiers::POST_TYPE_SERMON,
            $key,
                array(
                'single'            => true,
                'default'           => '',
                'show_in_rest'      => true,
                'auth_callback'     => $this->authCallback(),
            )
        );
    }
}
