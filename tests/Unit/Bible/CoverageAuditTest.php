<?php

declare(strict_types=1);

namespace Sermonator\Tests\Unit\Bible;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Schema\Identifiers as ID;

/**
 * Unit coverage for {@see CoverageAudit}: the parse-coverage math (with the post
 * query mocked) and the native Site Health status test array shape.
 *
 * The four buckets PARTITION the corpus (resolved + withheld + parse_fail + empty
 * == total), with_passage excludes `empty`, and parse_coverage = resolved /
 * with_passage. The Site Health "test" callback is a PURE READER of the precomputed
 * option — it must never recompute (no write-on-GET).
 */
final class CoverageAuditTest extends TestCase {
    /** @var array<int,array<string,mixed>> postId => (metaKey => value) */
    private array $meta = array();

    /** @var array<string,mixed> option store */
    private array $options = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->meta    = array();
        $this->options = array();

        Functions\when( '__' )->returnArg( 1 );
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
            return $this->meta[ (int) $id ][ $key ] ?? '';
        } );
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            return $this->options[ $name ] ?? $default;
        } );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string,mixed> $meta */
    private function seed( int $id, array $meta ): void {
        $this->meta[ $id ] = $meta;
    }

    /** @param list<array<string,mixed>> $refs */
    private function envelope( array $refs ): string {
        return (string) json_encode( array( 'v' => 1, 'refs' => $refs ) );
    }

    /** @param list<int> $ids */
    private function auditOver( array $ids ): CoverageAudit {
        return new CoverageAudit( static function () use ( $ids ): array {
            return $ids;
        } );
    }

    public function test_empty_corpus_is_all_zero(): void {
        $stats = $this->auditOver( array() )->run();

        $this->assertSame( 0, $stats['total'] );
        $this->assertSame( 0, $stats['with_passage'] );
        $this->assertSame( 0, $stats['resolved'] );
        $this->assertSame( 0.0, $stats['parse_coverage'] );
        $this->assertSame(
            array( 'resolved' => 0, 'withheld_low_confidence' => 0, 'parse_fail' => 0, 'empty' => 0 ),
            $stats['breakdown']
        );
    }

    public function test_buckets_partition_the_corpus(): void {
        // 1: resolved via stored envelope (in-canon, structurally valid).
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                array( 'bookUSFM' => 'JHN', 'chapterStart' => 3, 'verseStart' => 16, 'verseEnd' => null, 'chapterEnd' => null ),
            ) ),
        ) );
        // 2: resolved via LIVE parse (no envelope) — un-backfilled ground truth.
        $this->seed( 2, array( ID::META_BIBLE_PASSAGE => 'Romans 8:28' ) );
        // 3: withheld — refs exist but none clear the validator (out-of-canon import).
        $this->seed( 3, array(
            ID::META_BIBLE_PASSAGE => 'Hezekiah 4:5',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                array( 'bookUSFM' => 'XYZ', 'chapterStart' => 4, 'verseStart' => 5, 'verseEnd' => null, 'chapterEnd' => null ),
            ) ),
        ) );
        // 4: parse_fail — non-empty passage, zero refs extractable.
        $this->seed( 4, array( ID::META_BIBLE_PASSAGE => 'Welcome and announcements' ) );
        // 5: empty — no passage label at all.
        $this->seed( 5, array( ID::META_BIBLE_PASSAGE => '   ' ) );

        $stats = $this->auditOver( array( 1, 2, 3, 4, 5 ) )->run();

        $this->assertSame( 5, $stats['total'] );
        $this->assertSame(
            array( 'resolved' => 2, 'withheld_low_confidence' => 1, 'parse_fail' => 1, 'empty' => 1 ),
            $stats['breakdown']
        );

        // with_passage == total - empty; buckets sum back to total.
        $this->assertSame( 4, $stats['with_passage'] );
        $b = $stats['breakdown'];
        $this->assertSame(
            $stats['total'],
            $b['resolved'] + $b['withheld_low_confidence'] + $b['parse_fail'] + $b['empty']
        );

        // parse_coverage = resolved / with_passage * 100 = 2/4 = 50.0.
        $this->assertSame( 2, $stats['resolved'] );
        $this->assertSame( 50.0, $stats['parse_coverage'] );
    }

    public function test_full_coverage_is_one_hundred_percent(): void {
        $this->seed( 1, array( ID::META_BIBLE_PASSAGE => 'John 3:16' ) );
        $this->seed( 2, array( ID::META_BIBLE_PASSAGE => 'Genesis 1:1' ) );

        $stats = $this->auditOver( array( 1, 2 ) )->run();

        $this->assertSame( 2, $stats['with_passage'] );
        $this->assertSame( 2, $stats['resolved'] );
        $this->assertSame( 100.0, $stats['parse_coverage'] );
    }

    public function test_run_persists_the_rollup_to_the_stats_option(): void {
        $this->seed( 1, array( ID::META_BIBLE_PASSAGE => 'John 3:16' ) );

        $returned = $this->auditOver( array( 1 ) )->run();

        $this->assertArrayHasKey( ID::OPTION_BIBLE_STATS, $this->options );
        $this->assertSame( $returned, $this->options[ ID::OPTION_BIBLE_STATS ] );
        $this->assertIsInt( $returned['generated_at'] );
    }

    public function test_envelope_with_only_invalid_refs_is_withheld_not_resolved(): void {
        // chapterStart 0 is structurally invalid even though the book is in-canon.
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'John',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                array( 'bookUSFM' => 'JHN', 'chapterStart' => 0, 'verseStart' => null, 'verseEnd' => null, 'chapterEnd' => null ),
            ) ),
        ) );

        $stats = $this->auditOver( array( 1 ) )->run();

        $this->assertSame( 0, $stats['breakdown']['resolved'] );
        $this->assertSame( 1, $stats['breakdown']['withheld_low_confidence'] );
        $this->assertSame( 0.0, $stats['parse_coverage'] );
    }

    public function test_hook_wires_site_health_cron_init_and_on_save(): void {
        Functions\expect( 'add_filter' )
            ->once()
            ->with( 'site_status_tests', \Mockery::type( 'array' ) );
        Functions\expect( 'add_action' )
            ->once()
            ->with( CoverageAudit::EVENT_HOOK, \Mockery::type( 'array' ) );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'init', \Mockery::type( 'array' ) );
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'save_post_' . ID::POST_TYPE_SERMON, \Mockery::type( 'array' ), 99, 1 );

        ( new CoverageAudit() )->hook();
        $this->addToAssertionCount( 1 );
    }

    public function test_register_site_health_test_adds_a_direct_test(): void {
        $audit = $this->auditOver( array() );

        $tests = $audit->registerSiteHealthTest( array( 'direct' => array(), 'async' => array() ) );

        $this->assertArrayHasKey( CoverageAudit::SITE_HEALTH_TEST, $tests['direct'] );
        $entry = $tests['direct'][ CoverageAudit::SITE_HEALTH_TEST ];
        $this->assertArrayHasKey( 'label', $entry );
        $this->assertSame( array( $audit, 'siteHealthResult' ), $entry['test'] );
        // It must not clobber the async bucket.
        $this->assertArrayHasKey( 'async', $tests );
    }

    public function test_register_site_health_test_tolerates_a_non_array_input(): void {
        $tests = $this->auditOver( array() )->registerSiteHealthTest( null );

        $this->assertArrayHasKey( 'direct', $tests );
        $this->assertArrayHasKey( CoverageAudit::SITE_HEALTH_TEST, $tests['direct'] );
    }

    public function test_site_health_result_shape_is_complete(): void {
        $this->options[ ID::OPTION_BIBLE_STATS ] = array(
            'generated_at'   => 123,
            'total'          => 10,
            'with_passage'   => 8,
            'resolved'       => 8,
            'parse_coverage' => 100.0,
            'breakdown'      => array( 'resolved' => 8, 'withheld_low_confidence' => 0, 'parse_fail' => 0, 'empty' => 2 ),
        );

        $result = $this->auditOver( array() )->siteHealthResult();

        foreach ( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ) as $key ) {
            $this->assertArrayHasKey( $key, $result );
        }
        $this->assertArrayHasKey( 'label', $result['badge'] );
        $this->assertArrayHasKey( 'color', $result['badge'] );
        $this->assertSame( CoverageAudit::SITE_HEALTH_TEST, $result['test'] );
    }

    public function test_site_health_is_green_at_or_above_threshold(): void {
        $this->options[ ID::OPTION_BIBLE_STATS ] = array(
            'with_passage'   => 100,
            'resolved'       => 95,
            'parse_coverage' => 95.0,
            'breakdown'      => array( 'resolved' => 95, 'withheld_low_confidence' => 3, 'parse_fail' => 2, 'empty' => 0 ),
        );

        $this->assertSame( 'good', $this->auditOver( array() )->siteHealthResult()['status'] );
    }

    public function test_site_health_is_amber_below_threshold(): void {
        $this->options[ ID::OPTION_BIBLE_STATS ] = array(
            'with_passage'   => 100,
            'resolved'       => 50,
            'parse_coverage' => 50.0,
            'breakdown'      => array( 'resolved' => 50, 'withheld_low_confidence' => 30, 'parse_fail' => 20, 'empty' => 0 ),
        );

        $this->assertSame( 'recommended', $this->auditOver( array() )->siteHealthResult()['status'] );
    }

    public function test_site_health_is_green_when_no_passages_exist(): void {
        $this->options[ ID::OPTION_BIBLE_STATS ] = array(
            'with_passage'   => 0,
            'resolved'       => 0,
            'parse_coverage' => 0.0,
            'breakdown'      => array( 'resolved' => 0, 'withheld_low_confidence' => 0, 'parse_fail' => 0, 'empty' => 5 ),
        );

        $this->assertSame( 'good', $this->auditOver( array() )->siteHealthResult()['status'] );
    }

    public function test_site_health_reports_not_computed_before_first_run(): void {
        // No OPTION_BIBLE_STATS seeded.
        $result = $this->auditOver( array() )->siteHealthResult();

        $this->assertSame( 'recommended', $result['status'] );
        $this->assertSame( CoverageAudit::SITE_HEALTH_TEST, $result['test'] );
    }

    public function test_site_health_result_does_not_recompute_or_write(): void {
        // Stats already present; calling the read must NOT touch the option store.
        $this->options[ ID::OPTION_BIBLE_STATS ] = array(
            'with_passage'   => 1,
            'resolved'       => 1,
            'parse_coverage' => 100.0,
            'breakdown'      => array( 'resolved' => 1, 'withheld_low_confidence' => 0, 'parse_fail' => 0, 'empty' => 0 ),
        );
        $before = $this->options[ ID::OPTION_BIBLE_STATS ];

        $this->auditOver( array() )->siteHealthResult();

        $this->assertSame( $before, $this->options[ ID::OPTION_BIBLE_STATS ] );
    }

    // ----------------------------------------------------------------------------------
    // Phase 3b — inline corpus-gate report (Task 14)
    // ----------------------------------------------------------------------------------

    /**
     * Build an inline-shape ref envelope row. `srcVersification` defaults to ESV
     * (eng-protestant) and `confidence` to `exact` (clears the default floor) so the
     * row is inline-eligible unless a test deliberately diverges one field.
     *
     * @return array<string,mixed>
     */
    private function ref(
        string $book,
        int $chapter,
        ?int $verseStart,
        ?int $verseEnd = null,
        string $confidence = 'exact',
        string $src = 'ESV',
        ?int $chapterEnd = null
    ): array {
        return array(
            'bookUSFM'         => $book,
            'chapterStart'     => $chapter,
            'verseStart'       => $verseStart,
            'verseEnd'         => $verseEnd,
            'chapterEnd'       => $chapterEnd,
            'confidence'       => $confidence,
            'srcVersification' => $src,
        );
    }

    /**
     * A chapter resolver that returns a fixed normalized chapter carrying verses
     * $present (each with one renderable text node), for ANY (translation,book,chapter).
     *
     * @param list<int> $present
     *
     * @return callable(string,string,int,bool):(array<int,mixed>|null)
     */
    private function chapterWith( array $present ): callable {
        return static function ( $translation, $book, $chapter, $warm ) use ( $present ): array {
            $verses = array();
            foreach ( $present as $n ) {
                $verses[] = array( 'number' => $n, 'nodes' => array( array( 'type' => 'text', 'text' => 'word' ) ) );
            }
            return $verses;
        };
    }

    /**
     * @param list<int>                                                       $ids
     * @param callable(string,string,int,bool):(array<int,mixed>|null)|null   $chapterResolver
     */
    private function inlineAuditOver( array $ids, ?callable $chapterResolver = null ): CoverageAudit {
        return new CoverageAudit(
            static function () use ( $ids ): array {
                return $ids;
            },
            $chapterResolver ?? $this->chapterWith( array() )
        );
    }

    public function test_inline_report_eligible_ref_passes_all_layers(): void {
        // Stored envelope: a single exact, ESV-sourced, present John 3:16.
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array( $this->ref( 'JHN', 3, 16 ) ) ),
        ) );

        $report = $this->inlineAuditOver( array( 1 ), $this->chapterWith( array( 16, 17, 18 ) ) )->inlineReport();

        $this->assertSame( 1, $report['refs_total'] );
        $this->assertSame( 1, $report['inline_eligible'] );
        $this->assertSame( 100.0, $report['inline_eligible_pct'] );
        $this->assertSame( 0, array_sum( $report['withheld'] ) );
        $this->assertSame( 'ENGWEBP', $report['target'] );
        $this->assertFalse( $report['heterogeneous'] );
    }

    public function test_inline_report_withheld_keys_are_always_present(): void {
        $report = $this->inlineAuditOver( array() )->inlineReport();

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
            $this->assertArrayHasKey( $reason, $report['withheld'], "missing withheld reason: $reason" );
            $this->assertSame( 0, $report['withheld'][ $reason ] );
        }
        $this->assertSame( 0, $report['refs_total'] );
        $this->assertSame( 0.0, $report['inline_eligible_pct'] );
    }

    public function test_inline_report_tallies_each_reason_and_partitions(): void {
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'corpus',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                // eligible (present in the resolver-supplied chapter).
                $this->ref( 'JHN', 3, 16 ),
                // L1 not-inline-eligible: chapter-only (no verse).
                $this->ref( 'JHN', 3, null ),
                // L2 low-confidence: probable < exact floor.
                $this->ref( 'JHN', 3, 16, null, 'probable' ),
                // L4 src-versification-unsupported: foreign source, homogeneous w/ others? No —
                // here the corpus is dominantly eng-protestant, so a lone foreign ref is
                // heterogeneous, not unsupported. Use it for the heterogeneous bucket instead.
                $this->ref( 'JHN', 3, 16, null, 'exact', 'RVR1960' ),
                // L7 versification-divergent: ROM 16 is an enumerated divergent zone.
                $this->ref( 'ROM', 16, 1 ),
                // L9 verse-out-of-range: asks 16-20 but chapter only carries 16-18.
                $this->ref( 'JHN', 3, 16, 20 ),
            ) ),
        ) );

        $report = $this->inlineAuditOver( array( 1 ), $this->chapterWith( array( 16, 17, 18 ) ) )->inlineReport();

        $this->assertSame( 6, $report['refs_total'] );
        $this->assertSame( 1, $report['inline_eligible'] );
        $this->assertSame( 1, $report['withheld']['not-inline-eligible'] );
        $this->assertSame( 1, $report['withheld']['low-confidence'] );
        $this->assertSame( 1, $report['withheld']['src-heterogeneous'] );
        $this->assertSame( 1, $report['withheld']['versification-divergent'] );
        $this->assertSame( 1, $report['withheld']['verse-out-of-range'] );

        // Partition: eligible + every withheld bucket == refs_total.
        $this->assertSame(
            $report['refs_total'],
            $report['inline_eligible'] + array_sum( $report['withheld'] )
        );
        $this->assertTrue( $report['heterogeneous'] );
    }

    public function test_inline_eligible_pct_math(): void {
        // 2 eligible of 4 in-canon/valid refs == 50.0%.
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'corpus',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                $this->ref( 'JHN', 3, 16 ),               // eligible
                $this->ref( 'GEN', 1, 1 ),                // eligible
                $this->ref( 'JHN', 3, 16, null, 'probable' ), // low-confidence
                $this->ref( 'JHN', 3, null ),             // not-inline-eligible
            ) ),
        ) );

        $report = $this->inlineAuditOver( array( 1 ), $this->chapterWith( array( 1, 16 ) ) )->inlineReport();

        $this->assertSame( 4, $report['refs_total'] );
        $this->assertSame( 2, $report['inline_eligible'] );
        $this->assertSame( 50.0, $report['inline_eligible_pct'] );
    }

    public function test_homogeneous_foreign_corpus_is_unsupported_not_heterogeneous(): void {
        // Every ref is a foreign tradition: ONE bucket → not heterogeneous → plain L4.
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'corpus',
            ID::META_BIBLE_REFS    => $this->envelope( array(
                $this->ref( 'JHN', 3, 16, null, 'exact', 'RVR1960' ),
                $this->ref( 'GEN', 1, 1, null, 'exact', 'RVR1960' ),
            ) ),
        ) );

        $report = $this->inlineAuditOver( array( 1 ), $this->chapterWith( array( 1, 16 ) ) )->inlineReport();

        $this->assertSame( 2, $report['withheld']['src-versification-unsupported'] );
        $this->assertSame( 0, $report['withheld']['src-heterogeneous'] );
        $this->assertFalse( $report['heterogeneous'] );
        $this->assertSame( array( 'unknown' => 2 ), $report['families'] );
        $this->assertSame( 'unknown', $report['dominant_family'] );
    }

    public function test_cold_unwarmed_when_chapter_absent_offline(): void {
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array( $this->ref( 'JHN', 3, 16 ) ) ),
        ) );

        // Resolver returns null (chapter not vendored/warmed to disk yet).
        $cold = static function ( $t, $b, $c, $w ): ?array {
            return null;
        };

        $report = $this->inlineAuditOver( array( 1 ), $cold )->inlineReport();

        $this->assertSame( 1, $report['withheld']['cold-unwarmed'] );
        $this->assertSame( 0, $report['inline_eligible'] );
    }

    public function test_render_context_chapter_read_is_offline_only(): void {
        // The audit's L8 read MUST pass warmContext=false (no network on the audit path).
        $seenWarm = null;
        $spy = static function ( $t, $b, $c, $warm ) use ( &$seenWarm ): array {
            $seenWarm = $warm;
            return array( array( 'number' => 16, 'nodes' => array( array( 'type' => 'text', 'text' => 'x' ) ) ) );
        };
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array( $this->ref( 'JHN', 3, 16 ) ) ),
        ) );

        $this->inlineAuditOver( array( 1 ), $spy )->inlineReport();

        $this->assertFalse( $seenWarm, 'L8 chapter read must be offline-only (warmContext=false)' );
    }

    public function test_inline_report_writes_nothing(): void {
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array( $this->ref( 'JHN', 3, 16 ) ) ),
        ) );

        $this->inlineAuditOver( array( 1 ), $this->chapterWith( array( 16 ) ) )->inlineReport();

        // No-write-on-report: the read-only instrument must not persist the stats option.
        $this->assertArrayNotHasKey( ID::OPTION_BIBLE_STATS, $this->options );
    }

    public function test_run_folds_inline_subreport_into_persisted_stats(): void {
        $this->seed( 1, array(
            ID::META_BIBLE_PASSAGE => 'John 3:16',
            ID::META_BIBLE_REFS    => $this->envelope( array( $this->ref( 'JHN', 3, 16 ) ) ),
        ) );

        $stats = $this->inlineAuditOver( array( 1 ), $this->chapterWith( array( 16 ) ) )->run();

        $this->assertArrayHasKey( 'inline', $stats );
        $this->assertSame( $stats, $this->options[ ID::OPTION_BIBLE_STATS ] );
        $this->assertSame( 1, $stats['inline']['refs_total'] );
        $this->assertSame( 1, $stats['inline']['inline_eligible'] );
        $this->assertArrayHasKey( 'unmodeled_pair_wrong_text', $stats['inline'] );
    }

    public function test_site_health_surfaces_inline_eligible_and_heterogeneity_warning(): void {
        $this->options[ ID::OPTION_BIBLE_STATS ] = array(
            'with_passage'   => 4,
            'resolved'       => 4,
            'parse_coverage' => 100.0,
            'breakdown'      => array( 'resolved' => 4, 'withheld_low_confidence' => 0, 'parse_fail' => 0, 'empty' => 0 ),
            'inline'         => array(
                'refs_total'                => 4,
                'inline_eligible'           => 2,
                'inline_eligible_pct'       => 50.0,
                'withheld'                  => array(),
                'unmodeled_pair_wrong_text' => 0,
                'families'                  => array( 'eng-protestant' => 3, 'unknown' => 1 ),
                'dominant_family'           => 'eng-protestant',
                'heterogeneous'             => true,
            ),
        );

        $result = $this->auditOver( array() )->siteHealthResult();

        $this->assertStringContainsString( 'inline-eligible', $result['description'] );
        $this->assertStringContainsString( 'mixes more than one source-versification tradition', $result['description'] );
    }

    public function test_site_health_omits_inline_paragraph_for_pre_3b_rollup(): void {
        // A rollup persisted before the 3b extension has no `inline` key: back-compat.
        $this->options[ ID::OPTION_BIBLE_STATS ] = array(
            'with_passage'   => 1,
            'resolved'       => 1,
            'parse_coverage' => 100.0,
            'breakdown'      => array( 'resolved' => 1, 'withheld_low_confidence' => 0, 'parse_fail' => 0, 'empty' => 0 ),
        );

        $result = $this->auditOver( array() )->siteHealthResult();

        $this->assertStringNotContainsString( 'inline-eligible', $result['description'] );
    }
}
