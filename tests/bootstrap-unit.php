<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

\Brain\Monkey\setUp();
register_shutdown_function( static function (): void {
    \Brain\Monkey\tearDown();
} );
