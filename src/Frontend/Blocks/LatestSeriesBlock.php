<?php

declare(strict_types=1);

namespace Sermonator\Frontend\Blocks;

use Sermonator\Frontend\Renderer;
use Sermonator\Schema\Identifiers as ID;

/**
 * The "latest" sermon series rendered as a single image + title + description card (legacy
 * `[latest_series]`).
 *
 * PROVISIONAL semantics — "latest" is resolved as the {@see ID::TAX_SERIES} term of the
 * MOST-RECENTLY-PREACHED sermon: a 1-post {@see \WP_Query} on {@see ID::POST_TYPE_SERMON}
 * ordered by {@see ID::META_DATE} (`meta_value_num` DESC), optionally constrained to a
 * {@see ID::TAX_SERVICE_TYPE} via the `serviceType` attribute. This is NOT validated against
 * the (absent) Sermon Manager source, so the Bundle 1 `[latest_series]` shim keeps its
 * "needs review" notice for this tag.
 *
 * Image source: {@see ID::OPTION_TERM_IMAGES}, keyed STRICTLY by `term_taxonomy_id` (tt_id)
 * — matching {@see \Sermonator\Migration\ArtworkWriter}, NOT term_id.
 *
 * Resolution failures (no sermon, the sermon has no series term, etc.) → '' (empty-state);
 * the card's own image/title/description are passed already-resolved to the pure
 * {@see Renderer::latestSeries()}.
 */
final class LatestSeriesBlock extends AbstractBlock {
    public function name(): string {
        return 'sermonator/latest-series';
    }

    public function render( array $attributes, string $content, \WP_Block $block ): string {
        unset( $content, $block );

        $size            = isset( $attributes['size'] ) && '' !== (string) $attributes['size'] ? (string) $attributes['size'] : 'large';
        $showTitle       = ! isset( $attributes['showTitle'] ) || (bool) $attributes['showTitle'];
        $showDescription = ! isset( $attributes['showDescription'] ) || (bool) $attributes['showDescription'];
        $serviceType     = isset( $attributes['serviceType'] ) ? (string) $attributes['serviceType'] : '';

        $term = $this->latestSeriesTerm( $serviceType );
        if ( null === $term ) {
            return '';
        }

        // OPTION_TERM_IMAGES is keyed by term_taxonomy_id (tt_id), exactly as ArtworkWriter
        // writes it — never by term_id.
        $imageHtml = '';
        $images    = get_option( ID::OPTION_TERM_IMAGES, array() );
        if ( is_array( $images ) ) {
            $ttId  = (int) $term->term_taxonomy_id;
            $attId = isset( $images[ $ttId ] ) ? (int) $images[ $ttId ] : 0;
            if ( $attId > 0 ) {
                $resolved = wp_get_attachment_image( $attId, $size );
                if ( is_string( $resolved ) ) {
                    $imageHtml = $resolved;
                }
            }
        }

        $link = get_term_link( $term );

        return ( new Renderer() )->latestSeries(
            array(
                'name'        => (string) $term->name,
                'url'         => is_wp_error( $link ) ? '' : (string) $link,
                'imageHtml'   => $imageHtml,
                'description' => (string) $term->description,
            ),
            $showTitle,
            $showDescription
        );
    }

    /**
     * The series (TAX_SERIES) term of the most-recently-preached sermon, or null when there
     * is no such sermon / it carries no series term. PROVISIONAL "latest" = the single sermon
     * with the greatest META_DATE.
     */
    private function latestSeriesTerm( string $serviceType ): ?\WP_Term {
        $args = array(
            'post_type'              => ID::POST_TYPE_SERMON,
            'post_status'            => 'publish',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_key'               => ID::META_DATE,
            'orderby'                => 'meta_value_num',
            'order'                  => 'DESC',
        );

        if ( '' !== $serviceType ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => ID::TAX_SERVICE_TYPE,
                    'field'    => 'slug',
                    'terms'    => $serviceType,
                ),
            );
        }

        $query = new \WP_Query( $args );
        $ids   = array_map( 'intval', (array) $query->posts );
        if ( array() === $ids ) {
            return null;
        }

        $terms = get_the_terms( $ids[0], ID::TAX_SERIES );
        if ( ! is_array( $terms ) || array() === $terms ) {
            return null;
        }

        $term = $terms[0];

        return $term instanceof \WP_Term ? $term : null;
    }
}
