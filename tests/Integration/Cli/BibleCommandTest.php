<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Cli;

use WP_UnitTestCase;
use Sermonator\Bible\CoverageAudit;
use Sermonator\Cli\BibleCommand;
use Sermonator\Schema\Identifiers as ID;
use Sermonator\Tests\Integration\Support\WpCliShim;

/**
 * Task 15: WP-CLI bible commands + Plugin wiring.
 *
 * {@see BibleCommand} exposes `backfill` (dry-run default / --write / --rollback /
 * --limit) and `flush` as THIN wrappers over the gated services. The class carries NO
 * logic of its own — every guardrail (native-only, fill-missing, never-overwrite-
 * authoring, exact reverse, idempotency, migration-gating) lives in
 * {@see \Sermonator\Migration\BibleRefsBackfill} and applies identically here.
 *
 * Because the wp-env phpunit runtime is a plain `php` process (WP_CLI is NOT defined),
 * these tests install a tiny WP_CLI shim that captures log/success/warning output and
 * throws on error, then call the command methods directly and assert their effects on
 * real post-meta, the TAX_BOOK term graph, the reverse log, and the cache-gen option.
 *
 * NOTE: written but NOT run in this environment (no Docker / wp-env).
 */
final class BibleCommandTest extends WP_UnitTestCase {
    private BibleCommand $command;

    protected function setUp(): void {
        parent::setUp();

        require_once __DIR__ . '/../Support/WpCliShim.php';
        WpCliShim::install();
        WpCliShim::reset();

        delete_option( ID::OPTION_MIGRATION_STATE );
        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );
        delete_option( ID::OPTION_BIBLE_CACHE_GEN );
        delete_option( ID::OPTION_BIBLE_INLINE_ATTESTATION );
        delete_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG );
        delete_option( ID::OPTION_BIBLE_STATS );
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );

        $this->command = new BibleCommand();
    }

    protected function tearDown(): void {
        WpCliShim::reset();
        delete_option( ID::OPTION_MIGRATION_STATE );
        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );
        delete_option( ID::OPTION_BIBLE_CACHE_GEN );
        delete_option( ID::OPTION_BIBLE_INLINE_ATTESTATION );
        delete_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG );
        delete_option( ID::OPTION_BIBLE_STATS );
        delete_option( ID::OPTION_BIBLE_LINK_VERSION );
        parent::tearDown();
    }

    private function sermon( string $passage ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, $passage );
        return $id;
    }

    /**
     * Seed a sermon carrying a pre-built structured-refs envelope (the shape
     * {@see CoverageAudit} reads), so the inline preview classifies real refs without
     * depending on the backfill parser. Mirrors CoveragePromotionPreviewTest's seam.
     *
     * @param list<array<string,mixed>> $refs
     */
    private function sermonWithRefs( array $refs ): int {
        $id = (int) self::factory()->post->create( array(
            'post_type'   => ID::POST_TYPE_SERMON,
            'post_status' => 'publish',
        ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, 'passage' );
        update_post_meta( $id, ID::META_BIBLE_REFS, (string) wp_json_encode( array( 'v' => 1, 'refs' => $refs ) ) );
        return $id;
    }

    /** @param array<string,mixed> $overrides */
    private function ref( int $verseStart, string $raw, string $confidence, array $overrides = array() ): array {
        return array_merge( array(
            'bookUSFM'         => 'JHN',
            'chapterStart'     => 3,
            'verseStart'       => $verseStart,
            'verseEnd'         => null,
            'chapterEnd'       => null,
            'raw'              => $raw,
            'confidence'       => $confidence,
            'srcVersification' => 'ESV',
        ), $overrides );
    }

    /**
     * A chapter resolver reporting every requested chapter as carrying verses 1..40, so a
     * promoted ref clears L8/L9 and becomes inline-eligible (and thus sample-able) without
     * vendored text — the same trick CoveragePromotionPreviewTest uses.
     *
     * @return callable(string,string,int,bool):array<int,mixed>
     */
    private function warmChapter(): callable {
        return static function ( $t, $b, $c, $w ): array {
            $verses = array();
            for ( $n = 1; $n <= 40; $n++ ) {
                $verses[] = array( 'number' => $n, 'nodes' => array( array( 'type' => 'text', 'text' => 'word' ) ) );
            }
            return $verses;
        };
    }

    // -------------------------------------------------------------------------
    // backfill
    // -------------------------------------------------------------------------

    public function test_backfill_default_is_dry_run_and_writes_nothing(): void {
        $id = $this->sermon( 'John 3:16' );

        $this->command->backfill( array(), array() );

        // Dry-run: no envelope persisted, reverse log untouched.
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
        $this->assertSame( array(), get_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, array() ) );

        $output = WpCliShim::output();
        $this->assertStringContainsString( '1 candidate', $output );
        $this->assertStringContainsString( 'Dry run complete', $output );
    }

    public function test_backfill_write_persists_envelope_and_terms(): void {
        $id = $this->sermon( 'John 3:16; Romans 8:28' );

        $this->command->backfill( array(), array( 'write' => true ) );

        $this->assertNotSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
        $log = get_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, array() );
        $this->assertArrayHasKey( $id, $log );

        $this->assertStringContainsString( 'Success: Backfilled 1 sermon', WpCliShim::output() );

        // #1 data preservation: the legacy passage label is never mutated.
        $this->assertSame( 'John 3:16; Romans 8:28', get_post_meta( $id, ID::META_BIBLE_PASSAGE, true ) );
    }

    public function test_backfill_honors_limit(): void {
        $this->sermon( 'John 3:16' );
        $this->sermon( 'Romans 8:28' );

        $this->command->backfill( array(), array( 'write' => true, 'limit' => '1' ) );

        // Exactly one of the two candidates was written this run.
        $log = get_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, array() );
        $this->assertCount( 1, $log );
    }

    public function test_backfill_rollback_reverses_a_prior_write(): void {
        $id = $this->sermon( 'John 3:16' );

        $this->command->backfill( array(), array( 'write' => true ) );
        $this->assertNotSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );

        WpCliShim::reset();
        $this->command->backfill( array(), array( 'rollback' => true ) );

        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
        $this->assertSame( array(), get_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG, array() ) );
        $this->assertStringContainsString( 'Success: Reversed 1', WpCliShim::output() );
    }

    public function test_backfill_write_is_gated_during_active_migration(): void {
        $id = $this->sermon( 'John 3:16' );
        // Put a migration in flight so MigrationGuard::editingAllowed() is false.
        update_option( ID::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $this->command->backfill( array(), array( 'write' => true ) );

        // The gate is a warning (a service refusal, not a fatal error) and nothing is written.
        $this->assertSame( '', get_post_meta( $id, ID::META_BIBLE_REFS, true ) );
        $this->assertStringContainsString( 'Warning:', WpCliShim::output() );
    }

    // -------------------------------------------------------------------------
    // flush
    // -------------------------------------------------------------------------

    public function test_flush_bumps_the_cache_generation(): void {
        update_option( ID::OPTION_BIBLE_CACHE_GEN, 4 );

        $this->command->flush( array(), array() );

        $this->assertSame( 5, (int) get_option( ID::OPTION_BIBLE_CACHE_GEN ) );
        $this->assertStringContainsString( 'Success: Flushed bible chapter cache', WpCliShim::output() );
    }

    public function test_flush_from_unset_starts_at_one(): void {
        delete_option( ID::OPTION_BIBLE_CACHE_GEN );

        $this->command->flush( array(), array() );

        $this->assertSame( 1, (int) get_option( ID::OPTION_BIBLE_CACHE_GEN ) );
    }

    // -------------------------------------------------------------------------
    // vendor (no-network wrapper paths: dry-run / refusal / rollback)
    // -------------------------------------------------------------------------

    public function test_vendor_default_is_dry_run_and_writes_nothing(): void {
        // Default ENGWEBP, no --write: a report-only sweep (no network, no disk).
        $this->command->vendor( array(), array() );

        $output = WpCliShim::output();
        $this->assertStringContainsString( 'would be vendored', $output );
        $this->assertStringContainsString( 'Dry run complete', $output );
    }

    public function test_vendor_refuses_non_public_domain_translation(): void {
        $this->command->vendor( array(), array( 'translation' => 'BSB', 'write' => true ) );

        $output = WpCliShim::output();
        $this->assertStringContainsString( 'Warning:', $output );
        $this->assertStringContainsString( 'public-domain', $output );
    }

    public function test_vendor_rollback_with_nothing_vendored_reports_zero(): void {
        $this->command->vendor( array(), array( 'rollback' => true ) );

        $this->assertStringContainsString( 'Success: Removed 0 vendored chapter file(s) for ENGWEBP', WpCliShim::output() );
    }

    public function test_vendor_write_is_gated_during_active_migration(): void {
        update_option( ID::OPTION_MIGRATION_STATE, array( 'phase' => 'migrating' ) );

        $this->command->vendor( array(), array( 'write' => true ) );

        $this->assertStringContainsString( 'Warning:', WpCliShim::output() );
        $this->assertStringContainsString( 'migration is in progress', WpCliShim::output() );
    }

    // -------------------------------------------------------------------------
    // audit --inline (three-floor would-promote preview) + --sample (T-I)
    // -------------------------------------------------------------------------

    public function test_audit_inline_prints_all_three_floors_with_withheld_breakdown(): void {
        // A lone clean probable ref — promotable under strict AND perseg, never exact.
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $this->command->audit( array(), array( 'inline' => true ) );

        $out = WpCliShim::output();
        $this->assertStringContainsString( 'Would-promote preview', $out );
        // All three floor labels appear (perseg is the unique discriminator).
        $this->assertStringContainsString( 'derived-exact-perseg:', $out );
        // Exactly one floor line per floor (each prints the "would-promote" token once).
        $this->assertSame( 3, substr_count( $out, 'would-promote ' ) );
        // The withheld-by-reason breakdown is printed under each floor.
        $this->assertStringContainsString( 'withheld by reason:', $out );
        $this->assertStringContainsString( 'low-confidence', $out );
        // Read-only: no preview sample requested, and nothing persisted.
        $this->assertStringNotContainsString( 'Axis-2 spot-check sample', $out );
        $this->assertFalse( get_option( ID::OPTION_BIBLE_STATS, false ) );
    }

    public function test_audit_inline_writes_nothing(): void {
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        $this->command->audit( array(), array( 'inline' => true ) );

        // The read-only preview never persists the rollup or touches the attestation log.
        $this->assertFalse( get_option( ID::OPTION_BIBLE_STATS, false ) );
        $this->assertSame( array(), get_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG, array() ) );
        $this->assertStringContainsString( 'read-only; no writes', WpCliShim::output() );
    }

    public function test_audit_inline_sample_prints_promoted_refs_with_raw(): void {
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'probable' ) ) );

        // Inject a warm-chapter audit so the promoted ref becomes fully inline-eligible and
        // therefore enters the axis-2 sample (no vendored text under wp-env).
        $command = new BibleCommand( new CoverageAudit( null, $this->warmChapter() ) );

        $command->audit( array(), array( 'inline' => true, 'sample' => '5' ) );

        $out = WpCliShim::output();
        $this->assertStringContainsString( 'Axis-2 spot-check sample', $out );
        // The promoted ref's RAW passage substring + its structural coordinates.
        $this->assertStringContainsString( 'John 3:16', $out );
        $this->assertStringContainsString( '[JHN 3:16]', $out );
        // Still read-only: the spot-check writes nothing (the ack is a separate step).
        $this->assertSame( array(), get_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG, array() ) );
        $this->assertFalse( get_option( ID::OPTION_BIBLE_STATS, false ) );
    }

    // -------------------------------------------------------------------------
    // attest --force (the single, LOGGED override path, T-I)
    // -------------------------------------------------------------------------

    public function test_attest_force_sets_option_and_logs_override(): void {
        $this->assertFalse( (bool) get_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, false ) );

        $this->command->attest( array(), array( 'force' => true ) );

        $this->assertTrue( (bool) get_option( ID::OPTION_BIBLE_INLINE_ATTESTATION ) );

        $log = get_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG, array() );
        $this->assertCount( 1, $log );
        $this->assertSame( 'cli-force', $log[0]['via'] );
        $this->assertFalse( $log[0]['previous'] );
        $this->assertArrayHasKey( 'at', $log[0] );
        $this->assertArrayHasKey( 'user', $log[0] );

        $this->assertStringContainsString( 'Success:', WpCliShim::output() );
        $this->assertStringContainsString( 'logged --force override', WpCliShim::output() );
    }

    public function test_attest_force_bumps_cache_generation(): void {
        update_option( ID::OPTION_BIBLE_CACHE_GEN, 7 );

        $this->command->attest( array(), array( 'force' => true ) );

        $this->assertSame( 8, (int) get_option( ID::OPTION_BIBLE_CACHE_GEN ) );
    }

    public function test_attest_force_bypasses_heterogeneity_hard_disable(): void {
        // A heterogeneous corpus (two distinct source-versification families) would make the
        // Settings checkbox refuse; --force is the deliberate, logged bypass.
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact', array( 'srcVersification' => 'ESV' ) ) ) );
        $this->sermonWithRefs( array( $this->ref( 16, 'John 3:16', 'exact', array( 'srcVersification' => 'RVR1960' ) ) ) );

        $this->command->attest( array(), array( 'force' => true ) );

        $this->assertTrue( (bool) get_option( ID::OPTION_BIBLE_INLINE_ATTESTATION ) );
        $log = get_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG, array() );
        $this->assertCount( 1, $log );
    }

    public function test_attest_force_records_previous_true_state(): void {
        update_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, true );

        $this->command->attest( array(), array( 'force' => true ) );

        $log = get_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG, array() );
        $this->assertCount( 1, $log );
        $this->assertTrue( $log[0]['previous'] );
    }

    public function test_attest_without_force_is_read_only(): void {
        $this->command->attest( array(), array() );

        // No write: attestation stays OFF, the override log stays empty, cache-gen untouched.
        $this->assertFalse( (bool) get_option( ID::OPTION_BIBLE_INLINE_ATTESTATION, false ) );
        $this->assertSame( array(), get_option( ID::OPTION_BIBLE_INLINE_ATTEST_LOG, array() ) );

        $out = WpCliShim::output();
        $this->assertStringContainsString( 'currently OFF', $out );
        $this->assertStringContainsString( 'requires the explicit --force', $out );
    }
}
