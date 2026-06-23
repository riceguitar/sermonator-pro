<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Migration\Detector;
use Sermonator\Migration\MigrationState;
use Sermonator\Schema\Identifiers;

/**
 * Admin banner that prompts an operator to run the migration wizard when legacy Sermon
 * Manager data is present and the migration has not yet been finalized. Read-only — it
 * only decides whether to show, and links to the wizard. It never appears on the wizard
 * page itself (the wizard IS the call to action there), and only for users who can run
 * the migration.
 */
final class LegacyDataNotice {
    private Detector $detector;
    private MigrationState $state;

    public function __construct( ?Detector $detector = null, ?MigrationState $state = null ) {
        $this->detector = $detector ?? new Detector();
        $this->state    = $state ?? new MigrationState();
    }

    public function hook(): void {
        add_action( 'admin_notices', array( $this, 'maybeRender' ) );
    }

    /** Decide whether the notice applies in the current admin context. */
    public function shouldShow(): bool {
        if ( ! current_user_can( MigrationController::CAPABILITY ) ) {
            return false;
        }
        // Already at the terminal phase → nothing to prompt.
        if ( $this->state->phase() === 'finalized' ) {
            return false;
        }
        // Don't nag on the wizard page itself.
        if ( $this->onWizardPage() ) {
            return false;
        }
        return $this->detector->hasLegacyData();
    }

    /** Echo the notice when it applies. */
    public function maybeRender(): void {
        if ( ! $this->shouldShow() ) {
            return;
        }
        echo $this->renderNotice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderNotice escapes its content.
    }

    /** Build the notice markup (escaped). Returned for testability. */
    public function renderNotice(): string {
        $url   = admin_url( 'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON . '&page=' . MigrationWizard::PAGE_SLUG );
        $phase = $this->state->phase();

        $message = $phase === 'none'
            ? __( 'Sermonator detected existing Sermon Manager data. Run the guided migration to import it — your original data is left untouched until you finalize.', 'sermonator' )
            : __( 'A Sermonator migration is in progress. Resume the guided migration to continue.', 'sermonator' );

        return '<div class="notice notice-info"><p>'
            . esc_html( $message )
            . ' <a href="' . esc_url( $url ) . '" class="button button-primary" style="margin-left:8px;">'
            . esc_html__( 'Open migration wizard', 'sermonator' )
            . '</a></p></div>';
    }

    /** Whether the current admin screen is the wizard page. */
    private function onWizardPage(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.
        return isset( $_GET['page'] ) && MigrationWizard::PAGE_SLUG === sanitize_key( (string) wp_unslash( $_GET['page'] ) );
    }
}
