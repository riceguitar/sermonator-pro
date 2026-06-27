<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Migration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Migration\PageBuilderScanner;

/**
 * Unit coverage for the READ-ONLY page-builder fingerprint floor (spec T10).
 *
 * Exercises scan() directly with an injected candidate provider, so no $wpdb is needed.
 * The render/Site Health surfaces (which call translation/escaping helpers) are covered by the
 * integration suite; here we pin the detection logic and the zero-writes invariant.
 */
final class PageBuilderScannerTest extends TestCase {
    /** @var array<int,array<string,array<int,mixed>>> id => get_post_meta() shape. */
    private array $meta = array();

    /** @var array<int,string> id => post_content. */
    private array $content = array();

    /** @var array<int,string> id => title. */
    private array $titles = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->meta    = array();
        $this->content = array();
        $this->titles  = array();

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key = '', $single = false ) {
            return $this->meta[ (int) $id ] ?? array();
        } );
        Functions\when( 'get_post_field' )->alias( function ( $field, $id ) {
            return $this->content[ (int) $id ] ?? '';
        } );
        Functions\when( 'get_the_title' )->alias( function ( $id ) {
            return $this->titles[ (int) $id ] ?? '';
        } );
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Build the scanner over the fixture post ids. */
    private function scanner(): PageBuilderScanner {
        $ids = array_keys( $this->content + $this->meta );

        return new PageBuilderScanner( fn(): array => array_map( 'intval', $ids ) );
    }

    public function test_flags_elementor_post_with_legacy_taxonomy_slug_in_meta(): void {
        $this->titles[10]  = 'Sermons Landing';
        $this->meta[10]    = array(
            '_elementor_data' => array( '[{"settings":{"taxonomy":"wpfc_sermon_series"}}]' ),
        );
        $this->content[10] = '';

        $findings = $this->scanner()->scan();

        $this->assertCount( 1, $findings );
        $this->assertSame( PageBuilderScanner::TYPE_BUILDER_EMBEDDED, $findings[0]['type'] );
        $this->assertSame( PageBuilderScanner::SEVERITY_CRITICAL, $findings[0]['severity'] );
        $this->assertSame( array( 'elementor' ), $findings[0]['builders'] );
        $this->assertSame( PageBuilderScanner::WHERE_META, $findings[0]['where'] );
        $this->assertContains( 'wpfc_sermon_series', $findings[0]['refs'] );
    }

    public function test_flags_divi_content_shortcode_with_wpfc_sermon_reference(): void {
        // Divi via content shortcode (no builder meta key), legacy post-type token in content.
        $this->content[20] = '[et_pb_section][et_pb_code]post_type=wpfc_sermon[/et_pb_code][/et_pb_section]';
        $this->meta[20]    = array();

        $findings = $this->scanner()->scan();

        $this->assertCount( 1, $findings );
        $this->assertSame( PageBuilderScanner::TYPE_BUILDER_EMBEDDED, $findings[0]['type'] );
        $this->assertSame( array( 'divi' ), $findings[0]['builders'] );
        $this->assertSame( PageBuilderScanner::WHERE_CONTENT, $findings[0]['where'] );
        $this->assertContains( 'wpfc_sermon', $findings[0]['refs'] );
    }

    public function test_flags_beaver_builder_array_meta_with_legacy_shortcode(): void {
        // Beaver stores an unserialized array; stringify() must JSON-encode it to search.
        $this->meta[30]    = array(
            '_fl_builder_data' => array(
                array( 'node' => 'abc', 'settings' => array( 'html' => '[latest_series]' ) ),
            ),
        );
        $this->content[30] = '';

        $findings = $this->scanner()->scan();

        // Floor + the distinct meta-shortcode finding.
        $types = array_column( $findings, 'type' );
        $this->assertContains( PageBuilderScanner::TYPE_BUILDER_EMBEDDED, $types );
        $this->assertContains( PageBuilderScanner::TYPE_SHORTCODE_IN_META, $types );
        foreach ( $findings as $f ) {
            $this->assertSame( array( 'beaver' ), $f['builders'] );
        }
    }

    public function test_meta_embedded_shortcode_is_the_distinct_lower_severity_finding(): void {
        $this->meta[40]    = array(
            '_elementor_data' => array( '[{"widget":"shortcode","settings":{"shortcode":"[sermons per_page=5]"}}]' ),
        );
        $this->content[40] = '';

        $findings = $this->scanner()->scan();

        $distinct = array_values( array_filter(
            $findings,
            static fn( array $f ): bool => $f['type'] === PageBuilderScanner::TYPE_SHORTCODE_IN_META
        ) );

        $this->assertCount( 1, $distinct, 'exactly one distinct meta-shortcode finding' );
        $this->assertSame( PageBuilderScanner::SEVERITY_WARNING, $distinct[0]['severity'] );
        $this->assertSame( PageBuilderScanner::WHERE_META, $distinct[0]['where'] );
        $this->assertContains( '[sermons]', $distinct[0]['refs'] );
    }

    public function test_builder_post_without_legacy_ref_is_not_flagged(): void {
        $this->meta[50]    = array(
            '_elementor_data' => array( '[{"widget":"heading","settings":{"title":"Welcome"}}]' ),
        );
        $this->content[50] = '[et_pb_section]Just a normal page[/et_pb_section]';

        $this->assertSame( array(), $this->scanner()->scan() );
    }

    public function test_legacy_ref_without_builder_is_not_flagged(): void {
        // A bare [sermons] in plain content is the do_shortcode shim's job, NOT this scanner's.
        $this->meta[60]    = array(
            'some_unrelated_meta' => array( 'value' ),
        );
        $this->content[60] = '<p>Watch the latest [sermons] below. Topic: wpfc_sermon_topics</p>';

        $this->assertSame( array(), $this->scanner()->scan() );
    }

    public function test_scan_performs_zero_writes(): void {
        // Any DB-write path being invoked fails the test (Brain Monkey ->never()).
        foreach ( array(
            'update_post_meta',
            'add_post_meta',
            'delete_post_meta',
            'update_option',
            'add_option',
            'delete_option',
            'wp_insert_post',
            'wp_update_post',
            'wp_delete_post',
            'wp_set_object_terms',
        ) as $writeFn ) {
            Functions\expect( $writeFn )->never();
        }

        $this->meta[70]    = array(
            '_elementor_data' => array( '[{"settings":{"shortcode":"[sermons]"}}]' ),
        );
        $this->content[70] = '[et_pb_section]wpfc_sermon[/et_pb_section]';

        $findings = $this->scanner()->scan();

        // Sanity: it really did detect something (so "no writes" isn't vacuously true).
        $this->assertNotEmpty( $findings );
    }
}
