<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Bible;

use WP_UnitTestCase;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Schema\Identifiers as ID;

/**
 * Integration coverage for {@see CoverageAudit}, driving real `get_post_meta` /
 * `get_option` / `WP_Query` (no Brain Monkey). NOT run in this environment (no
 * Docker / wp-env) — authored to run under wp-env later.
 *
 * Exercises three things the unit test cannot: the real published-corpus query
 * (default `queryPublishedSermons`, so drafts/private are excluded), the option
 * round-trip through the live options API, and the Site Health filter wiring
 * (registerSiteHealthTest invoked through `site_status_tests` reading the persisted
 * rollup without recomputing).
 */
final class CoverageAuditTest extends WP_UnitTestCase {
    /** @param array<string,mixed> $meta */
    private function sermon( string $status, array $meta ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => $status,
            'post_title'  => 'Scripture Sermon',
        ) );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $id, $key, $value );
        }
        return $id;
    }

    /** @param list<array<string,mixed>> $refs */
    private function envelope( array $refs ): string {
        return (string) wp_json_encode( array( 'v' => 1, 'refs' => $refs ) );
    }

    public function test_run_counts_only_published_sermons_and_persists_rollup(): void {
        // Published, resolves via stored envelope.
        $this->sermon( 'publish', array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null ),
            ) ),
        ) );
        // Published, resolves via live parse (no envelope).
        $this->sermon( 'publish', array( ID::META_BIBLE_PASSAGE => 'Romans 8:28' ) );
        // Published, non-empty passage that parses to zero refs.
        $this->sermon( 'publish', array( ID::META_BIBLE_PASSAGE => 'Welcome and announcements' ) );
        // Published, no passage.
        $this->sermon( 'publish', array() );
        // DRAFT — must be excluded from the corpus entirely.
        $this->sermon( 'draft', array( ID::META_BIBLE_PASSAGE => 'Genesis 1:1' ) );

        $stats = ( new CoverageAudit() )->run();

        $this->assertSame( 4, $stats['total'], 'draft is excluded' );
        $this->assertSame( 3, $stats['with_passage'] );
        $this->assertSame( 2, $stats['resolved'] );
        $this->assertSame(
            array( 'resolved' => 2, 'withheld_low_confidence' => 0, 'parse_fail' => 1, 'empty' => 1 ),
            $stats['breakdown']
        );

        // Persisted to the option, and a pure read returns the same rollup.
        $this->assertSame( $stats, get_option( ID::OPTION_BIBLE_STATS ) );
        $this->assertSame( $stats, CoverageAudit::stats() );
    }

    /**
     * Production-wiring guard: the previous test calls `$audit->hook()` itself, so it
     * stays green even if NOTHING in production instantiates CoverageAudit. This test
     * asserts the LIVE filter — registered by `Plugin::boot()` during plugin load (see
     * tests/bootstrap-integration.php), with no manual `hook()` here — so a regression
     * that drops the wiring from Plugin::boot() (the Task-13 "ships dark" failure) fails
     * loudly. Booting is idempotent (static guard), so the wiring is already in place.
     */
    public function test_plugin_boot_registers_the_site_health_test_in_production(): void {
        // No $audit->hook() here on purpose — this must be wired by Plugin::boot().
        \Sermonator\Plugin::boot();

        $tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );

        $this->assertArrayHasKey(
            CoverageAudit::SITE_HEALTH_TEST,
            $tests['direct'],
            'Plugin::boot() must wire CoverageAudit so the Site Health test is registered in production.'
        );
        $this->assertIsCallable( $tests['direct'][ CoverageAudit::SITE_HEALTH_TEST ]['test'] );
    }

    public function test_site_health_filter_registers_a_reader_that_uses_persisted_stats(): void {
        $audit = new CoverageAudit();
        $audit->hook();

        $tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
        $this->assertArrayHasKey( CoverageAudit::SITE_HEALTH_TEST, $tests['direct'] );

        // Seed a known rollup, then invoke the registered test callback (pure read).
        update_option( ID::OPTION_BIBLE_STATS, array(
            'generated_at'   => time(),
            'total'          => 4,
            'with_passage'   => 4,
            'resolved'       => 4,
            'parse_coverage' => 100.0,
            'breakdown'      => array( 'resolved' => 4, 'withheld_low_confidence' => 0, 'parse_fail' => 0, 'empty' => 0 ),
        ) );

        $result = call_user_func( $tests['direct'][ CoverageAudit::SITE_HEALTH_TEST ]['test'] );

        $this->assertSame( 'good', $result['status'] );
        $this->assertSame( CoverageAudit::SITE_HEALTH_TEST, $result['test'] );
        foreach ( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ) as $key ) {
            $this->assertArrayHasKey( $key, $result );
        }
    }

    // ----------------------------------------------------------------------------------
    // Phase 3b — inline corpus-gate report (Task 14)
    //
    // NOT run in this environment (no Docker / wp-env) — authored to run under wp-env.
    // These exercise the read-only inline instrument against the REAL options/meta/query
    // stack: the withheld-by-reason tally shape, the no-write-on-report boundary, the
    // run()-folds-inline persistence path, and the source-versification heterogeneity
    // signal. Chapters are NOT vendored under wp-env, so inline-eligible refs land in
    // `cold-unwarmed` rather than `inline_eligible` — which is exactly the honest
    // pre-warm ground truth (we assert SHAPE + signals, not warmed eligibility).
    // ----------------------------------------------------------------------------------

    /**
     * @param string $confidence
     * @param string $src
     *
     * @return array<string,mixed>
     */
    private function inlineRef( string $book, int $chapter, ?int $verseStart, string $confidence = 'exact', string $src = 'ESV' ): array {
        return array(
            'bookUSFM'         => $book,
            'chapterStart'     => $chapter,
            'verseStart'       => $verseStart,
            'verseEnd'         => null,
            'chapterEnd'       => null,
            'confidence'       => $confidence,
            'srcVersification' => $src,
        );
    }

    public function test_inline_report_shape_and_no_write_on_report(): void {
        delete_option( ID::OPTION_BIBLE_STATS );

        $this->sermon( 'publish', array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                $this->inlineRef( 'JHN', 3, 16 ),                 // inline-shaped exact
                $this->inlineRef( 'JHN', 3, 16, 'probable' ),     // low-confidence
                $this->inlineRef( 'JHN', 3, null ),               // not-inline-eligible
            ) ),
        ) );

        $report = ( new CoverageAudit() )->inlineReport();

        // Withheld tally carries every reason key (always-present partition buckets).
        foreach ( array(
            'not-inline-eligible',
            'low-confidence',
            'translation-ineligible',
            'src-versification-unsupported',
            'src-heterogeneous',
            'unmodeled-versification-pair',
            'versification-divergent',
            'cold-unwarmed',
            'verse-out-of-range',
        ) as $reason ) {
            $this->assertArrayHasKey( $reason, $report['withheld'] );
        }

        $this->assertSame( 3, $report['refs_total'] );
        $this->assertSame( 1, $report['withheld']['low-confidence'] );
        $this->assertSame( 1, $report['withheld']['not-inline-eligible'] );
        // Partition holds.
        $this->assertSame(
            $report['refs_total'],
            $report['inline_eligible'] + array_sum( $report['withheld'] )
        );
        $this->assertArrayHasKey( 'unmodeled_pair_wrong_text', $report );

        // NO WRITE-ON-REPORT: the read-only instrument never persists the rollup option.
        $this->assertFalse( get_option( ID::OPTION_BIBLE_STATS, false ) );
    }

    public function test_run_persists_inline_subreport_in_the_rollup(): void {
        $this->sermon( 'publish', array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array( $this->inlineRef( 'JHN', 3, 16 ) ) ),
        ) );

        $stats = ( new CoverageAudit() )->run();

        $this->assertArrayHasKey( 'inline', $stats );
        $this->assertSame( $stats['inline'], get_option( ID::OPTION_BIBLE_STATS )['inline'] );
        $this->assertSame( 1, $stats['inline']['refs_total'] );
        $this->assertArrayHasKey( 'inline_eligible_pct', $stats['inline'] );
    }

    public function test_inline_report_flags_source_versification_heterogeneity(): void {
        delete_option( ID::OPTION_BIBLE_STATS );

        // Dominant eng-protestant tradition + a single foreign-tradition ref.
        $this->sermon( 'publish', array(
            ID::META_BIBLE_PASSAGE => 'mixed corpus',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                $this->inlineRef( 'JHN', 3, 16, 'exact', 'ESV' ),
                $this->inlineRef( 'GEN', 1, 1, 'exact', 'NIV' ),
                $this->inlineRef( 'ROM', 8, 28, 'exact', 'RVR1960' ), // foreign minority
            ) ),
        ) );

        $report = ( new CoverageAudit() )->inlineReport();

        $this->assertTrue( $report['heterogeneous'] );
        $this->assertSame( 'eng-protestant', $report['dominant_family'] );
        $this->assertSame( 1, $report['withheld']['src-heterogeneous'] );
        $this->assertSame( 0, $report['withheld']['src-versification-unsupported'] );
    }
}
