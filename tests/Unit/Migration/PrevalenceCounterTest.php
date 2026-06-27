<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Frontend\Feed\PodcastScopeResolver;
use Sermonator\Migration\PageBuilderScanner;
use Sermonator\Migration\PrevalenceCounter;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for the §63 prevalence counter (spec T11).
 *
 * Drives {@see PrevalenceCounter::tally()} with injected providers (no `$wpdb`), pins the
 * rollup SHAPE + counts, and asserts the two hard boundaries: {@see PrevalenceCounter::run()}
 * writes exactly once; {@see PrevalenceCounter::tally()} and the report read path write NOTHING.
 */
final class PrevalenceCounterTest extends TestCase {
    /** @var array<int,mixed> podcast id => META_PODCAST_SETTINGS blob. */
    private array $podcastSettings = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->podcastSettings = array();

        // PodcastScopeResolver::forPodcast() reads only this.
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key = '', $single = false ) {
            if ( ID::META_PODCAST_SETTINGS === $key ) {
                return $this->podcastSettings[ (int) $id ] ?? array();
            }
            return array();
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a counter whose podcast-id list comes from $this->podcastSettings keys, with the
     * given shortcode embeds and page-builder findings injected.
     *
     * @param list<array<array-key,mixed>>                   $embeds
     * @param list<array{post_id:int,type:string}>           $findings
     */
    private function counter( array $embeds = array(), array $findings = array() ): PrevalenceCounter {
        $ids = array_map( 'intval', array_keys( $this->podcastSettings ) );

        return new PrevalenceCounter(
            fn(): array => $ids,
            new PodcastScopeResolver(),
            fn(): array => $embeds,
            fn(): array => $findings
        );
    }

    public function test_tally_returns_the_full_rollup_shape(): void {
        $stats = $this->counter()->tally();

        $this->assertArrayHasKey( 'generated_at', $stats );
        $this->assertArrayHasKey( 'podcasts', $stats );
        $this->assertArrayHasKey( 'shortcodes', $stats );
        $this->assertArrayHasKey( 'page_builder', $stats );

        $this->assertSame(
            array( 'published', 'with_scope', 'single_scoped', 'multi_podcast' ),
            array_keys( $stats['podcasts'] )
        );
        $this->assertSame(
            array( 'posts', 'with_attributes', 'total_attributes', 'max_attributes', 'attribute_histogram' ),
            array_keys( $stats['shortcodes'] )
        );
        $this->assertSame(
            array( 'pages', 'findings', 'builder_embedded', 'shortcode_in_meta' ),
            array_keys( $stats['page_builder'] )
        );
    }

    public function test_podcast_scope_counts_single_axis_vs_multi_axis(): void {
        // p1: single-axis scope (series only) → with_scope + single_scoped.
        $this->podcastSettings[1] = array( ID::TAX_SERIES => array( 10 ) );
        // p2: multi-axis scope (preacher AND topic) → with_scope, NOT single_scoped.
        $this->podcastSettings[2] = array( ID::TAX_PREACHER => array( 5 ), ID::TAX_TOPIC => array( 7 ) );
        // p3: no scope (only identity keys / empty) → neither.
        $this->podcastSettings[3] = array( 'title' => 'My Show' );

        $podcasts = $this->counter()->tally()['podcasts'];

        $this->assertSame( 3, $podcasts['published'] );
        $this->assertSame( 2, $podcasts['with_scope'] );
        $this->assertSame( 1, $podcasts['single_scoped'] );
        $this->assertTrue( $podcasts['multi_podcast'] );
    }

    public function test_single_podcast_site_is_not_multi(): void {
        $this->podcastSettings[1] = array( ID::TAX_SERIES => array( 10 ) );

        $podcasts = $this->counter()->tally()['podcasts'];

        $this->assertSame( 1, $podcasts['published'] );
        $this->assertFalse( $podcasts['multi_podcast'] );
    }

    public function test_zero_podcasts_yields_zero_counts(): void {
        $podcasts = $this->counter()->tally()['podcasts'];

        $this->assertSame(
            array( 'published' => 0, 'with_scope' => 0, 'single_scoped' => 0, 'multi_podcast' => false ),
            $podcasts
        );
    }

    public function test_shortcode_attribute_density_counts_names_total_and_max(): void {
        $embeds = array(
            array( 'per_page' => '5', 'orderby' => 'date' ), // 2 attrs
            array( 'per_page' => '10' ),                     // 1 attr
            array(),                                          // bare [sermons] — 0 attrs
            array( 0 => 'hide_filters', 'per_page' => '3' ),  // valueless flag + 1 named = 2 attrs
        );

        $shortcodes = $this->counter( $embeds )->tally()['shortcodes'];

        $this->assertSame( 4, $shortcodes['posts'] );
        $this->assertSame( 3, $shortcodes['with_attributes'] );
        $this->assertSame( 5, $shortcodes['total_attributes'] ); // 2+1+0+2
        $this->assertSame( 2, $shortcodes['max_attributes'] );

        $hist = $shortcodes['attribute_histogram'];
        $this->assertSame( 3, $hist['per_page'] );
        $this->assertSame( 1, $hist['orderby'] );
        $this->assertSame( 1, $hist['hide_filters'] );
        // arsort: the most-used attribute is listed first.
        $this->assertSame( 'per_page', array_key_first( $hist ) );
    }

    public function test_no_shortcode_embeds_yields_empty_density(): void {
        $shortcodes = $this->counter()->tally()['shortcodes'];

        $this->assertSame( 0, $shortcodes['posts'] );
        $this->assertSame( 0, $shortcodes['total_attributes'] );
        $this->assertSame( array(), $shortcodes['attribute_histogram'] );
    }

    public function test_page_builder_finding_counts_split_by_type_and_dedupe_pages(): void {
        $findings = array(
            array( 'post_id' => 10, 'type' => PageBuilderScanner::TYPE_BUILDER_EMBEDDED ),
            array( 'post_id' => 10, 'type' => PageBuilderScanner::TYPE_SHORTCODE_IN_META ),
            array( 'post_id' => 20, 'type' => PageBuilderScanner::TYPE_BUILDER_EMBEDDED ),
        );

        $builder = $this->counter( array(), $findings )->tally()['page_builder'];

        $this->assertSame( 2, $builder['pages'] );             // 10 + 20 unique
        $this->assertSame( 3, $builder['findings'] );
        $this->assertSame( 2, $builder['builder_embedded'] );
        $this->assertSame( 1, $builder['shortcode_in_meta'] );
    }

    public function test_run_persists_the_rollup_exactly_once(): void {
        $captured = null;
        Functions\expect( 'update_option' )
            ->once()
            ->with( ID::OPTION_MIGRATION_PREVALENCE, \Mockery::type( 'array' ), false )
            ->andReturnUsing( function ( $name, $value ) use ( &$captured ) {
                $captured = $value;
                return true;
            } );

        $returned = $this->counter()->run();

        $this->assertIsArray( $captured );
        $this->assertSame( $returned, $captured );
        $this->assertArrayHasKey( 'podcasts', $captured );
    }

    public function test_tally_performs_zero_writes(): void {
        foreach ( array(
            'update_option', 'add_option', 'delete_option',
            'update_post_meta', 'add_post_meta', 'delete_post_meta',
            'wp_insert_post', 'wp_update_post', 'wp_delete_post',
        ) as $writeFn ) {
            Functions\expect( $writeFn )->never();
        }

        $this->podcastSettings[1] = array( ID::TAX_SERIES => array( 10 ) );
        $stats = $this->counter(
            array( array( 'per_page' => '5' ) ),
            array( array( 'post_id' => 9, 'type' => PageBuilderScanner::TYPE_BUILDER_EMBEDDED ) )
        )->tally();

        // Sanity: it really did compute something (so "no writes" isn't vacuously true).
        $this->assertSame( 1, $stats['podcasts']['with_scope'] );
    }

    public function test_render_report_is_a_pure_reader_and_empty_when_unrun(): void {
        Functions\expect( 'update_option' )->never();
        Functions\expect( 'add_option' )->never();
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( '', ( new PrevalenceCounter() )->renderReport() );
    }

    public function test_render_report_renders_escaped_counts_from_the_stored_option(): void {
        Functions\expect( 'update_option' )->never();
        Functions\when( '__' )->returnArg( 1 );
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );
        Functions\when( 'get_option' )->justReturn( array(
            'generated_at' => 123,
            'podcasts'     => array( 'published' => 2, 'with_scope' => 1, 'single_scoped' => 1, 'multi_podcast' => true ),
            'shortcodes'   => array(
                'posts'               => 3,
                'with_attributes'     => 2,
                'total_attributes'    => 4,
                'max_attributes'      => 2,
                'attribute_histogram' => array( 'per_page' => 2, 'orderby' => 1 ),
            ),
            'page_builder' => array( 'pages' => 1, 'findings' => 1, 'builder_embedded' => 1, 'shortcode_in_meta' => 0 ),
        ) );

        $html = ( new PrevalenceCounter() )->renderReport();

        $this->assertStringContainsString( 'sermonator-prevalence', $html );
        $this->assertStringContainsString( 'per_page (2)', $html );
        $this->assertStringContainsString( 'orderby (1)', $html );
    }
}
