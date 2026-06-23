<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

/**
 * [sermonator_sermons] — the grid as a shortcode, for classic editors, page builders
 * (Elementor), and legacy embedding. Shares GridArgs + Renderer::grid with the block, so
 * the two cannot drift. Read-only.
 *
 * Attributes: count, columns, order, preacher, series, topic, book, service_type
 * (taxonomy attrs take comma-separated term slugs or ids).
 */
final class Shortcode {
    public const TAG = 'sermonator_sermons';

    public function hook(): void {
        add_shortcode( self::TAG, array( $this, 'render' ) );
    }

    /** @param array<string,mixed>|string $atts */
    public function render( $atts ): string {
        // Shortcodes do not trigger block-asset loading, so enqueue the stylesheet directly.
        if ( wp_style_is( Assets::STYLE_HANDLE, 'registered' ) ) {
            wp_enqueue_style( Assets::STYLE_HANDLE );
        }

        $atts = shortcode_atts(
            array(
                'count'        => 12,
                'columns'      => 3,
                'order'        => 'DESC',
                'preacher'     => '',
                'series'       => '',
                'topic'        => '',
                'book'         => '',
                'service_type' => '',
            ),
            is_array( $atts ) ? $atts : array(),
            self::TAG
        );

        // Normalise shortcode att names to the block attribute names GridArgs expects.
        $args   = GridArgs::fromAtts( array(
            'perPage'     => $atts['count'],
            'columns'     => $atts['columns'],
            'order'       => $atts['order'],
            'preacher'    => $atts['preacher'],
            'series'      => $atts['series'],
            'topic'       => $atts['topic'],
            'book'        => $atts['book'],
            'serviceType' => $atts['service_type'],
        ) );
        $result = ( new SermonQuery() )->run( $args );
        return ( new Renderer() )->grid( $result, array( 'columns' => $args['columns'] ) );
    }
}
