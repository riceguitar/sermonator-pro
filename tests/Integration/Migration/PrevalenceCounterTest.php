<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Migration\PrevalenceCounter;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for the §63 prevalence counter (spec T11), driving the REAL default
 * providers against a live `$wpdb`: the published-podcast WP_Query, the `[sermons]` content
 * `LIKE` scan + `shortcode_parse_atts()` density, the migrated-podcast scope read, and the
 * reused {@see \Sermonator\Migration\PageBuilderScanner} scan. NOT run in this environment
 * (no Docker / wp-env); authored to run under wp-env later.
 *
 * What the unit test cannot exercise and this does:
 *   - {@see PrevalenceCounter::queryPublishedPodcastIds()} over a real publish/draft mix;
 *   - {@see PrevalenceCounter::queryShortcodeEmbeds()} — real `[sermons` LIKE + the regex that
 *     distinguishes `[sermons]`/`[sermons_sm]` from `[list_sermons]`/`[sermon_images]`, with
 *     real `shortcode_parse_atts()` attribute parsing;
 *   - per-podcast scope read out of the real META_PODCAST_SETTINGS blob (through the meta
 *     sanitize_callback that preserves the scope keys);
 *   - a hard NO-WRITE-ON-REPORT assertion: a row-count + content checksum snapshot of
 *     wp_posts/wp_postmeta/wp_options around tally() + renderReport(), asserted byte-identical;
 *   - run() actually persisting OPTION_MIGRATION_PREVALENCE (the write-gated path).
 */
final class PrevalenceCounterTest extends WP_UnitTestCase {
    /** Create a published migrated podcast with the given settings blob. */
    private function makePodcast( array $settings, string $status = 'publish' ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_PODCAST,
            'post_status' => $status,
            'post_title'  => 'A Podcast',
        ) );
        update_post_meta( $id, ID::META_PODCAST_SETTINGS, $settings );

        return $id;
    }

    /** Create a post embedding the given content. */
    private function makePost( string $content ): int {
        return (int) self::factory()->post->create( array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => $content,
        ) );
    }

    public function test_run_persists_the_full_prevalence_rollup(): void {
        // Two published podcasts: one single-axis scope, one multi-axis. One unscoped. One DRAFT
        // (must be excluded from the published count).
        $this->makePodcast( array( ID::TAX_SERIES => array( 10 ) ) );
        $this->makePodcast( array( ID::TAX_PREACHER => array( 5 ), ID::TAX_TOPIC => array( 7 ) ) );
        $this->makePodcast( array( 'title' => 'Identity only' ) );
        $this->makePodcast( array( ID::TAX_SERIES => array( 99 ) ), 'draft' );

        // Embedded [sermons] density: a 2-attr embed, a bare embed, and a decoy that must NOT match.
        $this->makePost( 'Intro [sermons per_page="5" orderby="date"] outro' );
        $this->makePost( 'Just [sermons] here' );
        $this->makePost( 'A [list_sermons] and [sermon_images] — neither is [sermons].' );

        $stats = ( new PrevalenceCounter() )->run();

        // Persisted (write-gated) and readable back.
        $this->assertSame( $stats, PrevalenceCounter::stats() );

        $this->assertSame( 3, $stats['podcasts']['published'] );
        $this->assertSame( 2, $stats['podcasts']['with_scope'] );
        $this->assertSame( 1, $stats['podcasts']['single_scoped'] );
        $this->assertTrue( $stats['podcasts']['multi_podcast'] );

        // Only the two real [sermons] embeds; the decoy line is excluded.
        $this->assertSame( 2, $stats['shortcodes']['posts'] );
        $this->assertSame( 1, $stats['shortcodes']['with_attributes'] );
        $this->assertSame( 2, $stats['shortcodes']['max_attributes'] );
        $this->assertSame( 1, $stats['shortcodes']['attribute_histogram']['per_page'] );
        $this->assertSame( 1, $stats['shortcodes']['attribute_histogram']['orderby'] );

        // Page-builder block ships zero findings in this fixture (no builder pages).
        $this->assertSame( 0, $stats['page_builder']['pages'] );
    }

    public function test_shortcode_attribute_density_parses_real_atts(): void {
        $this->makePost( '[sermons per_page="5" orderby="date" filter_by="series"]' );
        $this->makePost( '[sermons_sm per_page="3"]' );

        $shortcodes = ( new PrevalenceCounter() )->tally()['shortcodes'];

        $this->assertSame( 2, $shortcodes['posts'] );
        $this->assertSame( 3, $shortcodes['max_attributes'] );
        $this->assertSame( 4, $shortcodes['total_attributes'] ); // 3 + 1
        $this->assertSame( 2, $shortcodes['attribute_histogram']['per_page'] );
        $this->assertSame( 1, $shortcodes['attribute_histogram']['orderby'] );
        $this->assertSame( 1, $shortcodes['attribute_histogram']['filter_by'] );
    }

    public function test_page_builder_findings_are_reused_from_the_scanner(): void {
        // An Elementor page embedding a legacy sermon shortcode → floor + meta-shortcode findings.
        $id = $this->makePost( '' );
        update_post_meta(
            $id,
            '_elementor_data',
            '[{"id":"a1","widgetType":"shortcode","settings":{"shortcode":"[sermons per_page=5]"}}]'
        );

        $builder = ( new PrevalenceCounter() )->tally()['page_builder'];

        $this->assertGreaterThanOrEqual( 1, $builder['pages'] );
        $this->assertGreaterThanOrEqual( 1, $builder['builder_embedded'] );
        $this->assertGreaterThanOrEqual( 1, $builder['shortcode_in_meta'] );
    }

    public function test_tally_and_report_perform_zero_writes(): void {
        $this->makePodcast( array( ID::TAX_SERIES => array( 10 ) ) );
        $this->makePost( '[sermons per_page="5"]' );

        // Seed the option once (the only legitimate write) so renderReport has data to read.
        ( new PrevalenceCounter() )->run();

        $before = $this->dbFingerprint();

        $counter = new PrevalenceCounter();
        $counter->tally();
        $html = $counter->renderReport();

        $after = $this->dbFingerprint();

        $this->assertSame( $before, $after, 'tally() + renderReport() must not write to the database' );
        $this->assertStringContainsString( 'sermonator-prevalence', $html );
    }

    /**
     * A row-count + content checksum of the post/meta/option tables, used to prove a code path
     * wrote nothing. Excludes the prevalence option itself is unnecessary — we snapshot AFTER the
     * seeding run, so a no-write path leaves the fingerprint identical.
     *
     * @return array<string,string>
     */
    private function dbFingerprint(): array {
        global $wpdb;

        return array(
            'posts'    => (string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(ID, post_modified_gmt ORDER BY ID)), '')) FROM {$wpdb->posts}" ),
            'postmeta' => (string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(meta_id, meta_value ORDER BY meta_id)), '')) FROM {$wpdb->postmeta}" ),
            'options'  => (string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(option_name, option_value ORDER BY option_id)), '')) FROM {$wpdb->options}" ),
        );
    }
}
