<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Schema\Identifiers as ID;

/** A list of term links for one sermon taxonomy. */
final class TaxonomyFilterBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/taxonomy-filter';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        $taxonomy = isset( $attributes['taxonomy'] ) ? (string) $attributes['taxonomy'] : ID::TAX_SERIES;
        if ( ! in_array( $taxonomy, ID::sermonTaxonomies(), true ) ) {
            return '';
        }
        $hideEmpty = ! isset( $attributes['hideEmpty'] ) || (bool) $attributes['hideEmpty'];

        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => $hideEmpty,
        ) );
        if ( ! is_array( $terms ) ) {
            return '';
        }

        $resolved = array();
        foreach ( $terms as $term ) {
            $link       = get_term_link( $term );
            $resolved[] = array(
                'name'  => (string) $term->name,
                'url'   => is_wp_error( $link ) ? '' : (string) $link,
                'count' => (int) $term->count,
            );
        }

        $taxObject = get_taxonomy( $taxonomy );
        $label     = ( $taxObject && isset( $taxObject->labels->name ) ) ? (string) $taxObject->labels->name : '';

        return ( new Renderer() )->taxonomyLinks( $resolved, $label );
    }
}
