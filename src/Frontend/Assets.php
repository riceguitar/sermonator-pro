<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * Registers and conditionally enqueues the minimal front-end CSS + player JS. Handles are
 * registered on init so block.json `style`/`viewScript` references always resolve; they are
 * enqueued only on sermon singles, the sermon archive, and sermon taxonomy archives (block
 * placements elsewhere enqueue via block.json automatically).
 */
final class Assets {
    public const STYLE_HANDLE  = 'sermonator-frontend';
    public const SCRIPT_HANDLE = 'sermonator-audio-player';

    public function hook(): void {
        // Handles are registered on init by FrontendServiceProvider (before block
        // registration so block.json style/viewScript references resolve); here we only
        // wire the conditional front-end enqueue. maybeEnqueue() self-heals if needed.
        add_action( 'wp_enqueue_scripts', array( $this, 'maybeEnqueue' ) );
    }

    public function register(): void {
        wp_register_style(
            self::STYLE_HANDLE,
            SERMONATOR_PLUGIN_URL . 'assets/frontend.css',
            array(),
            SERMONATOR_VERSION
        );
        wp_register_script(
            self::SCRIPT_HANDLE,
            SERMONATOR_PLUGIN_URL . 'assets/audio-player.js',
            array(),
            SERMONATOR_VERSION,
            true
        );
    }

    public function maybeEnqueue(): void {
        if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
            $this->register();
        }
        if ( $this->isSermonContext() ) {
            wp_enqueue_style( self::STYLE_HANDLE );
            wp_enqueue_script( self::SCRIPT_HANDLE );
        }
    }

    private function isSermonContext(): bool {
        if ( is_singular( ID::POST_TYPE_SERMON ) || is_post_type_archive( ID::POST_TYPE_SERMON ) ) {
            return true;
        }
        foreach ( ID::sermonTaxonomies() as $taxonomy ) {
            if ( is_tax( $taxonomy ) ) {
                return true;
            }
        }
        return false;
    }
}
