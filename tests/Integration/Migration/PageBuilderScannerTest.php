<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\PageBuilderScanner;
use Sermonator\Migration\LegacyIdentifiers;

/**
 * Integration coverage for {@see PageBuilderScanner}, driving the REAL `$wpdb` candidate
 * query, `get_post_meta`/`get_post_field`, the `site_status_tests` wiring, and — most
 * importantly — the ZERO-WRITES invariant against a live database. NOT run in this
 * environment (no Docker / wp-env); authored to run under wp-env later.
 *
 * What the unit test cannot exercise and this does:
 *   - the default {@see PageBuilderScanner::queryCandidates()} $wpdb scan (builder meta
 *     keys + builder shortcodes in post_content), so drafts/published mix is real;
 *   - the fingerprint floor over real serialized Beaver data and real Elementor JSON meta;
 *   - the distinct meta-embedded-shortcode finding;
 *   - the Site Health "direct" test surfacing through the `site_status_tests` filter;
 *   - a hard zero-writes assertion: a full row-count + content checksum snapshot of
 *     wp_posts/wp_postmeta/wp_options taken before and after a complete scan + render +
 *     Site Health read, asserted byte-identical.
 */
final class PageBuilderScannerTest extends WP_UnitTestCase {
    /**
     * Create a post with the given content + meta.
     *
     * @param array<string,mixed> $meta
     */
    private function makePost( string $content, array $meta, string $status = 'publish', string $title = 'A Page' ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'    => 'page',
            'post_status'  => $status,
            'post_title'   => $title,
            'post_content' => $content,
        ) );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $id, $key, $value );
        }

        return $id;
    }

    public function test_elementor_post_with_legacy_shortcode_is_flagged_twice(): void {
        $id = $this->makePost(
            '',
            array(
                '_elementor_data' => '[{"id":"a1","widgetType":"shortcode","settings":{"shortcode":"[sermons per_page=5]"}}]',
            ),
            'publish',
            'Sermons Page'
        );

        $findings = ( new PageBuilderScanner() )->scan();
        $mine     = array_values( array_filter( $findings, static fn( array $f ): bool => $f['post_id'] === $id ) );

        $types = array_column( $mine, 'type' );
        $this->assertContains( PageBuilderScanner::TYPE_BUILDER_EMBEDDED, $types, 'floor finding' );
        $this->assertContains( PageBuilderScanner::TYPE_SHORTCODE_IN_META, $types, 'distinct meta-shortcode finding' );

        $distinct = array_values( array_filter(
            $mine,
            static fn( array $f ): bool => $f['type'] === PageBuilderScanner::TYPE_SHORTCODE_IN_META
        ) );
        $this->assertSame( PageBuilderScanner::SEVERITY_WARNING, $distinct[0]['severity'] );
        $this->assertContains( '[sermons]', $distinct[0]['refs'] );
    }

    public function test_divi_content_with_legacy_taxonomy_slug_is_flagged(): void {
        $slug = LegacyIdentifiers::TAX_SERIES; // wpfc_sermon_series
        $id   = $this->makePost(
            '[et_pb_section][et_pb_code]taxonomy=' . $slug . '[/et_pb_code][/et_pb_section]',
            array()
        );

        $findings = ( new PageBuilderScanner() )->scan();
        $mine     = array_values( array_filter( $findings, static fn( array $f ): bool => $f['post_id'] === $id ) );

        $this->assertCount( 1, $mine );
        $this->assertSame( PageBuilderScanner::TYPE_BUILDER_EMBEDDED, $mine[0]['type'] );
        $this->assertSame( array( 'divi' ), $mine[0]['builders'] );
        $this->assertContains( $slug, $mine[0]['refs'] );
    }

    public function test_beaver_serialized_data_with_wpfc_sermon_reference_is_flagged(): void {
        // Beaver stores an array; WP serializes it. The scanner must JSON-encode to search.
        $id = $this->makePost(
            '',
            array(
                '_fl_builder_data' => array(
                    'node1' => array( 'type' => 'module', 'settings' => array( 'post_type' => LegacyIdentifiers::POST_TYPE_SERMON ) ),
                ),
            )
        );

        $findings = ( new PageBuilderScanner() )->scan();
        $mine     = array_values( array_filter( $findings, static fn( array $f ): bool => $f['post_id'] === $id ) );

        $this->assertCount( 1, $mine );
        $this->assertSame( array( 'beaver' ), $mine[0]['builders'] );
        $this->assertContains( LegacyIdentifiers::POST_TYPE_SERMON, $mine[0]['refs'] );
    }

    public function test_builder_post_without_legacy_ref_is_not_flagged(): void {
        $id = $this->makePost(
            '[et_pb_section]Welcome[/et_pb_section]',
            array( '_elementor_data' => '[{"widgetType":"heading","settings":{"title":"Hi"}}]' )
        );

        $findings = ( new PageBuilderScanner() )->scan();
        $mine     = array_filter( $findings, static fn( array $f ): bool => $f['post_id'] === $id );

        $this->assertSame( array(), array_values( $mine ) );
    }

    public function test_legacy_shortcode_without_builder_is_not_flagged(): void {
        // A bare [sermons] in plain content is the do_shortcode shim's job, not this scanner's.
        $id = $this->makePost( '<p>Latest [sermons] and topic wpfc_sermon_topics.</p>', array() );

        $findings = ( new PageBuilderScanner() )->scan();
        $mine     = array_filter( $findings, static fn( array $f ): bool => $f['post_id'] === $id );

        $this->assertSame( array(), array_values( $mine ) );
    }

    public function test_site_health_test_is_registered_and_reflects_findings(): void {
        $this->makePost(
            '',
            array( '_elementor_data' => '[{"settings":{"shortcode":"[sermons]"}}]' )
        );

        $scanner = new PageBuilderScanner();
        $tests   = $scanner->registerSiteHealthTest( array() );
        $this->assertArrayHasKey( PageBuilderScanner::SITE_HEALTH_TEST, $tests['direct'] );

        $result = $scanner->siteHealthResult();
        $this->assertSame( 'recommended', $result['status'] );
        $this->assertNotSame( '', $result['description'] );
    }

    public function test_site_health_is_green_with_no_findings(): void {
        // No builder posts created in this isolated test.
        $result = ( new PageBuilderScanner() )->siteHealthResult();
        $this->assertSame( 'good', $result['status'] );
    }

    public function test_render_report_escapes_and_lists_findings(): void {
        $this->makePost(
            '',
            array( '_elementor_data' => '[{"settings":{"shortcode":"[sermons]"}}]' ),
            'publish',
            'My <script> Page'
        );

        $html = ( new PageBuilderScanner() )->renderReport();
        $this->assertStringContainsString( 'notice-warning', $html );
        // The raw title must be escaped, never emitted verbatim.
        $this->assertStringNotContainsString( '<script>', $html );
    }

    public function test_scan_render_and_site_health_perform_zero_writes(): void {
        // A representative mix the scanner will read across.
        $this->makePost(
            '[et_pb_section][/et_pb_section]',
            array(
                '_elementor_data'  => '[{"settings":{"shortcode":"[sermons]"}}]',
                '_fl_builder_data' => array( 'n' => array( 'post_type' => LegacyIdentifiers::POST_TYPE_SERMON ) ),
            )
        );
        $this->makePost( '<p>plain page</p>', array() );

        $before = $this->dbFingerprint();

        $scanner = new PageBuilderScanner();
        $scanner->scan();
        $scanner->renderReport();
        $scanner->siteHealthResult();
        // Re-run to be sure nothing memoizes-via-write on a second pass.
        $scanner->scan();

        $after = $this->dbFingerprint();

        $this->assertSame( $before, $after, 'the scanner must not write to posts, postmeta, or options' );
    }

    /**
     * A content+count fingerprint of the tables the scanner touches. Any write (row
     * added/removed/changed) changes the hash. Read-only.
     *
     * @return array<string,string>
     */
    private function dbFingerprint(): array {
        global $wpdb;

        return array(
            'posts'    => (string) $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(ID, ':', post_content ORDER BY ID)) FROM {$wpdb->posts}" )
                . '|' . (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
            'postmeta' => (string) $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(meta_id, ':', meta_key, ':', meta_value ORDER BY meta_id)) FROM {$wpdb->postmeta}" )
                . '|' . (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
            'options'  => (string) $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(option_id, ':', option_name, ':', option_value ORDER BY option_id)) FROM {$wpdb->options}" )
                . '|' . (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
        );
    }
}
