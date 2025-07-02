<?php
/**
 * Bootstrap file.
 *
 * @package SMP
 */

/*
 * Plugin Name: Sermon Manager Pro
 * Plugin URI: https://sermonmanager.pro/
 * Description: This plugin is an addon to Sermon Manager, the most popular sermon plugin for WordPress. Enjoy templates, page builder support, page assignment and much more.
 * Version: 2.0.13
 * Author: WP for Church Author
 * URI: https://www.wpforchurch.com/
 *
 * Text Domain: sermon-manager-pro
 *
 * Requires at least: 4.5
 * Tested up to: 5.9.3
 */

// All files must be PHP 5.3 compatible!
defined( 'ABSPATH' ) or exit;

/**
 * Loads Sermon Manager Pro after other plugins.
 */
function smp_load() {
	define( 'SMP_VERSION', preg_match( '/^.*Version: (.*)$/m', file_get_contents( __FILE__ ), $version ) ? trim( $version[1] ) : 'N/A' );
	define( 'SMP_SM_VERSION', '2.15.15' ); // Minimum required Sermon Manager version.

	define( 'SMP__FILE__', __FILE__ );
	define( 'SMP_BASENAME', plugin_basename( SMP__FILE__ ) );
	define( 'SMP_PATH', dirname( SMP__FILE__ ) . '/' ); // With a trailing slash.
	define( 'SMP_PATH_INCLUDES', SMP_PATH . 'includes/' ); // With a trailing slash.
	define( 'SMP_PATH_SHORTCODES', SMP_PATH_INCLUDES . 'shortcodes/' ); // With a trailing slash.

	define( 'SMP_URL', plugins_url( '/', SMP__FILE__ ) ); // With a trailing slash.
	define( 'SMP_ASSETS_URL', SMP_URL . 'assets/' );

	if ( ! version_compare( PHP_VERSION, '5.3', '>=' ) ) {
		add_action( 'admin_notices', 'smp_fail_php' );
	} elseif ( ! class_exists( 'SermonManager' ) || ( defined( 'SM_VERSION' ) && version_compare( SM_VERSION, SMP_SM_VERSION, '<' ) ) ) {
		add_action( 'admin_notices', 'smp_fail_sm' );
	} else {
		require_once SMP_PATH . 'includes/plugin.php';
	}

	/**
	 * Sermon Manager Pro admin notice for minimum PHP version.
	 *
	 * @since 2.0.4
	 */
	function smp_fail_php() {
		/* translators: %s: PHP version */
		$message      = sprintf( __( '<strong>Sermon Manager Pro</strong> requires PHP version <strong>%s</strong> or greater. Plugin will not load.', 'sermon-manager-pro' ), '5.3' );
		$html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
		echo wp_kses_post( $html_message );
	}

	/**
	 * Sermon Manager Pro admin notice for missing/wrong Sermon Manager version.
	 */
	function smp_fail_sm() {
		if ( defined( 'SM_VERSION' ) && version_compare( SM_VERSION, SMP_SM_VERSION, '<' ) ) {
			/* translators: %s: Sermon Manager version */
			$message = sprintf( __( '<strong>Sermon Manager Pro</strong> requires <strong>Sermon Manager</strong> version <strong>%s</strong> or greater. Plugin will not load.', 'sermon-manager-pro' ), SMP_SM_VERSION );
		} else {
			$message = __( '<strong>Sermon Manager Pro</strong> requires <strong>Sermon Manager</strong> to be installed. Plugin will not load.', 'sermon-manager-pro' );
		}
		$html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
		echo wp_kses_post( $html_message );
	}
}

add_action( 'plugins_loaded', 'smp_load' );

function additional_support() {
	add_post_type_support( 'wpfc_sermon', 'editor' );
   }
   // Hooking up our function to theme setup
   add_action( 'init', 'additional_support' );

add_action( 'init', 'my_run_only_once' );
function my_run_only_once() {
    // if ( did_action( 'init' ) >= 2 )
    //     return;

    // if( ! get_option('run_divi_option_reset_once') ) {

        delete_option( 'et_bfb_settings' );

        // update_option( 'run_divi_option_reset_once', true );
    // }
}




/* plugin Updater EDD */

define( 'EDD_CHURCH_STORE_URL_SMPRO', 'https://my.wpforchurch.com' ); 
define( 'PRODUCT_ID_ON_CHURCH_SMPRO', 53 ); 
define( 'PRODUCT_NAME_ON_CHURCH_SMPRO', 'Sermon Manager Pro 2.0' );

add_filter( 'wp_insert_post_data' , 'filter_post_data' , '99', 2 );
function filter_post_data( $data , $postarr ) {
    
    if (is_null($data['post_content'])) {
    	$data['post_content']=' ';
    }
    if (is_null($data['post_content_filtered'])) {
    	$data['post_content_filtered']=' ';
    }
    return $data;
}


register_deactivation_hook( __FILE__, 'remove_template_directory' );
function remove_template_directory(){
	rrmdir(WP_CONTENT_DIR.'/data');
}

function rrmdir($dir) {
	error_log("fumction called");
   if (is_dir($dir)) { 
     $objects = scandir($dir); 
     foreach ($objects as $object) { 
       if ($object != "." && $object != "..") { 
         if (filetype($dir."/".$object) == "dir") rrmdir($dir."/".$object); else unlink($dir."/".$object); 
       } 
     } 
     reset($objects); 
     rmdir($dir); 
   } 
}

// if( !class_exists( 'SMPRO_SL_Plugin_Updater' ) ) {
// 	include( dirname( __FILE__ ) . '/SMPRO_SL_Plugin_Updater.php' );
// }

// function smpro_plugin_updater_fun() {
// 	$license_key = trim( get_option( 'sermonmanager_license_key' ) );

// 	$edd_updater = new SMPRO_SL_Plugin_Updater( EDD_CHURCH_STORE_URL_SMPRO, __FILE__,
// 		array(
// 			'version' => '2.0.4',                    // current version number
// 			'license' => $license_key,             // license key (used get_option above to retrieve from DB)
// 			'item_id' => PRODUCT_ID_ON_CHURCH_SMPRO,       // ID of the product
// 			'author'  => 'Easy Digital Downloads', // author of this plugin
// 			'beta'    => false,
// 		)
// 	);

// }
// add_action( 'admin_init', 'smpro_plugin_updater_fun', 0 );


// function smpro_license_register_option() {
// 	// creates our settings in the options table
// 	register_setting('smpro_license', 'sermonmanager_license_key', 'smpro_sanitize_license' );
// }
// add_action('admin_init', 'smpro_license_register_option');

// function smpro_sanitize_license( $new ) {
// 	$old = get_option( 'sermonmanager_license_key' );
// 	if( $old && $old != $new ) {
// 		delete_option( 'smpro_license_status' ); // new license has been entered, so must reactivate
// 	}
// 	return $new;
// }