<?php

declare(strict_types=1);

$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

require_once $wp_tests_dir . '/includes/functions.php';

// Worktree source directory: modified source files for TDD.
$worktree_src = dirname( __DIR__ ) . '/src';

// Override the Sermonator\ namespace to load from THIS worktree's src/ directory.
// The composer autoloader (loaded later by sermonator.php from the main repo) maps
// Sermonator\ to the MAIN repo's src/. We prepend our autoloader so the worktree's
// modified source files take precedence during TDD testing.
spl_autoload_register( static function ( string $class ) use ( $worktree_src ): void {
    if ( ! str_starts_with( $class, 'Sermonator\\' ) ) {
        return;
    }
    // Resolve the namespace path under our worktree src/.
    $relative = substr( $class, strlen( 'Sermonator\\' ) );
    $path     = $worktree_src . '/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $path ) ) {
        require_once $path;
    }
}, true, true ); // prepend=true so this runs BEFORE the composer autoloader

// Load the MAIN repo's sermonator.php (which has vendor/autoload.php and registers
// all infrastructure). The prepended autoloader above already handles any Sermonator\
// class resolution from the worktree, so the main-repo autoloader's src/ mapping
// simply becomes a fallback for classes we have NOT modified.
tests_add_filter( 'muplugins_loaded', static function (): void {
    // Main repo path: 11 levels up from .claude/worktrees/agent-X/ brings us back.
    $main_plugin = '/var/www/html/wp-content/plugins/sermonator-pro/sermonator.php';
    require $main_plugin;
} );

require_once $wp_tests_dir . '/includes/bootstrap.php';
