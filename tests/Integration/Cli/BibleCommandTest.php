<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Cli;

use WP_UnitTestCase;
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
        update_option( ID::OPTION_BIBLE_LINK_VERSION, 'ESV' );

        $this->command = new BibleCommand();
    }

    protected function tearDown(): void {
        WpCliShim::reset();
        delete_option( ID::OPTION_MIGRATION_STATE );
        delete_option( ID::OPTION_BIBLE_REFS_BACKFILL_LOG );
        delete_option( ID::OPTION_BIBLE_CACHE_GEN );
        delete_option( ID::OPTION_BIBLE_LINK_VERSION );
        parent::tearDown();
    }

    private function sermon( string $passage ): int {
        $id = (int) self::factory()->post->create( array( 'post_type' => ID::POST_TYPE_SERMON ) );
        update_post_meta( $id, ID::META_BIBLE_PASSAGE, $passage );
        return $id;
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
}
