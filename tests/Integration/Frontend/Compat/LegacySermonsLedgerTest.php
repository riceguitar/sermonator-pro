<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend\Compat;

use WP_UnitTestCase;
use Sermonator\Schema\Identifiers as ID;

/**
 * Bundle 2 Task 1 (review fix) — contract-boundary pins for the attribute-faithful
 * `[sermons]`/`[sermons_sm]` ledger. Each test encodes a corrected ledger row from the
 * adversarial review, asserted at the `do_shortcode()` boundary (rendered HTML / order /
 * editor notice) so it is independent of the mapper's internal API.
 *
 * These are FORWARD pins for the T2–T6 implementation (SermonQuery dateScope + orderby,
 * LegacyAttributeMapper, Renderer pagination). They will not pass until that lands.
 *
 * NOTE: integration suite — requires wp-env (Docker). NOT run in this environment (no
 * Docker available); written as the pinned spec.
 *
 * Corrected rows pinned here:
 *  - default `order`/`orderby` come from the migrated archive options (finding #2)
 *  - `orderby=date` → published ONLY when archive_orderby==='date', else preached (#1/#8)
 *  - `year`/`month`/`before`/`after` gated on dateScope=preached (#3)
 *  - `after` is exact-equality (not ignore) AND keeps a notice (#4/#9)
 *  - numeric `filter_value` keeps a notice even on resolution (#5/#10)
 *  - `per_page` paginates via the registered `sermon_page` var on a non-archive page (#6/#7)
 */
final class LegacySermonsLedgerTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option( ID::OPTION_ARCHIVE_ORDERBY );
        delete_option( ID::OPTION_ARCHIVE_ORDER );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * @param string|null $preached META_DATE string (signed unix ts) or null for a DATELESS sermon.
     */
    private function makeSermon( string $title, ?string $preached ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
            'post_title'  => $title,
        ) );
        if ( null !== $preached ) {
            update_post_meta( $id, ID::META_DATE, $preached );
        }
        return $id;
    }

    /** Assert $first appears BEFORE $second in the rendered output. */
    private function assertOrder( string $html, string $first, string $second ): void {
        $p1 = strpos( $html, $first );
        $p2 = strpos( $html, $second );
        $this->assertNotFalse( $p1, "missing '$first'" );
        $this->assertNotFalse( $p2, "missing '$second'" );
        $this->assertLessThan( $p2, $p1, "'$first' should sort before '$second'" );
    }

    // ---------------------------------------------------------------- defaults (#2)

    public function test_bare_sermons_honors_migrated_archive_order_and_orderby(): void {
        update_option( ID::OPTION_ARCHIVE_ORDERBY, 'date_preached' );
        update_option( ID::OPTION_ARCHIVE_ORDER, 'asc' ); // NOT SM's desc default

        $this->makeSermon( 'Earlier', '1000000000' );
        $this->makeSermon( 'Later', '1700000000' );

        $html = do_shortcode( '[sermons]' );

        // Ascending preached order, per the migrated archive_order=asc — a naive
        // hardcoded DESC default would silently reverse this set.
        $this->assertOrder( $html, 'Earlier', 'Later' );
    }

    // --------------------------------------------------- orderby=date resolution (#1/#8)

    public function test_orderby_date_drops_future_and_dateless_when_option_is_preached(): void {
        update_option( ID::OPTION_ARCHIVE_ORDERBY, 'date_preached' );
        $this->makeSermon( 'PastSermon', '1000000000' );
        $this->makeSermon( 'FutureSermon', (string) ( time() + 31536000 ) );
        $this->makeSermon( 'DatelessSermon', null );

        $html = do_shortcode( '[sermons orderby="date"]' );

        // archive_orderby=date_preached → orderby=date resolves to dateScope=preached,
        // which drops FUTURE + DATELESS (SM display_sermons() :866 + meta_value_num branch).
        $this->assertStringContainsString( 'PastSermon', $html );
        $this->assertStringNotContainsString( 'FutureSermon', $html );
        $this->assertStringNotContainsString( 'DatelessSermon', $html );
    }

    public function test_orderby_date_includes_future_and_dateless_when_option_is_date(): void {
        update_option( ID::OPTION_ARCHIVE_ORDERBY, 'date' );
        $this->makeSermon( 'PastSermon', '1000000000' );
        $this->makeSermon( 'FutureSermon', (string) ( time() + 31536000 ) );
        $this->makeSermon( 'DatelessSermon', null );

        $html = do_shortcode( '[sermons orderby="date"]' );

        // archive_orderby===date → orderby=date resolves to PUBLISHED date, which includes
        // future + dateless. Mapping date→published UNCONDITIONALLY would wrongly do this for
        // a default-configured (date_preached) site too.
        $this->assertStringContainsString( 'PastSermon', $html );
        $this->assertStringContainsString( 'FutureSermon', $html );
        $this->assertStringContainsString( 'DatelessSermon', $html );
    }

    // ------------------------------------------- year/month/before gating (#3)

    public function test_year_filter_is_a_noop_under_a_non_preached_orderby(): void {
        // SM applies year/month only inside the meta_value_num (preached) branch; under
        // orderby=title it is silently ignored. Over-filtering to 2020 would render fewer
        // sermons than SM did.
        $this->makeSermon( 'AlphaSermon', strtotime( '2019-06-01' ) ? (string) strtotime( '2019-06-01' ) : '0' );
        $this->makeSermon( 'BetaSermon', strtotime( '2020-06-01' ) ? (string) strtotime( '2020-06-01' ) : '0' );

        $html = do_shortcode( '[sermons orderby="title" year="2020"]' );

        $this->assertStringContainsString( 'AlphaSermon', $html, 'year must be ignored under orderby=title (bug-compatible)' );
        $this->assertStringContainsString( 'BetaSermon', $html );
    }

    // --------------------------------------------------- after exact-equality (#4/#9)

    public function test_after_keeps_notice_for_editor_and_does_not_render_full_list(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );

        // A sermon NOT preached exactly on 2020-01-01 must be EXCLUDED — SM's `=>`→`=`
        // normalization made `after` an exact-day match, not the full list.
        $this->makeSermon( 'OffDaySermon', '1000000000' );

        $html = do_shortcode( '[sermons after="2020-01-01"]' );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html,
            'after is UNVALIDATABLE (strtotime/TZ reconstruction) → notice kept' );
        $this->assertStringNotContainsString( 'OffDaySermon', $html,
            'after is exact-equality (near-empty), never the full list SM never produced' );
    }

    public function test_after_renders_no_notice_for_a_visitor(): void {
        wp_set_current_user( 0 );
        $this->makeSermon( 'AnySermon', '1000000000' );

        $html = do_shortcode( '[sermons after="2020-01-01"]' );

        $this->assertStringNotContainsString( 'sermonator-compat-notice', $html );
    }

    // ----------------------------------------- numeric filter_value notice (#5/#10)

    public function test_numeric_filter_value_keeps_notice_even_when_it_resolves(): void {
        $editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        $this->makeSermon( 'AnySermon', '1000000000' );

        // SM's numeric filter_value was a broken slug lookup (near-empty); a crosswalk-resolved
        // render is a reinterpretation of intent, NOT SM's output → notice stays.
        $html = do_shortcode( '[sermons filter_by="series" filter_value="5"]' );

        $this->assertStringContainsString( 'sermonator-compat-notice', $html,
            'numeric filter_value keeps a notice regardless of crosswalk resolution' );
    }

    // ------------------------------------------------- per_page pagination (#6/#7)

    public function test_per_page_paginates_via_registered_sermon_page_var_not_archive_paged(): void {
        $this->makeSermon( 'PageOneA', '1000000003' );
        $this->makeSermon( 'PageOneB', '1000000002' );
        $this->makeSermon( 'PageTwoC', '1000000001' );

        // The archive main query var stays at its default (1) — proving the pager reads the
        // dedicated, REGISTERED sermon_page var, not GridArgs::currentPage()'s paged/page.
        set_query_var( 'sermon_page', 2 );

        $html = do_shortcode( '[sermons per_page="2"]' );

        $this->assertStringContainsString( 'PageTwoC', $html,
            'page 2 (via sermon_page) must reach the long tail — no silent tail-drop' );
        $this->assertStringNotContainsString( 'PageOneA', $html );
        $this->assertStringNotContainsString( 'PageOneB', $html );
    }

    public function test_sermon_page_query_var_is_registered(): void {
        // An unregistered var makes get_query_var('sermon_page') always empty → pager stuck on
        // page 1 → silent tail-drop. The query_vars filter must add it.
        $vars = apply_filters( 'query_vars', array() );
        $this->assertContains( 'sermon_page', $vars,
            'sermon_page must be registered via the query_vars filter for the embedded pager to work' );
    }
}
