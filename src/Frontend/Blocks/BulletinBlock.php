<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

/** Renders a download link for the sermon bulletin PDF. */
final class BulletinBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/bulletin';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->renderablePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->bulletin( ( new TemplateData() )->sermon( $postId ) );
    }
}
