<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\MigrationWizard;
use Sermonator\Admin\MigrationController;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\Verifier;
use Sermonator\Migration\Finalizer;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Plan C: the wizard page registers under the Sermons menu (capability-gated) and renders
 * the step matching the current phase. Pure presentation — these tests assert the
 * registration cap and the per-phase markup, not data mutation (that is the controller's).
 */
final class MigrationWizardTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );

        $admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
        get_user_by( 'id', $admin )->add_cap( MigrationController::CAPABILITY );
        wp_set_current_user( $admin );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function seedDataset(): array {
        $preacher = $this->fixture->createTerm( LegacyIdentifiers::TAX_PREACHER, 'Pastor Bob' );
        $sermons  = array();
        for ( $i = 0; $i < 2; $i++ ) {
            $sid = $this->fixture->createSermon();
            wp_set_object_terms( $sid, array( $preacher ), LegacyIdentifiers::TAX_PREACHER );
            $sermons[] = $sid;
        }
        $podcast = $this->fixture->createPodcast( 'Sunday Feed' );
        $this->fixture->setOption( LegacyIdentifiers::OPTION_DEFAULT_PODCAST, $podcast );
        $this->fixture->setOption( 'sermonmanager_general', array( 'archive_slug' => 'sermons' ) );
        return array( 'sermons' => $sermons, 'podcast' => $podcast );
    }

    private function migrateToMigrated(): void {
        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $p = $orch->run( 50 );
            $guard++;
        } while ( $p['phase'] !== 'migrated' && $guard < 200 );
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function test_register_page_adds_submenu_under_sermons_for_capable_user(): void {
        global $submenu;
        $submenu = array();

        ( new MigrationWizard() )->registerPage();

        $parent = 'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON;
        $this->assertArrayHasKey( $parent, $submenu, 'Wizard must register under the Sermons menu.' );
        $slugs = array_map( static fn( $item ) => $item[2], $submenu[ $parent ] );
        $this->assertContains( MigrationWizard::PAGE_SLUG, $slugs, 'The wizard page slug must be registered.' );
    }

    public function test_register_page_is_capability_gated(): void {
        global $submenu;
        $submenu = array();

        // A subscriber lacks manage_sermonator_settings → add_submenu_page registers no
        // accessible item for them.
        $subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );

        ( new MigrationWizard() )->registerPage();

        $parent = 'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON;
        $slugs  = array();
        if ( isset( $submenu[ $parent ] ) ) {
            $slugs = array_map( static fn( $item ) => $item[2], $submenu[ $parent ] );
        }
        $this->assertNotContains( MigrationWizard::PAGE_SLUG, $slugs, 'A non-capable user must not get the wizard submenu.' );
    }

    // -------------------------------------------------------------------------
    // Per-phase render
    // -------------------------------------------------------------------------

    public function test_render_shows_detect_step_when_legacy_present(): void {
        $this->seedDataset();
        $html = ( new MigrationWizard() )->renderContent();
        $this->assertStringContainsString( 'data-sermonator-action="detect"', $html );
        $this->assertStringContainsString( 'Scan legacy data', $html );
    }

    public function test_render_shows_nothing_to_migrate_when_no_legacy(): void {
        $html = ( new MigrationWizard() )->renderContent();
        $this->assertStringContainsString( 'nothing to migrate', strtolower( $html ) );
        $this->assertStringNotContainsString( 'data-sermonator-action="detect"', $html );
    }

    public function test_render_shows_review_with_counts_when_detected(): void {
        $this->seedDataset();
        ( new Orchestrator() )->detect();

        $html = ( new MigrationWizard() )->renderContent();
        $this->assertStringContainsString( 'data-sermonator-action="run"', $html );
        $this->assertStringContainsString( 'Start migration', $html );
        $this->assertStringContainsString( 'sermonator-migrate-counts', $html );
    }

    public function test_render_shows_verified_step_with_blast_radius_and_confirm(): void {
        $this->seedDataset();
        $this->migrateToMigrated();
        ( new Verifier() )->verify( ( new MigrationState() )->manifest() );
        $this->assertSame( 'verified', ( new MigrationState() )->phase() );

        $html = ( new MigrationWizard() )->renderContent();
        $this->assertStringContainsString( 'sermonator-confirm-finalize', $html, 'Verified step needs a finalize confirm.' );
        $this->assertStringContainsString( 'data-sermonator-action="finalize"', $html );
        $this->assertStringContainsString( 'data-sermonator-action="rollback"', $html );
        $this->assertStringContainsString( 'IRREVERSIBLE', $html );
    }

    public function test_render_shows_finalized_step_after_finalize(): void {
        $this->seedDataset();
        $this->migrateToMigrated();
        ( new Verifier() )->verify( ( new MigrationState() )->manifest() );
        ( new Finalizer() )->run( true );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );

        $html = ( new MigrationWizard() )->renderContent();
        $this->assertStringContainsString( 'finalized', strtolower( $html ) );
        $this->assertStringNotContainsString( 'data-sermonator-action="finalize"', $html, 'No actions remain once finalized.' );
        $this->assertStringNotContainsString( 'data-sermonator-action="rollback"', $html );
    }
}
