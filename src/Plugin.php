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
    }
}
