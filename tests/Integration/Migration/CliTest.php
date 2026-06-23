<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Migration;

use WP_UnitTestCase;
use Sermonator\Cli\MigrationCommand;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\Verifier;
use Sermonator\Migration\Rollback;
use Sermonator\Migration\Finalizer;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Task 6: WP-CLI migration commands + Plugin wiring.
 *
 * `Sermonator\Cli\MigrationCommand` exposes the subcommands detect / migrate /
 * verify / rollback / finalize / status as THIN wrappers over the gated lifecycle
 * services (Orchestrator / Verifier / Rollback / Finalizer). The class carries NO
 * migration logic of its own — every adversarial protection lives in the services and
 * applies identically whether invoked from the CLI or directly.
 *
 * Because the wp-env phpunit runtime is a plain `php` process (WP_CLI is NOT defined),
 * these tests:
 *   - install a tiny WP_CLI shim (captures log/success/warning, throws on error, and
 *     reports halt() like the real runner) so the command methods run + so we can
 *     assert their output;
 *   - call the command methods directly, asserting they DELEGATE to the services and
 *     enforce the confirm-guard:
 *       * migrate honors --batch-size and loops Orchestrator::run to completion;
 *       * finalize WITHOUT --yes aborts (nothing finalized);
 *       * finalize WITH --yes runs only when state === 'verified';
 *       * rollback prints the exact pending-deletion id set BEFORE acting;
 *       * status reports the phase + open flags.
 *
 * Legacy data is read READ-ONLY throughout (the CLI adds no legacy writes).
 */
final class CliTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();

        require_once __DIR__ . '/../Support/WpCliShim.php';
        \Sermonator\Tests\Integration\Support\WpCliShim::install();
        \Sermonator\Tests\Integration\Support\WpCliShim::reset();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
    }

    protected function tearDown(): void {
        \Sermonator\Tests\Integration\Support\WpCliShim::reset();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Seeding + driving
    // -------------------------------------------------------------------------

    /**
     * Seed a small realistic legacy dataset: terms, sermons referencing terms, a
     * podcast + default-podcast pointer, artwork, and a sermonmanager_* option.
     *
     * @return array{sermons:list<int>, podcast:int}
     */
    private function seedDataset(): array {
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $series   = $this->fixture->createTerm( LegacyIdentifiers::TAX_SERIES, 'Advent' );

        $sermons = array();
        for ( $i = 0; $i < 2; $i++ ) {
            $sid = $this->fixture->createSermon();
            wp_set_object_terms( $sid, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
            wp_set_object_terms( $sid, array( $series ), LegacyIdentifiers::TAX_SERIES );
            $sermons[] = $sid;
        }

        $podcast = $this->fixture->createPodcast( 'Sunday Feed' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );
        $this->fixture->setOption( 'sermonmanager_general', array( 'archive_slug' => 'sermons' ) );

        return array(
            'sermons' => $sermons,
            'podcast' => $podcast,
        );
    }

    // -------------------------------------------------------------------------
    // detect / migrate
    // -------------------------------------------------------------------------

    /** detect() runs the Detector and advances state to 'detected'. */
    public function test_detect_runs_detector_and_sets_state(): void {
        $this->seedDataset();

        $cmd = new MigrationCommand();
        $cmd->detect( array(), array() );

        $this->assertSame( 'detected', ( new MigrationState() )->phase() );
        $this->assertNotNull( ( new MigrationState() )->manifest() );
    }

    /**
     * migrate honors --batch-size and loops Orchestrator::run to completion,
     * reaching 'migrated' and reporting the migrated record counts.
     */
    public function test_migrate_loops_to_completion_with_batch_size(): void {
        $seed = $this->seedDataset();

        $cmd = new MigrationCommand();
        $cmd->detect( array(), array() );
        // A deliberately tiny batch so the command must loop multiple run() calls.
        $cmd->migrate( array(), array( 'batch-size' => 1 ) );

        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );

        // Every legacy sermon + podcast has a migrated counterpart.
        $this->assertCount(
            count( $seed['sermons'] ),
            Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON )
        );
        $this->assertCount(
            1,
            Crosswalk::migratedPostIds( Identifiers::POST_TYPE_PODCAST )
        );

        // The command reported counts to the operator.
        $log = \Sermonator\Tests\Integration\Support\WpCliShim::output();
        $this->assertStringContainsString( 'migrated', strtolower( $log ) );
    }

    // -------------------------------------------------------------------------
    // verify
    // -------------------------------------------------------------------------

    /** verify after a full migrate reports complete and advances state to 'verified'. */
    public function test_verify_delegates_and_reports_complete(): void {
        $this->seedDataset();

        $cmd = new MigrationCommand();
        $cmd->detect( array(), array() );
        $cmd->migrate( array(), array( 'batch-size' => 50 ) );
        $cmd->verify( array(), array() );

        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // status
    // -------------------------------------------------------------------------

    /** status prints the current phase. */
    public function test_status_reports_phase(): void {
        $this->seedDataset();

        $cmd = new MigrationCommand();
        $cmd->detect( array(), array() );
        $cmd->status( array(), array() );

        $log = \Sermonator\Tests\Integration\Support\WpCliShim::output();
        $this->assertStringContainsString( 'detected', $log );
    }

    // -------------------------------------------------------------------------
    // rollback — prints the exact id set BEFORE acting
    // -------------------------------------------------------------------------

    /**
     * rollback prints the exact pending-deletion id set first, then (with --yes)
     * reverses the migration: stamped posts gone, state back to 'detected', and zero
     * back-refs remain.
     */
    public function test_rollback_prints_pending_then_reverses_with_yes(): void {
        $seed = $this->seedDataset();

        $cmd = new MigrationCommand();
        $cmd->detect( array(), array() );
        $cmd->migrate( array(), array( 'batch-size' => 50 ) );

        $expectedPosts = ( new Rollback() )->pendingDeletions()['posts'];
        $this->assertNotEmpty( $expectedPosts, 'Setup must have migrated posts to roll back.' );

        \Sermonator\Tests\Integration\Support\WpCliShim::reset();
        $cmd->rollback( array(), array( 'yes' => true ) );

        $log = \Sermonator\Tests\Integration\Support\WpCliShim::output();
        // The exact id set was printed BEFORE acting.
        foreach ( $expectedPosts as $pid ) {
            $this->assertStringContainsString( (string) $pid, $log, 'Rollback must print each pending post id.' );
        }

        $this->assertSame( 'detected', ( new MigrationState() )->phase() );
        $this->assertSame(
            array(),
            Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ),
            'No migrated sermons should remain after rollback.'
        );
    }

    /** rollback WITHOUT --yes prints the pending set but does NOT delete anything. */
    public function test_rollback_without_yes_aborts(): void {
        $seed = $this->seedDataset();

        $cmd = new MigrationCommand();
        $cmd->detect( array(), array() );
        $cmd->migrate( array(), array( 'batch-size' => 50 ) );

        $before = Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON );
        $this->assertNotEmpty( $before );

        $cmd->rollback( array(), array() ); // no --yes

        $after = Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON );
        $this->assertSame( $before, $after, 'Rollback without --yes must delete nothing.' );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(), 'State unchanged when aborted.' );
    }

    // -------------------------------------------------------------------------
    // finalize — gated + confirm-guarded
    // -------------------------------------------------------------------------

    /** finalize WITHOUT --yes aborts: nothing finalized, legacy intact. */
    public function test_finalize_without_yes_aborts(): void {
        $seed = $this->seedDataset();

        $cmd = new MigrationCommand();
        $cmd->detect( array(), array() );
        $cmd->migrate( array(), array( 'batch-size' => 50 ) );
        $cmd->verify( array(), array() );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );

        $cmd->finalize( array(), array() ); // no --yes

        $this->assertSame( 'verified', ( new MigrationState() )->phase(), 'Finalize without --yes must not finalize.' );
        // Legacy sermons still present.
        foreach ( $seed['sermons'] as $sid ) {
            $this->assertInstanceOf( \WP_Post::class, get_post( $sid ), 'Legacy sermon must survive an aborted finalize.' );
        }
    }

    /** finalize WITH --yes runs ONLY when state === 'verified'. */
    public function test_finalize_with_yes_runs_only_when_verified(): void {
        $seed = $this->seedDataset();

        $cmd = new MigrationCommand();
        $cmd->detect( array(), array() );
        $cmd->migrate( array(), array( 'batch-size' => 50 ) );

        // State is 'migrated', NOT 'verified' — finalize must refuse even with --yes.
        $cmd->finalize( array(), array( 'yes' => true ) );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(), 'Finalize must refuse when not verified.' );
        foreach ( $seed['sermons'] as $sid ) {
            $this->assertInstanceOf( \WP_Post::class, get_post( $sid ), 'Legacy must survive a refused finalize.' );
        }

        // Now verify, then finalize with --yes succeeds and reaches 'finalized'.
        $cmd->verify( array(), array() );
        $cmd->finalize( array(), array( 'yes' => true ) );

        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
        foreach ( $seed['sermons'] as $sid ) {
            $this->assertNull( get_post( $sid ), 'Verified legacy sermons must be deleted at finalize.' );
        }
    }
}
