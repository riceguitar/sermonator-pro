<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

/** Renders the sermon meta list (scripture, preacher, series, date, taxonomies). */
final class SermonMetaBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/sermon-meta';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->renderablePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->meta( ( new TemplateData() )->sermon( $postId ) );
    }
}
