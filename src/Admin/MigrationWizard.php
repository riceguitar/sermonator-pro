<?php

declare(strict_types=1);

namespace Sermonator\Admin;

use Sermonator\Migration\Detector;
use Sermonator\Migration\MigrationState;
use Sermonator\Schema\Identifiers;

/**
 * The guided migration wizard admin page (Plan C).
 *
 * Registers a submenu page under the Sermons menu and renders the step that matches the
 * current MigrationState::phase(). Pure PRESENTATION — it reads status/previews from the
 * (thin, gated) MigrationController and never mutates migration state itself; all writes
 * go through the AJAX actions, which the wizard JS drives. The server-rendered markup is
 * meaningful without JS (it shows the current phase + the right next action), and the JS
 * enhances the chunked `migrating` progress and the destructive confirms.
 */
final class MigrationWizard {
    /** Page slug for the submenu + asset handle base. */
    public const PAGE_SLUG = 'sermonator-migrate';

    private MigrationController $controller;
    private MigrationState $state;
    private Detector $detector;

    /** The add_submenu_page hook suffix, captured so asset enqueue is screen-scoped. */
    private string $hookSuffix = '';

    public function __construct(
        ?MigrationController $controller = null,
        ?MigrationState $state = null,
        ?Detector $detector = null
    ) {
        $this->state      = $state ?? new MigrationState();
        $this->controller = $controller ?? new MigrationController( null, $this->state );
        $this->detector   = $detector ?? new Detector();
    }

    /** Register the admin page + its screen-scoped asset enqueue. */
    public function hook(): void {
        add_action( 'admin_menu', array( $this, 'registerPage' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
    }

    /** Register the wizard as a submenu under the Sermons CPT menu. */
    public function registerPage(): void {
        $suffix = add_submenu_page(
            'edit.php?post_type=' . Identifiers::POST_TYPE_SERMON,
            __( 'Migrate from Sermon Manager', 'sermonator' ),
            __( 'Migrate', 'sermonator' ),
            MigrationController::CAPABILITY,
            self::PAGE_SLUG,
            array( $this, 'render' )
        );
        if ( is_string( $suffix ) ) {
            $this->hookSuffix = $suffix;
        }
    }

    /** Enqueue the wizard JS/CSS ONLY on the wizard screen, with the nonce + ajax config. */
    public function enqueueAssets( string $hook ): void {
        if ( $this->hookSuffix === '' || $hook !== $this->hookSuffix ) {
            return;
        }

        $base = defined( 'SERMONATOR_PLUGIN_URL' ) ? (string) SERMONATOR_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/sermonator.php' );
        $ver  = defined( 'SERMONATOR_VERSION' ) ? (string) SERMONATOR_VERSION : '1.0.0';

        wp_enqueue_style( self::PAGE_SLUG, $base . 'assets/migration-wizard.css', array(), $ver );
        wp_enqueue_script( self::PAGE_SLUG, $base . 'assets/migration-wizard.js', array(), $ver, true );
        wp_localize_script(
            self::PAGE_SLUG,
            'SermonatorMigration',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( MigrationController::NONCE_ACTION ),
                'actionPrefix' => 'sermonator_migration_',
            )
        );
    }

    /** Echo the page (capability re-checked — defence-in-depth behind the menu cap). */
    public function render(): void {
        if ( ! current_user_can( MigrationController::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access the migration wizard.', 'sermonator' ) );
        }
        echo $this->renderContent(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderContent escapes every interpolated value.
    }

    /**
     * Build the wizard markup for the current phase. Every dynamic value is escaped here.
     * Returned (not echoed) so it is unit-testable.
     */
    public function renderContent(): string {
        $status = $this->controller->status();
        $phase  = (string) ( $status['phase'] ?? 'none' );
        $counts = is_array( $status['counts'] ?? null ) ? $status['counts'] : array();

        $h  = '<div class="wrap sermonator-migrate" data-phase="' . esc_attr( $phase ) . '">';
        $h .= '<h1>' . esc_html__( 'Migrate from Sermon Manager', 'sermonator' ) . '</h1>';
        $h .= '<p class="description">' . esc_html__( 'A one-time, non-destructive, reversible migration of your existing Sermon Manager data into Sermonator. Your legacy data is left untouched until you finalize.', 'sermonator' ) . '</p>';

        $h .= $this->renderStepNav( $phase );
        $h .= '<div class="sermonator-migrate-step">';

        switch ( $phase ) {
            case 'none':
                $h .= $this->detector->hasLegacyData()
                    ? $this->stepDetect()
                    : $this->stepNothingToMigrate();
                break;
            case 'detected':
                $h .= $this->stepReview( $counts );
                break;
            case 'migrating':
                $h .= $this->stepMigrating( $status );
                break;
            case 'migrated':
                $h .= $this->stepMigrated();
                break;
            case 'verified':
                $h .= $this->stepVerified();
                break;
            case 'finalized':
                $h .= $this->stepFinalized();
                break;
            default:
                $h .= '<p>' . esc_html__( 'Unknown migration state.', 'sermonator' ) . '</p>';
        }

        $h .= '</div>'; // .sermonator-migrate-step
        // A live region the JS updates with progress/flag messages.
        $h .= '<div class="sermonator-migrate-log" role="status" aria-live="polite"></div>';
        $h .= '</div>'; // .wrap
        return $h;
    }

    // -------------------------------------------------------------------------
    // Steps (escaped markup; the JS wires the data-action buttons)
    // -------------------------------------------------------------------------

    private function renderStepNav( string $phase ): string {
        $steps = array(
            'detect'   => __( 'Detect', 'sermonator' ),
            'migrate'  => __( 'Migrate', 'sermonator' ),
            'verify'   => __( 'Verify', 'sermonator' ),
            'finalize' => __( 'Finalize', 'sermonator' ),
        );
        $rank = array(
            'none'      => 'detect',
            'detected'  => 'migrate',
            'migrating' => 'migrate',
            'migrated'  => 'verify',
            'verified'  => 'finalize',
            'finalized' => 'finalize',
        );
        $current = $rank[ $phase ] ?? 'detect';

        $h = '<ol class="sermonator-migrate-nav">';
        foreach ( $steps as $key => $label ) {
            $cls = $key === $current ? ' class="current"' : '';
            $h  .= '<li' . $cls . '>' . esc_html( $label ) . '</li>';
        }
        $h .= '</ol>';
        return $h;
    }

    private function stepNothingToMigrate(): string {
        return '<p>' . esc_html__( 'No legacy Sermon Manager data was found. There is nothing to migrate.', 'sermonator' ) . '</p>';
    }

    private function stepDetect(): string {
        return '<p>' . esc_html__( 'Legacy Sermon Manager data was detected. Run a read-only scan to see exactly what will be migrated. This makes no changes.', 'sermonator' ) . '</p>'
            . $this->button( 'detect', __( 'Scan legacy data', 'sermonator' ), 'button button-primary' );
    }

    /** @param array<string,int> $counts */
    private function stepReview( array $counts ): string {
        $h = '<p>' . esc_html__( 'Scan complete. The following legacy data will be copied forward (your originals are not touched):', 'sermonator' ) . '</p>';
        $h .= '<table class="widefat striped sermonator-migrate-counts"><tbody>';
        foreach ( $counts as $key => $count ) {
            $h .= '<tr><th scope="row">' . esc_html( (string) $key ) . '</th><td>' . esc_html( (string) (int) $count ) . '</td></tr>';
        }
        $h .= '</tbody></table>';
        $h .= $this->button( 'run', __( 'Start migration', 'sermonator' ), 'button button-primary' );
        $h .= ' ' . $this->button( 'detect', __( 'Re-scan', 'sermonator' ), 'button' );
        return $h;
    }

    /** @param array<string,mixed> $status */
    private function stepMigrating( array $status ): string {
        $done      = (int) ( $status['done'] ?? 0 );
        $remaining = (int) ( $status['remaining'] ?? 0 );
        $total     = $done + $remaining;

        $h = '<p>' . esc_html__( 'Migration in progress. This runs in safe, resumable chunks — you can leave and return.', 'sermonator' ) . '</p>';
        $h .= '<div class="sermonator-migrate-progress"><progress max="' . esc_attr( (string) max( 1, $total ) ) . '" value="' . esc_attr( (string) $done ) . '"></progress> ';
        $h .= '<span class="sermonator-migrate-progress-label">' . esc_html( sprintf(
            /* translators: 1: migrated count, 2: total count */
            __( '%1$d of %2$d records migrated', 'sermonator' ),
            $done,
            $total
        ) ) . '</span></div>';
        $h .= $this->button( 'run', __( 'Continue migration', 'sermonator' ), 'button button-primary', true );
        $h .= ' ' . $this->destructiveButton( 'rollback', __( 'Roll back', 'sermonator' ) );
        return $h;
    }

    private function stepMigrated(): string {
        return '<p>' . esc_html__( 'Migration complete. Verify it against your original data before finalizing. Verification is read-only.', 'sermonator' ) . '</p>'
            . $this->button( 'verify', __( 'Verify migration', 'sermonator' ), 'button button-primary' )
            . ' ' . $this->destructiveButton( 'rollback', __( 'Roll back', 'sermonator' ) );
    }

    private function stepVerified(): string {
        $preview = $this->controller->finalizePreview();
        $posts   = count( $preview['posts'] );
        $options = count( $preview['options'] );

        $h = '<p class="sermonator-verified-ok">' . esc_html__( 'Verification passed — every record migrated faithfully. You can finalize, or roll back if you prefer.', 'sermonator' ) . '</p>';
        $h .= '<div class="sermonator-finalize-blast notice notice-warning inline"><p>';
        $h .= esc_html( sprintf(
            /* translators: 1: legacy post count, 2: legacy option count */
            __( 'Finalize is IRREVERSIBLE. It will delete %1$d legacy posts and %2$d legacy options (your migrated copies are kept). Roll back is no longer possible after this.', 'sermonator' ),
            $posts,
            $options
        ) );
        $h .= '</p></div>';
        $h .= '<label class="sermonator-confirm"><input type="checkbox" class="sermonator-confirm-finalize"> ' . esc_html__( 'I understand this permanently deletes the legacy data.', 'sermonator' ) . '</label>';
        $h .= '<p>' . $this->destructiveButton( 'finalize', __( 'Finalize (delete legacy data)', 'sermonator' ), 'sermonator-confirm-finalize' );
        $h .= ' ' . $this->destructiveButton( 'rollback', __( 'Roll back', 'sermonator' ) ) . '</p>';
        return $h;
    }

    private function stepFinalized(): string {
        return '<p class="sermonator-finalized">' . esc_html__( 'Migration finalized. The legacy Sermon Manager data has been removed and Sermonator is now the system of record. This is the point of no return — there is nothing more to do.', 'sermonator' ) . '</p>';
    }

    // -------------------------------------------------------------------------
    // Button helpers (the JS binds [data-sermonator-action])
    // -------------------------------------------------------------------------

    private function button( string $action, string $label, string $class = 'button', bool $loop = false ): string {
        $attrs = ' data-sermonator-action="' . esc_attr( $action ) . '"';
        if ( $loop ) {
            $attrs .= ' data-sermonator-loop="1"';
        }
        return '<button type="button" class="' . esc_attr( $class ) . '"' . $attrs . '>' . esc_html( $label ) . '</button>';
    }

    /** A destructive button: needs confirm. $requiresCheckbox links a confirm checkbox class. */
    private function destructiveButton( string $action, string $label, string $requiresCheckbox = '' ): string {
        $attrs = ' data-sermonator-action="' . esc_attr( $action ) . '" data-sermonator-destructive="1"';
        if ( $requiresCheckbox !== '' ) {
            $attrs .= ' data-sermonator-requires="' . esc_attr( $requiresCheckbox ) . '"';
        }
        return '<button type="button" class="button button-link-delete"' . $attrs . '>' . esc_html( $label ) . '</button>';
    }
}
