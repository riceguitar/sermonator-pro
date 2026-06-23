<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * Maps the (identical) block attributes and shortcode atts to {@see SermonQuery} args, so
 * the grid block and the [sermonator_sermons] shortcode share one source of truth and
 * cannot drift. Each taxonomy field accepts a comma-separated list of term slugs or ids.
 */
final class GridArgs {
    /**
     * @param array<string,mixed> $atts
     * @return array{perPage:int,page:int,columns:int,order:string,taxonomies:array<string,list<string>>}
     */
    public static function fromAtts( array $atts ): array {
        $map = array(
            'preacher'    => ID::TAX_PREACHER,
            'series'      => ID::TAX_SERIES,
            'topic'       => ID::TAX_TOPIC,
            'book'        => ID::TAX_BOOK,
            'serviceType' => ID::TAX_SERVICE_TYPE,
        );

        $taxonomies = array();
        foreach ( $map as $att => $taxonomy ) {
            $raw = isset( $atts[ $att ] ) ? trim( (string) $atts[ $att ] ) : '';
            if ( $raw === '' ) {
                continue;
            }
            $terms = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn( $t ) => $t !== '' ) );
            if ( $terms !== array() ) {
                $taxonomies[ $taxonomy ] = $terms;
            }
        }

        return array(
            // Clamp to a sane ceiling so an embedded grid/shortcode cannot hydrate an
            // unbounded number of posts (count="999999" DoS).
            'perPage'    => isset( $atts['perPage'] ) ? max( 1, min( 100, (int) $atts['perPage'] ) ) : 12,
            'page'       => self::currentPage(),
            'columns'    => isset( $atts['columns'] ) ? max( 1, min( 6, (int) $atts['columns'] ) ) : 3,
            'order'      => ( isset( $atts['order'] ) && strtoupper( (string) $atts['order'] ) === 'ASC' ) ? 'ASC' : 'DESC',
            'taxonomies' => $taxonomies,
        );
    }

    private static function currentPage(): int {
        $paged = (int) get_query_var( 'paged' );
        if ( $paged < 1 ) {
            $paged = (int) get_query_var( 'page' );
        }
        return max( 1, $paged );
    }
}
