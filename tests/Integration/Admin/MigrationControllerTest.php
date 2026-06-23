<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\MigrationController;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\Crosswalk;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Plan C: the migration wizard's AJAX controller is a THIN, gated wrapper over the
 * lifecycle services. These tests pin the data-safety invariants the UI must preserve:
 * the capability gate, the nonce gate, thin delegation (state advances only via the
 * service), the destructive confirm-guard, the verified+fresh-drift finalize gate the UI
 * cannot bypass, and advisory-lock parity. The pure core run_action() is driven directly
 * (the wp_ajax JSON shim adds nothing testable).
 */
final class MigrationControllerTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;
    private int $adminId;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();

        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );

        // A user that CAN run the migration (cap added directly so the test is
        // independent of when role grants ran).
        $this->adminId = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
        $admin = get_user_by( 'id', $this->adminId );
        $admin->add_cap( MigrationController::CAPABILITY );
        wp_set_current_user( $this->adminId );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Seeding + driving via the controller
    // -------------------------------------------------------------------------

    /** @return array{sermons:list<int>, podcast:int} */
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

        return array( 'sermons' => $sermons, 'podcast' => $podcast );
    }

    private function nonce(): string {
        return wp_create_nonce( MigrationController::NONCE_ACTION );
    }

    /** A request array with a valid nonce + any extras. */
    private function req( array $extra = array() ): array {
        return array_merge( array( 'nonce' => $this->nonce() ), $extra );
    }

    /** Drive detect → migrate (loop) via the controller until phase 'migrated'. */
    private function migrateViaController( MigrationController $c ): void {
        $c->run_action( 'detect', $this->req() );
        $guard = 0;
        do {
            $res = $c->run_action( 'run', $this->req( array( 'batch_size' => 50 ) ) );
            $phase = $res['data']['status']['phase'] ?? '';
            $guard++;
        } while ( $phase !== 'migrated' && $guard < 200 );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase(), 'Setup must reach migrated via the controller.' );
    }

    // -------------------------------------------------------------------------
    // GATE A: capability
    // -------------------------------------------------------------------------

    public function test_every_action_refuses_without_capability(): void {
        $this->seedDataset();
        $subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );

        $c = new MigrationController();
        foreach ( array( 'detect', 'run', 'verify', 'rollback', 'finalize', 'status' ) as $action ) {
            $res = $c->run_action( $action, $this->req( array( 'confirm' => '1' ) ) );
            $this->assertFalse( $res['ok'], "Action {$action} must refuse without the capability." );
            $this->assertSame( 403, $res['code'] );
        }
        // No state change occurred.
        $this->assertSame( 'none', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // GATE B: nonce
    // -------------------------------------------------------------------------

    public function test_actions_refuse_with_missing_or_invalid_nonce(): void {
        $this->seedDataset();
        $c = new MigrationController();

        $missing = $c->run_action( 'detect', array() );
        $this->assertFalse( $missing['ok'], 'A missing nonce must refuse.' );
        $this->assertSame( 403, $missing['code'] );

        $bad = $c->run_action( 'detect', array( 'nonce' => 'not-a-real-nonce' ) );
        $this->assertFalse( $bad['ok'], 'An invalid nonce must refuse.' );

        // Detect never ran → state untouched.
        $this->assertSame( 'none', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // Thin delegation: state advances via the service exactly like the CLI
    // -------------------------------------------------------------------------

    public function test_detect_run_verify_advance_state_via_controller(): void {
        $this->seedDataset();
        $c = new MigrationController();

        $detect = $c->run_action( 'detect', $this->req() );
        $this->assertTrue( $detect['ok'] );
        $this->assertSame( 'detected', ( new MigrationState() )->phase() );
        $this->assertArrayHasKey( 'counts', $detect['data'] );
        $this->assertSame( 2, (int) $detect['data']['counts']['sermons'] );

        $this->migrateViaController( $c );

        $verify = $c->run_action( 'verify', $this->req() );
        $this->assertTrue( $verify['ok'] );
        $this->assertTrue( $verify['data']['report']['complete'], 'A clean migrate must verify complete.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );
    }

    // -------------------------------------------------------------------------
    // Destructive confirm-guard
    // -------------------------------------------------------------------------

    public function test_rollback_requires_confirm_then_reverses(): void {
        $data = $this->seedDataset();
        $c    = new MigrationController();
        $this->migrateViaController( $c );

        // Without confirm: refuses, deletes nothing.
        $no = $c->run_action( 'rollback', $this->req() );
        $this->assertFalse( $no['ok'], 'Rollback without confirm must refuse.' );
        $this->assertGreaterThan( 0, $this->backRefCount(), 'Nothing deleted on an unconfirmed rollback.' );

        // With confirm: reverses, state → detected, back-refs gone.
        $yes = $c->run_action( 'rollback', $this->req( array( 'confirm' => '1' ) ) );
        $this->assertTrue( $yes['ok'] );
        $this->assertSame( 'detected', ( new MigrationState() )->phase() );
        $this->assertSame( 0, $this->backRefCount(), 'A confirmed rollback removes all back-refs.' );
        $this->assertSame( array(), Crosswalk::migratedPostIds( Identifiers::POST_TYPE_SERMON ) );

        // Legacy intact.
        foreach ( $data['sermons'] as $sid ) {
            $this->assertInstanceOf( \WP_Post::class, get_post( $sid ) );
        }
    }

    public function test_finalize_requires_confirm(): void {
        $this->seedDataset();
        $c = new MigrationController();
        $this->migrateViaController( $c );
        $c->run_action( 'verify', $this->req() );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );

        $no = $c->run_action( 'finalize', $this->req() ); // no confirm
        $this->assertFalse( $no['ok'], 'Finalize without confirm must refuse.' );
        $this->assertSame( 'verified', ( new MigrationState() )->phase(), 'Unconfirmed finalize changes nothing.' );
    }

    public function test_finalize_before_verified_is_refused_by_service_even_with_confirm(): void {
        $data = $this->seedDataset();
        $c    = new MigrationController();
        $this->migrateViaController( $c ); // phase 'migrated', NOT verified

        // The UI cannot bypass the service gate: confirm=1 is necessary, not sufficient.
        $res = $c->run_action( 'finalize', $this->req( array( 'confirm' => '1' ) ) );
        $this->assertTrue( $res['ok'], 'A gated refusal is reported, not an error.' );
        $this->assertIsString( $res['data']['refused'], 'Finalize before verified must be refused by the service.' );
        $this->assertSame( 'migrated', ( new MigrationState() )->phase() );
        // Legacy NOT deleted.
        foreach ( $data['sermons'] as $sid ) {
            $this->assertInstanceOf( \WP_Post::class, get_post( $sid ) );
        }
    }

    public function test_finalize_when_verified_deletes_legacy_and_finalizes(): void {
        $data = $this->seedDataset();
        $c    = new MigrationController();
        $this->migrateViaController( $c );
        $c->run_action( 'verify', $this->req() );

        // Preview matches what will be deleted.
        $preview = $c->finalizePreview();
        $this->assertNotEmpty( $preview['posts'] );

        $res = $c->run_action( 'finalize', $this->req( array( 'confirm' => '1' ) ) );
        $this->assertTrue( $res['ok'] );
        $this->assertNull( $res['data']['refused'], 'A verified, confirmed finalize must run.' );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );
        foreach ( $data['sermons'] as $sid ) {
            $this->assertNull( get_post( $sid ), 'Verified legacy posts deleted at finalize.' );
        }
    }

    // -------------------------------------------------------------------------
    // Advisory-lock parity: destructive actions refuse while a run holds the lock
    // -------------------------------------------------------------------------

    public function test_destructive_actions_refuse_while_advisory_lock_held(): void {
        $this->seedDataset();
        $c = new MigrationController();
        $this->migrateViaController( $c );

        // A separate orchestrator holds the lock (as if a migrate run were live).
        $holder = new Orchestrator();
        $this->assertTrue( $holder->acquireLock() );

        try {
            $rb = $c->run_action( 'rollback', $this->req( array( 'confirm' => '1' ) ) );
            $this->assertFalse( $rb['ok'], 'Rollback must refuse while the lock is held.' );
            $this->assertSame( 423, $rb['code'] );

            $c->run_action( 'verify', $this->req() ); // would advance to verified — but lock is held only for destructive ops
        } finally {
            $holder->releaseLock();
        }

        // Migrated data still intact (rollback refused).
        $this->assertGreaterThan( 0, $this->backRefCount(), 'A lock-blocked rollback deletes nothing.' );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function backRefCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", Crosswalk::LEGACY_POST_ID )
        );
    }
}
