<?php
/**
 * Admin area related stuff.
 *
 * @package SMP
 */

/**
 * Includes additional settings pages for Sermon Manager.
 *
 * @param array $settings The existing settings pages.
 *
 * @return array The modified settings.
 *
 * @since 2.0.4
 */
function smp_add_additional_setting_pages( $settings ) {
	//$settings[] = include __DIR__ . '/settings/class-smp-settings-podcasting.php';
	$settings[] = include __DIR__ . '/settings/class-smp-settings-page-setup.php';
	$settings[] = include __DIR__ . '/settings/class-smp-settings-pro.php';
	$settings[] = include __DIR__ . '/settings/class-smp-settings-licensing.php';
	$settings[] = include __DIR__ . '/settings/class-smp-settings-css.php';
	

	return $settings;
}

add_filter( 'sm_get_settings_pages', 'smp_add_additional_setting_pages' );

/**
 * Removes podcasting tab from settings.
 *
 * @param array $settings The existing settings pages.
 *
 * @return array The modified settings.
 *
 * @since 1.0.0-beta.8
 */
function smp_remove_podcasting_class_from_settings( $settings ) {
	foreach ( $settings as $id => $setting_page ) {
		if ( $setting_page instanceof SM_Settings_Podcast ) {
			unset( $settings[ $id ] );
		}
	}

	return $settings;
}

add_filter( 'sm_get_settings_pages', 'smp_remove_podcasting_class_from_settings', 90 );

/**
 * Removes the Podcasting tab from settings tabs.
 *
 * @param array $tabs Existing tabs.
 *
 * @return array Modified tabs.
 *
 * @since 1.0.0-beta.8
 */
function smp_remove_podcasting_tab_from_settings( $tabs ) {
	//unset($tabs["debug"]);
	//unset($tabs["display"]);
	//unset($tabs["podcast"]);
	//unset($tabs["verse"]);
	//unset($tabs["podcasting"]);
	return $tabs;
}

add_filter( 'sm_settings_tabs_array', 'smp_remove_podcasting_tab_from_settings', 90 );

/**
 * Renders the license key field, with button for checking the validity.
 *
 * @param array  $value             The field data.
 * @param string $option_value      The license key.
 * @param string $description       The description HTML.
 * @param string $tooltip_html      The tooltip HTML.
 * @param array  $custom_attributes The custom attributes.
 *
 * @since 1.0.0-beta.2
 */
function smp_render_licensing_field( $value, $option_value, $description, $tooltip_html, $custom_attributes ) {
	$is_valid      = \SMP\Plugin::instance()->licensing_manager->is_valid();
	$error_message = '';
	?> 
	<tr valign="top">
		<th scope="row" class="titledesc">
			<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			<?php echo $tooltip_html; ?>
		</th>
		<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?> status-<?php echo $error_message ? 'error' : ( $is_valid ? 'valid' : 'invalid' ); ?>">
			<input
					name="<?php echo esc_attr( $value['id'] ); ?>"
					id="<?php echo esc_attr( $value['id'] ); ?>"
					type="text"
					style="<?php echo esc_attr( $value['css'] ); ?>"
					value="<?php echo esc_attr( $option_value ); ?>"
					class="<?php echo esc_attr( $value['class'] ); ?>"
					size="<?php echo esc_attr( $value['size'] ); ?>"
					placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
				<?php echo implode( ' ', $custom_attributes ); ?>
			/>
			<div id="check_license_button" class="button-primary">
				<?php echo __( 'Check License', 'sermon-manager-pro' ); ?>
			</div>
			<span id="license_spinner" class="spinner is-active"
					style="float: none; margin-top: -5px; margin-left: 0;"></span>
			<?php echo $description; ?>
			<div id="license_status">
				<strong><?php echo __( 'License status', 'sermon-manager-for-wordpress' ); ?>:</strong>
				<p id="license_status_response">
					<?php
					if ( $error_message ) {
						echo $error_message;
					} else {
						if ( $is_valid ) {
							echo __( 'Valid.', 'sermon-manager-pro' );
						} else {
							echo __( 'Invalid.', 'sermon-manager-pro' );
						}
					}
					?>
				</p>
			</div>
		</td>
	</tr>
	<?php
}

add_action( 'sm_admin_field_licensing', 'smp_render_licensing_field', 10, 5 );

function smp_render_color_picker_field( $value, $option_value, $description, $tooltip_html, $custom_attributes ) {
	$is_valid      = \SMP\Plugin::instance()->licensing_manager->is_valid();
	$error_message = '';
	?> 
	<tr valign="top">
		<th scope="row" class="titledesc">
			<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			<?php echo $tooltip_html; ?>
		</th>
		<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?> status-<?php echo $error_message ? 'error' : ( $is_valid ? 'valid' : 'invalid' ); ?>">
			<input
					name="<?php echo esc_attr( $value['id'] ); ?>"
					id="<?php echo esc_attr( $value['id'] ); ?>"
					type="color"
					style="<?php echo esc_attr( $value['css'] ); ?>"
					value="<?php echo esc_attr( $option_value ); ?>"
					class="<?php echo esc_attr( $value['class'] ); ?>"
					size="<?php echo esc_attr( $value['size'] ); ?>"
					placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
				<?php echo implode( ' ', $custom_attributes ); ?>
			/><span>Current color: <?php echo esc_attr( $option_value ); ?></span>
			<?php echo $description; ?>
		</td>
	</tr>
	<?php
}

add_action( 'sm_admin_field_smcolor', 'smp_render_color_picker_field', 10, 5 );
/**
 * Validates the license key.
 *
 * @param string $license_key The key.
 *
 * @return string The license key.
 */
function smp_validate_license( $license_key = '' ) {
	\SMP\Plugin::instance()->licensing_manager->recheck_license( '' === $license_key ? null : $license_key );

	return $license_key;
}

add_filter( 'sm_admin_settings_sanitize_option_license_key', 'smp_validate_license' );

/**
 * Saves query vars into a global variable.
 *
 * @param array $query_vars The vars.
 *
 * @return array
 *
 * @since 2.0.4
 */
function smp_save_query_vars( $query_vars ) {
	$GLOBALS['smp_query_vars'] = $query_vars;

	return $query_vars;
}

add_filter( 'request', 'smp_save_query_vars' );

/**
 * Returns the array of normal WordPress pages as ID => title.
 *
 * @since 1.0.0-beta.5
 *
 * @return array
 */
function smp_get_pages_array() {
	$pages    = get_pages();
	$settings = array(
		0 => '-- ' . __( 'None' ) . ' --',
	);

	foreach ( $pages as $page ) {
		$settings[ $page->ID ] = $page->post_title;
	}

	return $settings;
}