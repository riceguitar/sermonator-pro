<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\TemplateData;

/** Renders the pastor's sermon notes / study guide. */
final class NotesBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/notes';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $postId = $this->renderablePostId( $attributes, $block );
        if ( $postId <= 0 ) {
            return '';
        }
        return ( new Renderer() )->notes( ( new TemplateData() )->sermon( $postId ) );
    }
}
