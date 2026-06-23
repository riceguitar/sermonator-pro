<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Admin;

use WP_UnitTestCase;
use Sermonator\Admin\LegacyDataNotice;
use Sermonator\Admin\MigrationController;
use Sermonator\Admin\MigrationWizard;
use Sermonator\Migration\Orchestrator;
use Sermonator\Migration\Verifier;
use Sermonator\Migration\Finalizer;
use Sermonator\Migration\MigrationState;
use Sermonator\Migration\LegacyIdentifiers;
use Sermonator\Schema\Identifiers;
use Sermonator\Tests\Integration\Support\LegacyFixture;

/**
 * Plan C: the legacy-data admin banner is correctly SCOPED — shown only when legacy data
 * is present, the migration is not finalized, the viewer can run it, and we are not
 * already on the wizard page.
 */
final class LegacyDataNoticeTest extends WP_UnitTestCase {
    private LegacyFixture $fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->fixture = new LegacyFixture();
        $this->fixture->registerLegacySchema();
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        unset( $_GET['page'] );

        $admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
        get_user_by( 'id', $admin )->add_cap( MigrationController::CAPABILITY );
        wp_set_current_user( $admin );
    }

    protected function tearDown(): void {
        delete_option( Identifiers::OPTION_MIGRATION_STATE );
        delete_option( Identifiers::OPTION_MIGRATION_PROGRESS );
        delete_option( Identifiers::OPTION_PRE_MIGRATION_BACKUP );
        delete_option( Orchestrator::OPTION_LOCK );
        unset( $_GET['page'] );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function seedLegacy(): void {
        $this->fixture->createSermon();
        $this->fixture->createSermon();
    }

    public function test_shows_when_legacy_present_and_not_finalized(): void {
        $this->seedLegacy();
        $notice = new LegacyDataNotice();
        $this->assertTrue( $notice->shouldShow() );
        $this->assertStringContainsString( MigrationWizard::PAGE_SLUG, $notice->renderNotice(), 'Notice links to the wizard.' );
        $this->assertStringContainsString( 'Open migration wizard', $notice->renderNotice() );
    }

    public function test_hidden_when_no_legacy_data(): void {
        $this->assertFalse( ( new LegacyDataNotice() )->shouldShow(), 'No legacy data → no notice.' );
    }

    public function test_hidden_without_capability(): void {
        $this->seedLegacy();
        $subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );
        $this->assertFalse( ( new LegacyDataNotice() )->shouldShow(), 'A non-capable user sees no notice.' );
    }

    public function test_hidden_on_the_wizard_page_itself(): void {
        $this->seedLegacy();
        $_GET['page'] = MigrationWizard::PAGE_SLUG;
        $this->assertFalse( ( new LegacyDataNotice() )->shouldShow(), 'No nag on the wizard page itself.' );
    }

    public function test_hidden_after_finalize(): void {
        $this->seedLegacy();
        $this->fixture->createPodcast( 'Feed' );

        $orch = new Orchestrator();
        $orch->detect();
        $guard = 0;
        do {
            $p = $orch->run( 50 );
            $guard++;
        } while ( $p['phase'] !== 'migrated' && $guard < 200 );
        ( new Verifier() )->verify( ( new MigrationState() )->manifest() );
        ( new Finalizer() )->run( true );
        $this->assertSame( 'finalized', ( new MigrationState() )->phase() );

        $this->assertFalse( ( new LegacyDataNotice() )->shouldShow(), 'Once finalized, the notice is gone.' );
    }
}
