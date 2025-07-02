<?php
/**
 * Functions used by updater go here.
 *
 * @package SMP/Updating
 */

use SMP\Plugin;

/**
 * Since we have removed WPFC Manager, we will transfer the license key as well. And reactivate the product.
 */
function smp_update_100beta2_move_from_wpfcm() {
	$wpfcm_license_key = get_option( 'sermon-manager-pro-license_key', false );

	if ( $wpfcm_license_key ) {
		update_option( 'sermonmanager_license_key', $wpfcm_license_key );
		delete_option( 'sermon-manager-pro-license_key' );
		Plugin::instance()->licensing_manager->recheck_license( $wpfcm_license_key );
	}

	// Mark it as done, backup way.
	update_option( 'wp_smp_updater_' . __FUNCTION__ . '_done', 1 );
}

/**
 * Convert default podcast data to new podcasting.
 */
function smp_update_100beta8_convert_default_podcast() {
	// Create default podcast if it does not exist.
	if ( ! (bool) get_option( 'wpfc_sm_default_podcast', 0 ) ) {
		if ( ! defined( 'SM_DOING_SAVE' ) ) {
			define( 'SM_DOING_SAVE', true ); // Override our function from trying to save a podcast from editor.
		}

		$id = wp_insert_post( array(
			'post_title'  => 'Default',
			'post_type'   => 'wpfc_sm_podcast',
			'post_status' => 'publish',
		) );

		update_option( 'wpfc_sm_default_podcast', $id );
	}

	$update_settings = array();

	// Hardcoded for the update function.
	$settings_fields = array(
		'title',
		'description',
		'website_link',
		'language',
		'copyright',
		'webmaster_name',
		'webmaster_email',
		'itunes_author',
		'itunes_subtitle',
		'itunes_summary',
		'itunes_owner_name',
		'itunes_owner_email',
		'itunes_cover_image',
		'itunes_sub_category',
		'podtrac',
		'enable_podcast_html_description',
		'enable_podcast_redirection',
		'podcast_redirection_old_url',
		'podcast_redirection_new_url',
		'podcasts_per_page',
		'podcast_url_itunes',
		'podcast_url_android',
		'podcast_url_overcast',
		'podcast_sermon_image_series',
	);

	foreach ( $settings_fields as $id ) {
		$value = SermonManager::getOption( $id );

		$update_settings[ $id ] = $value;
	}

	update_post_meta( (int) get_option( 'wpfc_sm_default_podcast', 0 ), 'sm_podcast_settings', $update_settings );

	// Mark it as done, backup way.
	update_option( 'wp_smp_updater_' . __FUNCTION__ . '_done', 1 );
}
