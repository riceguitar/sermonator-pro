<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

/** Renders the sermon video (stored embed code, or a link to a video URL). */
final class VideoBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/video';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->resolvePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->video( ( new TemplateData() )->sermon( $postId ) );
    }
}
