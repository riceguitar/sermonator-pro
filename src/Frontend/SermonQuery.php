<?php

declare(strict_types=1);

namespace Sermonator\Frontend;

use Sermonator\Schema\Identifiers as ID;

/**
 * Read service that queries published sermons and maps the results to {@see SermonView}s via
 * {@see TemplateData}. Read-only.
 *
 * Date semantics are governed by an explicit {@see DateScope} mode (default
 * {@see DateScope::INCLUSIVE}, the NATIVE grid behavior):
 *
 *  - INCLUSIVE (native default) — LEFT-JOIN-style `OR(EXISTS, NOT EXISTS)` on
 *    {@see ID::META_DATE}: dateless sermons are still listed (sorted last) and future-dated
 *    sermons are included. **This branch is pinned byte-for-byte to the original query and is
 *    NEVER mutated or forked by the new modes** — the legacy/preached compat work lives in the
 *    parallel PREACHED branch only.
 *  - PREACHED — reproduces legacy Sermon Manager `display_sermons()`: an EXISTS + `META_DATE <=
 *    now()` NUMERIC clause that drops BOTH future AND dateless sermons. The ONLY branch on which
 *    the year/month/before/after date-range bounds are applied (gated; force drop-dateless).
 *  - NONE — no date meta_query: dateless included, no future filter (used by non-date orderings).
 *
 * `META_DATE` is a SIGNED Unix timestamp (pre-1970 sermons are negative), so every date clause
 * uses `type=NUMERIC` and every numeric bound is validated with `ctype_digit(ltrim($v,'-'))`.
 */
final class SermonQuery {
    /**
     * @param int|null $now Override for "now" (the PREACHED future-cap and tests). Defaults to time().
     */
    public function __construct( private readonly ?int $now = null ) {}

    /**
     * @param array{
     *   perPage?: int,
     *   page?: int,
     *   order?: string,
     *   orderby?: string,
     *   dateScope?: DateScope|string,
     *   dateRange?: array{min?: int|string, max?: int|string, equals?: int|string, capFuture?: bool},
     *   postIn?: list<int|string>,
     *   postNotIn?: list<int|string>,
     *   taxonomies?: array<string,list<string|int>>
     * } $args
     */
    public function run( array $args = array() ): QueryResult {
        $page    = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
        $wpQuery = new \WP_Query( $this->buildQueryArgs( $args ) );

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
     * Pure builder for the {@see \WP_Query} args. Extracted so the date/order/range logic can be
     * unit-tested without WordPress, and so the INCLUSIVE default can be pinned byte-for-byte.
     *
     * @param array<string,mixed> $args See {@see self::run()}.
     * @return array<string,mixed>
     */
    public function buildQueryArgs( array $args = array() ): array {
        $perPage   = isset( $args['perPage'] ) ? (int) $args['perPage'] : 10;
        $page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
        $order     = ( isset( $args['order'] ) && strtoupper( (string) $args['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';
        $rawScope  = $args['dateScope'] ?? null;
        $dateScope = $rawScope instanceof DateScope ? $rawScope : DateScope::fromString( $rawScope === null ? null : (string) $rawScope );
        $orderbyTok = $this->resolveOrderby( isset( $args['orderby'] ) ? (string) $args['orderby'] : '' );

        $metaQuery = $this->buildDateMetaQuery( $dateScope, is_array( $args['dateRange'] ?? null ) ? $args['dateRange'] : array() );
        $orderby   = $this->buildOrderby( $orderbyTok, $order, $metaQuery );

        // Build in the original key order so DateScope::INCLUSIVE + default orderby is byte-for-byte
        // identical to the pre-Bundle-2 native query (the no-regression pin).
        $query = array(
            'post_type'      => ID::POST_TYPE_SERMON,
            'post_status'    => 'publish',
            'posts_per_page' => $perPage,
            'paged'          => $page,
        );
        if ( $metaQuery !== array() ) {
            $query['meta_query'] = $metaQuery;
        }
        $query['orderby']                = $orderby;
        $query['ignore_sticky_posts']    = true;
        $query['no_found_rows']          = false;
        $query['update_post_term_cache'] = true;
        $query['update_post_meta_cache'] = true;

        $postIn = $this->intList( $args['postIn'] ?? array() );
        if ( $postIn !== array() ) {
            $query['post__in'] = $postIn;
        }
        $postNotIn = $this->intList( $args['postNotIn'] ?? array() );
        if ( $postNotIn !== array() ) {
            $query['post__not_in'] = $postNotIn;
        }

        $taxQuery = $this->buildTaxQuery( $args['taxonomies'] ?? array() );
        if ( $taxQuery !== array() ) {
            $query['tax_query'] = $taxQuery;
        }

        return $query;
    }

    /**
     * The date meta_query for a given mode. The INCLUSIVE arm is the verbatim native branch and
     * MUST stay byte-for-byte stable. Date-range bounds are applied ONLY on the PREACHED arm.
     *
     * @param array{min?: int|string, max?: int|string, equals?: int|string, capFuture?: bool} $range
     * @return array<string,mixed>
     */
    private function buildDateMetaQuery( DateScope $scope, array $range ): array {
        switch ( $scope ) {
            case DateScope::INCLUSIVE:
                // NATIVE branch — byte-for-byte. LEFT-JOIN: dateless still listed (sorted last),
                // future included. Date-range bounds are intentionally NOT applied here.
                return array(
                    'relation' => 'OR',
                    'preached' => array( 'key' => ID::META_DATE, 'type' => 'NUMERIC', 'compare' => 'EXISTS' ),
                    'undated'  => array( 'key' => ID::META_DATE, 'compare' => 'NOT EXISTS' ),
                );

            case DateScope::NONE:
                return array();

            case DateScope::PREACHED:
                // EXISTS (drops dateless + enables meta ordering) + the legacy `<= now()` future-cap.
                $clauses = array(
                    'preached' => array( 'key' => ID::META_DATE, 'type' => 'NUMERIC', 'compare' => 'EXISTS' ),
                );

                // The future-cap is the defining preached behavior; the mapper turns it OFF
                // (capFuture=false) for year/month, which SM applies WITHOUT the `<= now` clause.
                if ( ( $range['capFuture'] ?? true ) ) {
                    $clauses['within'] = array(
                        'key'     => ID::META_DATE,
                        'value'   => $this->now(),
                        'type'    => 'NUMERIC',
                        'compare' => '<=',
                    );
                }

                $min = $this->numeric( $range['min'] ?? null );
                $max = $this->numeric( $range['max'] ?? null );
                $eq  = $this->numeric( $range['equals'] ?? null );

                if ( $min !== null && $max !== null ) {
                    // year/month -> a single BETWEEN clause (SM display_sermons() :917/:930).
                    $clauses['between'] = array(
                        'key'     => ID::META_DATE,
                        'value'   => array( $min, $max ),
                        'type'    => 'NUMERIC',
                        'compare' => 'BETWEEN',
                    );
                } elseif ( $min !== null ) {
                    $clauses['min'] = array( 'key' => ID::META_DATE, 'value' => $min, 'type' => 'NUMERIC', 'compare' => '>=' );
                } elseif ( $max !== null ) {
                    // before -> upper bound (SM :952).
                    $clauses['max'] = array( 'key' => ID::META_DATE, 'value' => $max, 'type' => 'NUMERIC', 'compare' => '<=' );
                }

                if ( $eq !== null ) {
                    // after -> SM's invalid `=>` normalized to `=` (an exact-day match, SM :962).
                    $clauses['equals'] = array( 'key' => ID::META_DATE, 'value' => $eq, 'type' => 'NUMERIC', 'compare' => '=' );
                }

                if ( count( $clauses ) > 1 ) {
                    $clauses['relation'] = 'AND';
                }
                return $clauses;
        }

        return array(); // unreachable; the enum is exhaustive.
    }

    /**
     * Map a resolved orderby token to a {@see \WP_Query} `orderby` value. `META_DATE` ordering
     * reuses the named `preached` meta_query clause (present on INCLUSIVE/PREACHED) so the
     * post_date tiebreaker keeps dateless rows (INCLUSIVE) ordered; it falls back to post_date
     * when no meta_query exists (the degenerate NONE + date_preached pairing).
     *
     * @param array<string,mixed> $metaQuery
     * @return array<string,string>|string
     */
    private function buildOrderby( string $token, string $order, array $metaQuery ) {
        switch ( $token ) {
            case 'preached':
                return isset( $metaQuery['preached'] )
                    ? array( 'preached' => $order, 'date' => 'DESC' )
                    : array( 'date' => $order );
            case 'published':
                return array( 'date' => $order );
            case 'id':
                return array( 'ID' => $order );
            case 'title':
                return array( 'title' => $order );
            case 'name':
                return array( 'name' => $order );
            case 'comment_count':
                return array( 'comment_count' => $order );
            case 'rand':
                return 'rand';
            case 'none':
                return 'none';
        }
        return array( 'preached' => $order, 'date' => 'DESC' );
    }

    /**
     * Normalize a legacy/native orderby string to an internal token. Mirrors the SM
     * `display_sermons()` whitelist (:852) including its invalid-value default of `date_preached`.
     * NOTE: a bare `date` resolves to the PUBLISHED (post_date) token here; SM's option-driven
     * `date`->preached resolution is performed UPSTREAM by the LegacyAttributeMapper, which passes
     * an already-resolved token.
     */
    private function resolveOrderby( string $orderby ): string {
        switch ( strtolower( trim( $orderby ) ) ) {
            case 'preached':
            case 'date_preached':
            case '':
                return 'preached';
            case 'date':
            case 'published':
            case 'date_published':
                return 'published';
            case 'id':
                return 'id';
            case 'title':
                return 'title';
            case 'name':
                return 'name';
            case 'rand':
                return 'rand';
            case 'none':
                return 'none';
            case 'comment_count':
                return 'comment_count';
            default:
                return 'preached';
        }
    }

    /** Validate a SIGNED-ts-safe numeric bound; non-numeric -> null (dropped, never rendered wrong). */
    private function numeric( mixed $value ): ?int {
        if ( $value === null || $value === '' ) {
            return null;
        }
        if ( is_int( $value ) ) {
            return $value;
        }
        $string = (string) $value;
        return ctype_digit( ltrim( $string, '-' ) ) ? (int) $string : null;
    }

    /**
     * @param mixed $list
     * @return list<int>
     */
    private function intList( $list ): array {
        if ( ! is_array( $list ) ) {
            return array();
        }
        $out = array();
        foreach ( $list as $value ) {
            if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( ltrim( $value, '-' ) ) ) ) {
                $id = (int) $value;
                if ( $id > 0 ) {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }

    private function now(): int {
        return $this->now ?? time();
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
