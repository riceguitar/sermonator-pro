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

        ( new \Sermonator\Model\Registrar() )->hook();
        ( new \Sermonator\Model\Capabilities() )->grant();

        self::registerCliCommands();
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
    }
}
