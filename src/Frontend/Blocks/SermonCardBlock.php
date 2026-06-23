<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

/** A single sermon card, for use inside a query loop (archive/taxonomy templates). */
final class SermonCardBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/sermon-card';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->renderablePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->card( ( new TemplateData() )->sermon( $postId ) );
    }
}
