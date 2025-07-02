<?php
/**
 * Adds a setting page for Pro licensing.
 *
 * @since   1.0.0-beta.2
 *
 * @package SMP\Settings
 */

namespace SMP\Settings;

use SM_Settings_Page;

/**
 * Class SMP_Settings_Licensing
 *
 * @package SMP\Settings
 */
class SMP_Settings_Licensing extends SM_Settings_Page {
	/**
	 * SM_Settings_Pro constructor.
	 */
	public function __construct() {
		$this->id    = 'licensing';
		$this->label = __( 'Licensing', 'sermon-manager-pro' );

		parent::__construct();
	}

	/**
	 * Get settings array.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = apply_filters( 'sm_licensing_settings', array(
			array(
				'title' => __( 'Licensing', 'sermon-manager-pro' ),
				'type'  => 'title',
				'desc'  => '',
				'id'    => 'licensing_settings',
			),
			array(
				'title'       => __( 'License Key', 'sermon-manager-pro' ),
				'type'        => 'licensing',
				'desc'        => __( 'Enter your license to receive updates and support.', 'sermon-manager-pro' ),
				'id'          => 'license_key',
				'default'     => '',
				'placeholder' => 'wpfc-XXXXXXXXXX',
				'css'         => 'width:initial;padding:5px 10px;font-family:monospace, sans-serif;',
				'size'        => '15',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'licensing_settings',
			),
		) );

		return apply_filters( 'smp_get_settings_' . $this->id, $settings );
	}
}

return new SMP_Settings_Licensing();
