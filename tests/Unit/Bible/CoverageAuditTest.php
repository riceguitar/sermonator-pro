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
}
