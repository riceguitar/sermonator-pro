<?php

declare(strict_types=1);

namespace Sermonator;

use Sermonator\Support\VersionGate;

final class Plugin {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        $gate = new VersionGate( PHP_VERSION, get_bloginfo( 'version' ) );
        if ( ! $gate->isSatisfied() ) {
            add_action(
                'admin_notices',
                static function () use ( $gate ): void {
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html( $gate->failureMessage() )
                    );
                }
            );
            return;
        }

        add_action(
            'init',
            static function (): void {
                load_plugin_textdomain(
                    'sermonator',
                    false,
                    dirname( plugin_basename( SERMONATOR_FILE ) ) . '/languages'
                );
            }
        );

        ( new \Sermonator\Model\Registrar() )->hook();
        ( new \Sermonator\Model\Capabilities() )->grant();
        ( new \Sermonator\Admin\Authoring\AuthoringServiceProvider() )->hook();

        self::registerAdmin();
        self::registerFrontend();
        self::registerCliCommands();
    }

    /**
     * Register the read-only front-end display layer (blocks, block template, classic
     * fallback, assets). Booted in ALL contexts: block and block-template registration must
     * be visible to the editor (admin) and REST as well as the front end. The front-end-only
     * pieces self-scope — single_template and wp_enqueue_scripts only fire on front-end
     * requests, and the the_content meta hook is guarded by is_singular()/in_the_loop()/
     * is_main_query(). The layer never writes data.
     */
    private static function registerFrontend(): void {
        ( new \Sermonator\Frontend\FrontendServiceProvider() )->hook();
    }

    /**
     * Register the guided migration wizard (Plan C): the admin page, the thin AJAX
     * controller, and the legacy-data notice. Admin-context only — is_admin() is true
     * for both regular admin screens and admin-ajax.php (where the wp_ajax_* handlers
     * fire), so all three register correctly while never touching front-end requests.
     * The wizard is pure UI over the gated lifecycle services; it adds no migration
     * logic and cannot bypass any data-safety gate.
     */
    private static function registerAdmin(): void {
        if ( ! is_admin() ) {
            return;
        }
        ( new \Sermonator\Admin\MigrationController() )->hook();
        ( new \Sermonator\Admin\MigrationWizard() )->hook();
        ( new \Sermonator\Admin\LegacyDataNotice() )->hook();
    }

    /**
     * Register the WP-CLI migration command, but ONLY under a real WP-CLI runtime.
     * Guarded by defined('WP_CLI') && WP_CLI so a normal web/admin request — and the
     * plain phpunit process — never touches the WP_CLI API. The command itself is a
     * thin wrapper over the gated lifecycle services.
     */
    private static function registerCliCommands(): void {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }
        \WP_CLI::add_command( 'sermonator migration', \Sermonator\Cli\MigrationCommand::class );
        \WP_CLI::add_command( 'sermonator audio', \Sermonator\Cli\AudioCommand::class );
    }
}
