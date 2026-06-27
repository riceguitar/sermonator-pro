<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Compat\LegacyShortcodes;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\Shortcode;
use Sermonator\Schema\Identifiers as ID;

/**
 * A grid of taxonomy-term images — series artwork being the canonical case (legacy
 * `[sermon_images]`). For each term that carries a configured image it resolves the core
 * attachment HTML + term archive link + description and delegates to the pure
 * {@see Renderer::termImageGrid()}.
 *
 * Image source: {@see ID::OPTION_TERM_IMAGES}, keyed STRICTLY by `term_taxonomy_id`
 * (tt_id) — matching {@see \Sermonator\Migration\ArtworkWriter}, which writes that option
 * keyed by the NEW tt_id. Keying by term_id instead would silently mismatch on any site
 * whose terms do not share id==tt_id.
 *
 * Safe fallback (#1 data preservation, never a blank grid): when the option is absent/empty
 * OR resolves to ZERO images (no matching terms, missing attachments, etc.) the block emits
 * the standard safe sermon list plus the editor-only "needs review" notice instead of an
 * empty grid — the same fail-visible default the Bundle 1 `[sermon_images]` shim uses.
 */
final class SermonImagesBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/sermon-images';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        unset( $content, $block );

        $taxonomy = isset( $attributes['taxonomy'] ) ? (string) $attributes['taxonomy'] : ID::TAX_SERIES;
        if ( ! in_array( $taxonomy, ID::sermonTaxonomies(), true ) ) {
            $taxonomy = ID::TAX_SERIES;
        }

        $columns   = isset( $attributes['columns'] ) ? (int) $attributes['columns'] : 3;
        $size      = isset( $attributes['size'] ) && '' !== (string) $attributes['size'] ? (string) $attributes['size'] : 'medium';
        $order     = isset( $attributes['order'] ) ? (string) $attributes['order'] : 'ASC';
        $orderby   = isset( $attributes['orderby'] ) ? (string) $attributes['orderby'] : 'name';
        $hideEmpty = isset( $attributes['hideEmpty'] ) && (bool) $attributes['hideEmpty'];

        // OPTION_TERM_IMAGES is keyed by term_taxonomy_id (tt_id), exactly as
        // ArtworkWriter writes it. Absent/empty → safe fallback, never a blank grid.
        $images = get_option( ID::OPTION_TERM_IMAGES, array() );
        if ( ! is_array( $images ) || array() === $images ) {
            return $this->safeFallback();
        }

        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => $hideEmpty,
            'orderby'    => $orderby,
            'order'      => $order,
        ) );
        if ( ! is_array( $terms ) ) {
            return $this->safeFallback();
        }

        $items = array();
        foreach ( $terms as $term ) {
            $ttId = (int) $term->term_taxonomy_id;
            if ( ! isset( $images[ $ttId ] ) ) {
                continue;
            }
            $attId = (int) $images[ $ttId ];
            if ( $attId <= 0 ) {
                continue;
            }

            $imageHtml = wp_get_attachment_image( $attId, $size );
            if ( ! is_string( $imageHtml ) || '' === $imageHtml ) {
                continue;
            }

            $link      = get_term_link( $term );
            $items[]   = array(
                'name'        => (string) $term->name,
                'url'         => is_wp_error( $link ) ? '' : (string) $link,
                'imageHtml'   => $imageHtml,
                'description' => (string) $term->description,
            );
        }

        // Zero resolved images (no matching tt_ids, all attachments missing): safe
        // fallback, never an empty grid.
        if ( array() === $items ) {
            return $this->safeFallback();
        }

        return ( new Renderer() )->termImageGrid( $items, '', $columns );
    }

    /**
     * The fail-visible default when no term images resolve: the standard safe sermon list
     * (same primitive the legacy shim uses) plus the editor-only review notice — never a
     * blank grid.
     */
    private function safeFallback(): string {
        $list = ( new Shortcode() )->render( array() );

        return LegacyShortcodes::needsReviewNotice() . $list;
    }
}
