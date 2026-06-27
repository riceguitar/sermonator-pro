<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Sermonator\Frontend\DateScope;
use Sermonator\Frontend\SermonQuery;
use Sermonator\Schema\Identifiers as ID;

/**
 * Pure unit coverage for {@see SermonQuery::buildQueryArgs()} — the Bundle 2 T2 query engine:
 * the three {@see DateScope} modes, orderby resolution, NUMERIC (signed-ts-safe) date-range
 * bounds, and post__in/post__not_in passthrough.
 *
 * The load-bearing test is {@see self::test_inclusive_default_is_byte_for_byte_native()}: it pins
 * the native grid query so the new modes provably never mutate or fork the inclusive branch.
 */
final class SermonQueryArgsTest extends TestCase {
    /** A fixed clock so the PREACHED `<= now()` clause is deterministic. */
    private const NOW = 1_700_000_000;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function query(): SermonQuery {
        return new SermonQuery( self::NOW );
    }

    /** The exact native query as it existed before Bundle 2 — the no-regression pin. */
    private function nativeExpected( int $perPage = 10, int $page = 1, string $order = 'DESC' ): array {
        return array(
            'post_type'      => ID::POST_TYPE_SERMON,
            'post_status'    => 'publish',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'meta_query'     => array(
                'relation' => 'OR',
                'preached' => array( 'key' => ID::META_DATE, 'type' => 'NUMERIC', 'compare' => 'EXISTS' ),
                'undated'  => array( 'key' => ID::META_DATE, 'compare' => 'NOT EXISTS' ),
            ),
            'orderby'                => array( 'preached' => $order, 'date' => 'DESC' ),
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => false,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
        );
    }

    public function test_inclusive_default_is_byte_for_byte_native(): void {
        // Both an empty arg set AND an explicit dateScope=inclusive must equal the original query,
        // key order included (=== compares arrays by key+order+value).
        $this->assertSame( $this->nativeExpected(), $this->query()->buildQueryArgs() );
        $this->assertSame( $this->nativeExpected(), $this->query()->buildQueryArgs( array( 'dateScope' => 'inclusive' ) ) );
        $this->assertSame(
            $this->nativeExpected(),
            $this->query()->buildQueryArgs( array( 'dateScope' => DateScope::INCLUSIVE ) )
        );
    }

    public function test_inclusive_carries_native_args_through(): void {
        $args = $this->query()->buildQueryArgs( array( 'perPage' => 24, 'page' => 3, 'order' => 'asc' ) );
        $this->assertSame( $this->nativeExpected( 24, 3, 'ASC' ), $args );
    }

    public function test_unknown_dateScope_falls_back_to_inclusive(): void {
        $this->assertSame( $this->nativeExpected(), $this->query()->buildQueryArgs( array( 'dateScope' => 'garbage' ) ) );
    }

    public function test_inclusive_ignores_date_range_bounds(): void {
        // Date-range bounds are gated on PREACHED; the native branch must be untouched by them.
        $args = $this->query()->buildQueryArgs( array(
            'dateScope' => 'inclusive',
            'dateRange' => array( 'min' => 0, 'max' => self::NOW ),
        ) );
        $this->assertSame( $this->nativeExpected(), $args );
    }

    public function test_preached_drops_future_and_dateless(): void {
        $args  = $this->query()->buildQueryArgs( array( 'dateScope' => 'preached' ) );
        $metaQ = $args['meta_query'];

        // EXISTS drops dateless; `<= now` drops future. No NOT-EXISTS arm (that would re-admit dateless).
        $this->assertSame( 'AND', $metaQ['relation'] );
        $this->assertSame(
            array( 'key' => ID::META_DATE, 'type' => 'NUMERIC', 'compare' => 'EXISTS' ),
            $metaQ['preached']
        );
        $this->assertSame(
            array( 'key' => ID::META_DATE, 'value' => self::NOW, 'type' => 'NUMERIC', 'compare' => '<=' ),
            $metaQ['within']
        );
        $this->assertArrayNotHasKey( 'undated', $metaQ );
        // Ordering still references the named preached clause (with the post_date tiebreaker).
        $this->assertSame( array( 'preached' => 'DESC', 'date' => 'DESC' ), $args['orderby'] );
    }

    public function test_none_has_no_date_meta_query(): void {
        $args = $this->query()->buildQueryArgs( array( 'dateScope' => 'none' ) );
        $this->assertArrayNotHasKey( 'meta_query', $args );
        // With no meta_query, preached ordering falls back to post_date (dateless still included).
        $this->assertSame( array( 'date' => 'DESC' ), $args['orderby'] );
    }

    /**
     * @dataProvider orderbyProvider
     * @param array<string,string>|string $expected
     */
    public function test_orderby_resolution( string $orderby, $expected ): void {
        $args = $this->query()->buildQueryArgs( array( 'dateScope' => 'none', 'orderby' => $orderby, 'order' => 'ASC' ) );
        $this->assertSame( $expected, $args['orderby'] );
    }

    /** @return iterable<string,array{0:string,1:array<string,string>|string}> */
    public static function orderbyProvider(): iterable {
        yield 'published -> post_date' => array( 'published', array( 'date' => 'ASC' ) );
        yield 'date_published -> post_date' => array( 'date_published', array( 'date' => 'ASC' ) );
        yield 'date -> post_date (mapper resolves option upstream)' => array( 'date', array( 'date' => 'ASC' ) );
        yield 'id -> ID' => array( 'id', array( 'ID' => 'ASC' ) );
        yield 'title' => array( 'title', array( 'title' => 'ASC' ) );
        yield 'name' => array( 'name', array( 'name' => 'ASC' ) );
        yield 'comment_count' => array( 'comment_count', array( 'comment_count' => 'ASC' ) );
        yield 'rand (string, order ignored)' => array( 'rand', 'rand' );
        yield 'none (string)' => array( 'none', 'none' );
        yield 'invalid -> SM default date_preached' => array( 'bogus', array( 'date' => 'ASC' ) );
    }

    public function test_orderby_preached_on_preached_scope_uses_named_clause(): void {
        $args = $this->query()->buildQueryArgs( array( 'dateScope' => 'preached', 'orderby' => 'date_preached' ) );
        $this->assertSame( array( 'preached' => 'DESC', 'date' => 'DESC' ), $args['orderby'] );
    }

    public function test_year_month_emit_between_only_when_cap_disabled(): void {
        // The mapper turns the future-cap OFF for year/month (SM resets the meta_query, dropping
        // the `<= now` clause) and passes a min/max pair -> a single BETWEEN clause.
        $args  = $this->query()->buildQueryArgs( array(
            'dateScope' => 'preached',
            'dateRange' => array( 'min' => 1_577_836_800, 'max' => 1_609_459_199, 'capFuture' => false ),
        ) );
        $metaQ = $args['meta_query'];

        $this->assertArrayNotHasKey( 'within', $metaQ, 'year/month drops the `<= now` future-cap.' );
        $this->assertSame(
            array(
                'key'     => ID::META_DATE,
                'value'   => array( 1_577_836_800, 1_609_459_199 ),
                'type'    => 'NUMERIC',
                'compare' => 'BETWEEN',
            ),
            $metaQ['between']
        );
        $this->assertSame( 'AND', $metaQ['relation'] );
    }

    public function test_before_is_upper_bound_anded_with_future_cap(): void {
        // `before` keeps the future-cap (SM appends, does not reset) -> EXISTS + `<= now` + `<= before`.
        $args  = $this->query()->buildQueryArgs( array(
            'dateScope' => 'preached',
            'dateRange' => array( 'max' => 1_609_459_199 ),
        ) );
        $metaQ = $args['meta_query'];

        $this->assertArrayHasKey( 'within', $metaQ );
        $this->assertSame(
            array( 'key' => ID::META_DATE, 'value' => 1_609_459_199, 'type' => 'NUMERIC', 'compare' => '<=' ),
            $metaQ['max']
        );
    }

    public function test_after_is_exact_equality_bug_for_bug(): void {
        // SM's invalid `=>` operator normalizes to `=` (an exact-day match), gated on preached.
        $args  = $this->query()->buildQueryArgs( array(
            'dateScope' => 'preached',
            'dateRange' => array( 'equals' => 1_600_000_000 ),
        ) );
        $metaQ = $args['meta_query'];

        $this->assertSame(
            array( 'key' => ID::META_DATE, 'value' => 1_600_000_000, 'type' => 'NUMERIC', 'compare' => '=' ),
            $metaQ['equals']
        );
    }

    public function test_negative_pre_1970_bounds_are_signed_ts_safe(): void {
        // Pre-1970 sermons carry NEGATIVE timestamps; the bound validator must accept them.
        $args  = $this->query()->buildQueryArgs( array(
            'dateScope' => 'preached',
            'dateRange' => array( 'min' => '-631152000', 'max' => '-315619200', 'capFuture' => false ),
        ) );
        $metaQ = $args['meta_query'];

        $this->assertSame( array( -631152000, -315619200 ), $metaQ['between']['value'] );
    }

    public function test_non_numeric_bounds_are_dropped_not_rendered_wrong(): void {
        // A non-numeric bound must never be silently coerced to 0 (would render a wrong set).
        $args  = $this->query()->buildQueryArgs( array(
            'dateScope' => 'preached',
            'dateRange' => array( 'max' => 'not-a-number' ),
        ) );
        $metaQ = $args['meta_query'];

        $this->assertArrayNotHasKey( 'max', $metaQ );
        // Only the base preached clauses survive.
        $this->assertSame( array( 'preached', 'within', 'relation' ), array_keys( $metaQ ) );
    }

    public function test_date_range_ignored_off_the_preached_path(): void {
        foreach ( array( 'none', 'inclusive' ) as $scope ) {
            $args = $this->query()->buildQueryArgs( array(
                'dateScope' => $scope,
                'dateRange' => array( 'min' => 1, 'max' => 2 ),
            ) );
            $meta = $args['meta_query'] ?? array();
            $this->assertArrayNotHasKey( 'between', $meta, "dateRange must be a no-op on dateScope={$scope}." );
        }
    }

    public function test_post_in_and_not_in_passthrough(): void {
        $args = $this->query()->buildQueryArgs( array(
            'postIn'    => array( 5, '7', 9 ),
            'postNotIn' => array( 11 ),
        ) );
        $this->assertSame( array( 5, 7, 9 ), $args['post__in'] );
        $this->assertSame( array( 11 ), $args['post__not_in'] );
    }

    public function test_post_in_drops_invalid_ids(): void {
        $args = $this->query()->buildQueryArgs( array(
            'postIn' => array( 0, -3, 'abc', '12', 4 ),
        ) );
        // 0 and negatives are not valid post ids; 'abc' is non-numeric. Only positive ids survive.
        $this->assertSame( array( 12, 4 ), $args['post__in'] );
    }

    public function test_empty_post_in_omits_the_key(): void {
        $args = $this->query()->buildQueryArgs( array( 'postIn' => array( '0', 'x' ) ) );
        $this->assertArrayNotHasKey( 'post__in', $args );
    }
}
