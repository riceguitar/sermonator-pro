<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Frontend;

use WP_UnitTestCase;
use Sermonator\Frontend\Renderer;
use Sermonator\Frontend\SermonQuery;
use Sermonator\Schema\Identifiers as ID;

/**
 * Bundle 2 / T5 integration coverage: the paginated list capability that CERTIFIES
 * `[sermons per_page=N]` faithful. The native grid renders a fixed count with NO pager, so on an
 * archive larger than `per_page` its long tail is silently lost — the canonical fail-wrong. This
 * proves the dedicated, registered `sermon_page` query var drives a real second page that carries
 * the tail with zero content loss.
 *
 * The current page is sourced from `sermon_page` — NOT the main query's `paged`/`page` (reserved
 * for the sermon ARCHIVE) — exactly as it must behave on a STATIC embedding page, where `paged`
 * stays 1.
 *
 * NOTE: requires wp-env (Docker). NOT run in this environment (no Docker); authored per the spec
 * test strategy and to be run in CI. (The full `[sermons]` shortcode wiring onto this capability
 * lands in T6; this test drives the engine + Renderer directly.)
 */
final class PaginatedListTest extends WP_UnitTestCase {
    /** A fixed "now" so the preached future-cap (when used) is deterministic. */
    private const NOW = 2_000_000_000;

    /** Create a dated sermon; descending dates give a deterministic newest-first order. */
    private function sermon( int $ts, string $title ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'  => ID::POST_TYPE_SERMON,
            'post_title' => $title,
        ) );
        update_post_meta( $id, ID::META_DATE, (string) $ts );
        return $id;
    }

    /** @param object $result */
    private function ids( $result ): array {
        return array_map( static fn( $v ) => $v->id, $result->sermons );
    }

    protected function tearDown(): void {
        set_query_var( SermonQuery::PAGE_QUERY_VAR, '' );
        parent::tearDown();
    }

    /**
     * The certifying test: 5 sermons, per_page=2 → 3 pages. Page 2 (via `sermon_page`) reaches the
     * middle, page 3 reaches the TAIL, and the union of every page equals the full set with no loss
     * and no duplication.
     */
    public function test_per_page_reaches_page_two_and_tail_with_no_content_lost(): void {
        $all = array();
        for ( $i = 0; $i < 5; $i++ ) {
            // Descending dates: s0 newest … s4 oldest → stable DESC order.
            $all[] = $this->sermon( self::NOW - $i * 86400, "Sermon {$i}" );
        }

        $query = new SermonQuery( self::NOW );

        $page1 = $query->run( array( 'perPage' => 2, 'page' => 1 ) );
        $page2 = $query->run( array( 'perPage' => 2, 'page' => 2 ) );
        $page3 = $query->run( array( 'perPage' => 2, 'page' => 3 ) );

        // Pagination facts surfaced on the result (found_posts / max_num_pages).
        $this->assertSame( 5, $page1->total, 'found_posts must reflect the FULL archive, not the page slice.' );
        $this->assertSame( 3, $page1->totalPages, 'max_num_pages = ceil(5 / 2).' );

        $this->assertCount( 2, $page1->sermons );
        $this->assertCount( 2, $page2->sermons );
        $this->assertCount( 1, $page3->sermons, 'The tail page carries the remaining sermon.' );

        // Page 2 is genuinely different from page 1, and page 3 holds the oldest (the tail).
        $this->assertNotSame( $this->ids( $page1 ), $this->ids( $page2 ) );
        $this->assertSame( array( $all[4] ), $this->ids( $page3 ), 'Oldest sermon is on the last page.' );

        // No content lost: every page concatenated equals the whole set, deduped.
        $seen = array_merge( $this->ids( $page1 ), $this->ids( $page2 ), $this->ids( $page3 ) );
        sort( $seen );
        $expected = $all;
        sort( $expected );
        $this->assertSame( $expected, $seen, 'Union of all pages must be the complete, non-duplicated set.' );
    }

    /**
     * The read-path pin: with no explicit `page`, `run()` sources the current page from the
     * registered `sermon_page` query var — proving an embedded list paginates without touching the
     * main query's `paged`.
     */
    public function test_run_sources_current_page_from_sermon_page_query_var(): void {
        $all = array();
        for ( $i = 0; $i < 5; $i++ ) {
            $all[] = $this->sermon( self::NOW - $i * 86400, "Sermon {$i}" );
        }

        $query = new SermonQuery( self::NOW );

        // Default (var unset) → page 1.
        set_query_var( SermonQuery::PAGE_QUERY_VAR, '' );
        $this->assertSame( 1, SermonQuery::currentPage() );
        $default = $query->run( array( 'perPage' => 2 ) );
        $this->assertSame( $this->ids( $query->run( array( 'perPage' => 2, 'page' => 1 ) ) ), $this->ids( $default ) );

        // sermon_page=2 → the implicit page becomes 2 with no explicit `page` arg.
        set_query_var( SermonQuery::PAGE_QUERY_VAR, '2' );
        $this->assertSame( 2, SermonQuery::currentPage() );
        $viaVar = $query->run( array( 'perPage' => 2 ) );
        $this->assertSame(
            $this->ids( $query->run( array( 'perPage' => 2, 'page' => 2 ) ) ),
            $this->ids( $viaVar ),
            'With no explicit page, run() must follow sermon_page.'
        );
        $this->assertSame( 2, $viaVar->page );
    }

    /** The query var must be on the public whitelist, else get_query_var would return '' (stuck on page 1). */
    public function test_sermon_page_is_registered_on_the_public_query_vars(): void {
        $registered = apply_filters( 'query_vars', array() );
        $this->assertContains( SermonQuery::PAGE_QUERY_VAR, $registered, 'FrontendServiceProvider must register sermon_page.' );
    }

    /** The pager renders a real, escaped link to page 2 over `sermon_page` (query-string, not /page/N/). */
    public function test_renderer_pager_links_page_two_via_sermon_page(): void {
        $base = home_url( '/about/' ); // a STATIC (non-archive) embedding page.
        $html = ( new Renderer() )->pager( 3, 1, $base );

        $this->assertNotSame( '', $html );
        $this->assertStringContainsString( 'sermon_page=2', $html );
        $this->assertStringNotContainsString( '/page/', $html, 'Pretty /page/N/ permalinks collide with the main query on a static page.' );
        $this->assertStringContainsString( 'aria-current="page"', $html );
    }

    /** paginatedGrid couples the grid with the pager; the empty/single-page case shows no pager. */
    public function test_paginated_grid_shows_pager_only_when_multipage(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->sermon( self::NOW - $i * 86400, "Sermon {$i}" );
        }
        $renderer = new Renderer();
        $base     = home_url( '/about/' );

        $multi = ( new SermonQuery( self::NOW ) )->run( array( 'perPage' => 2, 'page' => 1 ) );
        $this->assertStringContainsString( 'sermonator-pager', $renderer->paginatedGrid( $multi, $base ) );

        $single = ( new SermonQuery( self::NOW ) )->run( array( 'perPage' => 50, 'page' => 1 ) );
        $this->assertStringNotContainsString( 'sermonator-pager', $renderer->paginatedGrid( $single, $base ), 'One page → no pager.' );
    }
}
