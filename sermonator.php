<?php
/**
 * Plugin Name: Sermonator
 * Description: Sermon management for WordPress.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 7.0
 * License: GPL-2.0-or-later
 * Text Domain: sermonator
 *
 * @package Sermonator
 */

defined( 'ABSPATH' ) || exit;

define( 'SERMONATOR_VERSION', '0.1.0' );
define( 'SERMONATOR_FILE', __FILE__ );
define( 'SERMONATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SERMONATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';

\Sermonator\Plugin::boot();

register_activation_hook( __FILE__, static function (): void {
    ( new \Sermonator\Model\Activation() )->activate();
} );
