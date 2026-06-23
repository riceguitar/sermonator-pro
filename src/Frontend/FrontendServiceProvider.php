<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Frontend\Blocks\SermonMetaBlock;
use Sermonator\Frontend\Blocks\AudioPlayerBlock;
use Sermonator\Frontend\Blocks\VideoBlock;

/**
 * Wires the read-only front-end display layer: dynamic blocks, the block template (FSE),
 * the classic-theme template fallback, and conditional assets. Instantiated by Plugin::boot
 * on front-end + feed/REST requests (not wp-admin screens, not WP-CLI rendering).
 */
final class FrontendServiceProvider {
    private Assets $assets;

    public function __construct() {
        $this->assets = new Assets();
    }

    public function hook(): void {
        add_action( 'init', array( $this, 'onInit' ) );
        ( new ClassicTemplates() )->hook();
        $this->assets->hook();
    }

    public function onInit(): void {
        // Register asset handles first so block.json style/viewScript references resolve.
        $this->assets->register();

        ( new SermonMetaBlock() )->register();
        ( new AudioPlayerBlock() )->register();
        ( new VideoBlock() )->register();

        ( new BlockTemplates() )->register();
    }
}
