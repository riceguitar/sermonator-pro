<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

/** Renders the sermon audio player (native <audio> + download + speed control). */
final class AudioPlayerBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/audio-player';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->renderablePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->audioPlayer( ( new TemplateData() )->sermon( $postId ) );
    }
}
