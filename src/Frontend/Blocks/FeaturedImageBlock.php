<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

/** Renders the sermon featured image. */
final class FeaturedImageBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/featured-image';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->renderablePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->featuredImage( ( new TemplateData() )->sermon( $postId ) );
    }
}
