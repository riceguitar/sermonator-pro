<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Frontend\Compat;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Sermonator\Frontend\Compat\LegacyAttributeMapper;
use Sermonator\Frontend\DateScope;
use Sermonator\Schema\Identifiers as ID;

/**
 * Per-attribute ledger coverage for the Bundle 2 `[sermons]`/`[sermons_sm]` mapper
 * (T4). One test per ledger cell: FAITHFUL attrs drop their notice; UNVALIDATABLE /
 * UNSUPPORTED attrs are NAMED in unfaithfulAttrs (the precise per-attribute notice).
 *
 * The mapper is WP-light: it reads three options via get_option (posts_per_page,
 * the migrated archive order/orderby) and delegates numeric/slug resolution to the
 * shared LegacyTermResolver/LegacyPostResolver, which touch get_term_by / $wpdb.
 * All of that is stubbed here exactly as the T3 resolver unit tests stub it.
 */
final class LegacyAttributeMapperTest extends TestCase {
    /** @var array<string,mixed> Backing store for the get_option stub. */
    private array $options = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Option-driven defaults SM falls back to: order desc, orderby date_preached,
        // WP reading setting 10. Individual tests mutate $this->options before map().
        $this->options = array(
            'posts_per_page'           => 10,
            ID::OPTION_ARCHIVE_ORDER   => 'desc',
            ID::OPTION_ARCHIVE_ORDERBY => 'date_preached',
        );
        $options =& $this->options;
        Functions\when( 'get_option' )->alias(
            static function ( $key, $default = false ) use ( &$options ) {
                if ( array_key_exists( $key, $options ) ) {
                    return $options[ $key ];
                }
                return $default !== false ? $default : '';
            }
        );

        Functions\when( 'is_wp_error' )->justReturn( false );
        if ( ! class_exists( 'WP_Term' ) ) {
            eval( 'class WP_Term { public $term_id = 0; }' );
        }
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mapper(): LegacyAttributeMapper {
        // Pinned "now" so date('Y')/strtotime-based assertions are deterministic.
        return new LegacyAttributeMapper( now: strtotime( '2026-06-15 12:00:00 UTC' ) );
    }

    /**
     * Make get_term_by resolve a slug to the given new term id, or false on miss.
     */
    private function stubSlug( ?int $termId ): void {
        if ( $termId === null ) {
            Functions\when( 'get_term_by' )->justReturn( false );
            return;
        }
        $term          = new \WP_Term();
        $term->term_id = $termId;
        Functions\when( 'get_term_by' )->justReturn( $term );
    }

    /**
     * Install a $wpdb stub whose get_col() returns $rows for BOTH the termmeta
     * (TermCrosswalk) and postmeta (Crosswalk) lookups — empty rows == unresolved.
     *
     * @param list<int> $rows
     */
    private function stubCrosswalk( array $rows ): void {
        $GLOBALS['wpdb'] = new class( $rows ) {
            public string $termmeta = 'wp_termmeta';
            public string $postmeta = 'wp_postmeta';
            public string $posts    = 'wp_posts';
            /** @var list<int> */
            public array $rows;
            /** @param list<int> $rows */
            public function __construct( array $rows ) {
                $this->rows = $rows;
            }
            public function prepare( $query, ...$args ) {
                return $query;
            }
            public function get_col( $query ) {
                return $this->rows;
            }
        };
    }

    // === orderby / order defaults (FAITHFUL, option-driven) ===================

    public function test_bare_call_uses_option_driven_preached_defaults(): void {
        $result = $this->mapper()->map( array() );

        $this->assertSame( DateScope::PREACHED, $result->dateScope );
        $this->assertSame( 'preached', $result->orderby );
        $this->assertSame( 'DESC', $result->gridArgs['order'] );
        $this->assertSame( 10, $result->gridArgs['perPage'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_default_order_sources_the_migrated_archive_order_option(): void {
        $this->options[ ID::OPTION_ARCHIVE_ORDER ] = 'asc';

        $result = $this->mapper()->map( array() );

        $this->assertSame( 'ASC', $result->gridArgs['order'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_explicit_order_is_faithful(): void {
        $result = $this->mapper()->map( array( 'order' => 'ASC' ) );

        $this->assertSame( 'ASC', $result->gridArgs['order'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_orderby_published_is_none_scope(): void {
        $result = $this->mapper()->map( array( 'orderby' => 'published' ) );

        $this->assertSame( 'published', $result->orderby );
        $this->assertSame( DateScope::NONE, $result->dateScope );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_orderby_id_title_name_rand_none_comment_count_are_none_scope(): void {
        foreach ( array( 'id', 'title', 'name', 'rand', 'none', 'comment_count' ) as $orderby ) {
            $result = $this->mapper()->map( array( 'orderby' => $orderby ) );
            $this->assertSame( $orderby, $result->orderby, "orderby=$orderby token" );
            $this->assertSame( DateScope::NONE, $result->dateScope, "orderby=$orderby scope" );
            $this->assertSame( array(), $result->unfaithfulAttrs, "orderby=$orderby notice" );
        }
    }

    public function test_orderby_preached_is_preached_scope(): void {
        $result = $this->mapper()->map( array( 'orderby' => 'date_preached' ) );

        $this->assertSame( 'preached', $result->orderby );
        $this->assertSame( DateScope::PREACHED, $result->dateScope );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_orderby_date_resolves_to_published_only_when_archive_orderby_is_date(): void {
        $this->options[ ID::OPTION_ARCHIVE_ORDERBY ] = 'date';

        $result = $this->mapper()->map( array( 'orderby' => 'date' ) );

        $this->assertSame( 'published', $result->orderby );
        $this->assertSame( DateScope::NONE, $result->dateScope );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_orderby_date_resolves_to_preached_when_archive_orderby_is_not_date(): void {
        $this->options[ ID::OPTION_ARCHIVE_ORDERBY ] = 'date_preached';

        $result = $this->mapper()->map( array( 'orderby' => 'date' ) );

        $this->assertSame( 'preached', $result->orderby );
        $this->assertSame( DateScope::PREACHED, $result->dateScope );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_invalid_orderby_defaults_to_preached_without_a_notice(): void {
        $result = $this->mapper()->map( array( 'orderby' => 'totally-bogus' ) );

        $this->assertSame( 'preached', $result->orderby );
        $this->assertSame( DateScope::PREACHED, $result->dateScope );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    // === filter_by + filter_value ============================================

    public function test_slug_filter_value_resolves_to_new_term_no_notice(): void {
        $this->stubSlug( 77 );

        $result = $this->mapper()->map( array( 'filter_by' => 'series', 'filter_value' => 'grace' ) );

        $this->assertSame( array( ID::TAX_SERIES => array( 77 ) ), $result->gridArgs['taxonomies'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_slug_filter_value_falls_back_to_slug_passthrough_when_unresolved(): void {
        $this->stubSlug( null ); // no such term in the new taxonomy

        $result = $this->mapper()->map( array( 'filter_by' => 'preacher', 'filter_value' => 'jdoe' ) );

        // Pass the slug straight to field=slug — byte-identical to SM's empty/match;
        // faithful, so still no notice.
        $this->assertSame( array( ID::TAX_PREACHER => array( 'jdoe' ) ), $result->gridArgs['taxonomies'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_numeric_filter_value_resolves_but_keeps_notice(): void {
        $this->stubCrosswalk( array( 909 ) );

        $result = $this->mapper()->map( array( 'filter_by' => 'topic', 'filter_value' => '42' ) );

        $this->assertSame( array( ID::TAX_TOPIC => array( 909 ) ), $result->gridArgs['taxonomies'] );
        $this->assertContains( 'filter_value', $result->unfaithfulAttrs );
    }

    public function test_numeric_filter_value_unresolved_drops_axis_and_names_it(): void {
        $this->stubCrosswalk( array() ); // back-ref stripped / never migrated

        $result = $this->mapper()->map( array( 'filter_by' => 'book', 'filter_value' => '42' ) );

        $this->assertSame( array(), $result->gridArgs['taxonomies'] );
        $this->assertContains( 'filter_value', $result->unfaithfulAttrs );
    }

    public function test_unrecognized_filter_by_names_filter_by(): void {
        $result = $this->mapper()->map( array( 'filter_by' => 'banana', 'filter_value' => 'x' ) );

        $this->assertSame( array(), $result->gridArgs['taxonomies'] );
        $this->assertContains( 'filter_by', $result->unfaithfulAttrs );
    }

    public function test_taxonomy_and_tax_term_aliases_map_to_filter_by_filter_value(): void {
        $this->stubSlug( 5 );

        $result = $this->mapper()->map( array( 'taxonomy' => 'books', 'tax_term' => 'romans' ) );

        $this->assertSame( array( ID::TAX_BOOK => array( 5 ) ), $result->gridArgs['taxonomies'] );
    }

    // === include / exclude ===================================================

    public function test_include_resolves_legacy_ids_to_new_post_ids(): void {
        $this->stubCrosswalk( array( 501 ) );

        $result = $this->mapper()->map( array( 'include' => '42' ) );

        $this->assertSame( array( 501 ), $result->postIn );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_include_alias_id_resolves(): void {
        $this->stubCrosswalk( array( 501 ) );

        $result = $this->mapper()->map( array( 'id' => '42' ) );

        $this->assertSame( array( 501 ), $result->postIn );
    }

    public function test_include_unresolved_id_is_dropped_and_named(): void {
        $this->stubCrosswalk( array() );

        $result = $this->mapper()->map( array( 'include' => '42' ) );

        $this->assertSame( array(), $result->postIn );
        $this->assertContains( 'include', $result->unfaithfulAttrs );
    }

    public function test_exclude_resolves_to_post_not_in(): void {
        $this->stubCrosswalk( array( 777 ) );

        $result = $this->mapper()->map( array( 'exclude' => '9' ) );

        $this->assertSame( array( 777 ), $result->postNotIn );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    // === year / month / before / after (gated on PREACHED) ===================

    public function test_year_sets_between_bounds_and_disables_future_cap(): void {
        $result = $this->mapper()->map( array( 'year' => '2020' ) );

        $this->assertSame( strtotime( '2020-01-01' ), $result->gridArgs['dateRange']['min'] );
        $this->assertSame( strtotime( '2020-12-31' ), $result->gridArgs['dateRange']['max'] );
        $this->assertFalse( $result->gridArgs['dateRange']['capFuture'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_month_sets_between_bounds_for_the_month(): void {
        $result = $this->mapper()->map( array( 'year' => '2020', 'month' => '3' ) );

        $this->assertSame( strtotime( '2020-3-01' ), $result->gridArgs['dateRange']['min'] );
        $this->assertSame( strtotime( '2020-03-31' ), $result->gridArgs['dateRange']['max'] );
        $this->assertFalse( $result->gridArgs['dateRange']['capFuture'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_year_is_a_noop_under_a_non_preached_orderby(): void {
        $result = $this->mapper()->map( array( 'orderby' => 'title', 'year' => '2020' ) );

        $this->assertSame( array(), $result->gridArgs['dateRange'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_before_sets_upper_bound_no_notice(): void {
        $result = $this->mapper()->map( array( 'before' => '2021-06-01' ) );

        $this->assertSame( strtotime( '2021-06-01' ), $result->gridArgs['dateRange']['max'] );
        $this->assertArrayNotHasKey( 'capFuture', $result->gridArgs['dateRange'] );
        $this->assertSame( array(), $result->unfaithfulAttrs );
    }

    public function test_after_is_exact_equality_and_keeps_its_notice(): void {
        $result = $this->mapper()->map( array( 'after' => '2019-01-01' ) );

        $this->assertSame( strtotime( '2019-01-01' ), $result->gridArgs['dateRange']['equals'] );
        $this->assertContains( 'after', $result->unfaithfulAttrs );
    }

    public function test_after_is_a_silent_noop_under_a_non_preached_orderby(): void {
        $result = $this->mapper()->map( array( 'orderby' => 'title', 'after' => '2019-01-01' ) );

        $this->assertSame( array(), $result->gridArgs['dateRange'] );
        $this->assertNotContains( 'after', $result->unfaithfulAttrs );
    }

    // === per_page (FAITHFUL pending pagination -> NAMED until T5) =============

    public function test_explicit_per_page_is_named_until_pagination_lands(): void {
        $result = $this->mapper()->map( array( 'per_page' => '5' ) );

        $this->assertSame( 5, $result->gridArgs['perPage'] );
        $this->assertContains( 'per_page', $result->unfaithfulAttrs );
    }

    public function test_posts_per_page_alias_is_honored_and_named(): void {
        $result = $this->mapper()->map( array( 'posts_per_page' => '7' ) );

        $this->assertSame( 7, $result->gridArgs['perPage'] );
        $this->assertContains( 'per_page', $result->unfaithfulAttrs );
    }

    public function test_absent_per_page_uses_option_default_without_a_notice(): void {
        $this->options['posts_per_page'] = 25;

        $result = $this->mapper()->map( array() );

        $this->assertSame( 25, $result->gridArgs['perPage'] );
        $this->assertNotContains( 'per_page', $result->unfaithfulAttrs );
    }

    public function test_per_page_is_clamped_to_a_safe_ceiling(): void {
        $result = $this->mapper()->map( array( 'per_page' => '999999' ) );

        $this->assertSame( 100, $result->gridArgs['perPage'] );
    }

    // === NO-OP-SAFE (disable_pagination + its aliases) =======================

    public function test_disable_pagination_and_aliases_are_noop_safe_not_named(): void {
        foreach ( array( 'disable_pagination', 'hide_nav', 'hide_pagination' ) as $attr ) {
            $result = $this->mapper()->map( array( $attr => 'yes' ) );
            $this->assertSame( array(), $result->unfaithfulAttrs, "$attr should be NO-OP-SAFE" );
        }
    }

    // === UNSUPPORTED (named) =================================================

    public function test_image_size_is_named(): void {
        $result = $this->mapper()->map( array( 'image_size' => 'thumbnail' ) );

        $this->assertContains( 'image_size', $result->unfaithfulAttrs );
    }

    public function test_hide_filter_form_attrs_are_named(): void {
        foreach ( array(
            'hide_filters', 'hide_topics', 'hide_series', 'hide_preachers',
            'hide_books', 'hide_dates', 'hide_service_types',
        ) as $attr ) {
            $result = $this->mapper()->map( array( $attr => 'yes' ) );
            $this->assertContains( $attr, $result->unfaithfulAttrs, "$attr should be named" );
        }
    }

    public function test_show_initial_is_named(): void {
        $result = $this->mapper()->map( array( 'show_initial' => '1' ) );

        $this->assertContains( 'show_initial', $result->unfaithfulAttrs );
    }

    public function test_unknown_attribute_is_named(): void {
        $result = $this->mapper()->map( array( 'bogus_attr' => 'x' ) );

        $this->assertContains( 'bogus_attr', $result->unfaithfulAttrs );
    }

    public function test_positional_token_is_named_as_unknown(): void {
        $result = $this->mapper()->map( array( 0 => 'mystery' ) );

        $this->assertContains( 'mystery', $result->unfaithfulAttrs );
    }

    // === composition smoke test =============================================

    public function test_to_sermon_query_args_composes_all_axes(): void {
        $this->stubCrosswalk( array( 501 ) );

        $result = $this->mapper()->map( array( 'include' => '42', 'order' => 'asc', 'orderby' => 'preached' ) );
        $args   = $result->toSermonQueryArgs();

        $this->assertSame( 'ASC', $args['order'] );
        $this->assertSame( 'preached', $args['orderby'] );
        $this->assertSame( DateScope::PREACHED, $args['dateScope'] );
        $this->assertSame( array( 501 ), $args['postIn'] );
        $this->assertArrayNotHasKey( 'postNotIn', $args );
    }
}
