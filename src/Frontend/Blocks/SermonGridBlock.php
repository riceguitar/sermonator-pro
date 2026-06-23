<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\GridArgs;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\SermonQuery;

/** A grid of sermons (newest preached first), optionally filtered by taxonomy. */
final class SermonGridBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/sermon-grid';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $args   = GridArgs::fromAtts( $attributes );
        $result = ( new SermonQuery() )->run( $args );
        return ( new Renderer() )->grid( $result, array( 'columns' => $args['columns'] ) );
    }
}
