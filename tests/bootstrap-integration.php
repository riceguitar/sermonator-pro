<?php

declare(strict_types=1);

$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function (): void {
    require dirname( __DIR__ ) . '/sermonator.php';
} );

require_once $wp_tests_dir . '/includes/bootstrap.php';
