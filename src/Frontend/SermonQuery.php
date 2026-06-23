<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * Read service that queries published sermons ordered by PREACHED date (the
 * `sermonator_date` meta, not post_date), with optional taxonomy filters and pagination,
 * and maps the results to {@see SermonView}s via {@see TemplateData}. The migration
 * guarantees every sermon carries `sermonator_date`, so ordering on that meta key does not
 * drop rows. Read-only.
 */
final class SermonQuery {
    /**
     * @param array{
     *   perPage?: int,
     *   page?: int,
     *   order?: string,
     *   taxonomies?: array<string,list<string|int>>
     * } $args
     */
    public function run( array $args = array() ): QueryResult {
        $perPage = isset( $args['perPage'] ) ? (int) $args['perPage'] : 10;
        $page    = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
        $order   = ( isset( $args['order'] ) && strtoupper( (string) $args['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

        $query = array(
            'post_type'              => ID::POST_TYPE_SERMON,
            'post_status'            => 'publish',
            'posts_per_page'         => $perPage,
            'paged'                  => $page,
            'meta_key'               => ID::META_DATE,
            'orderby'                => 'meta_value_num',
            'order'                  => $order,
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => false,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
        );

        $taxQuery = $this->buildTaxQuery( $args['taxonomies'] ?? array() );
        if ( $taxQuery !== array() ) {
            $query['tax_query'] = $taxQuery;
        }

        $wpQuery = new \WP_Query( $query );

        $data    = new TemplateData();
        $sermons = array();
        foreach ( $wpQuery->posts as $post ) {
            $sermons[] = $data->sermon( (int) $post->ID );
        }

        return new QueryResult(
            $sermons,
            (int) $wpQuery->found_posts,
            (int) $wpQuery->max_num_pages,
            $page
        );
    }

    /**
     * @param array<string,list<string|int>> $taxonomies
     * @return list<array<string,mixed>>
     */
    private function buildTaxQuery( array $taxonomies ): array {
        $clauses = array();
        foreach ( ID::sermonTaxonomies() as $taxonomy ) {
            if ( empty( $taxonomies[ $taxonomy ] ) ) {
                continue;
            }
            $terms      = array_values( (array) $taxonomies[ $taxonomy ] );
            $allNumeric = $terms === array_filter( $terms, static fn( $t ) => is_int( $t ) || ctype_digit( (string) $t ) );
            $clauses[]  = array(
                'taxonomy' => $taxonomy,
                'field'    => $allNumeric ? 'term_id' : 'slug',
                'terms'    => $terms,
            );
        }
        if ( count( $clauses ) > 1 ) {
            $clauses['relation'] = 'AND';
        }
        return $clauses;
    }
}
