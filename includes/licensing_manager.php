<?php
/**
 * Handles all licensing code, alternative to WPFC Manager.
 *
 * @since   2.0.4
 * @package SMP\Licensing
 */

namespace SMP;

use SermonManager;

defined( 'ABSPATH' ) or exit;

/**
 * Class Licensing_Manager
 */
class Licensing_Manager {
	/**
	 * If the license is valid. Null means that it is still checking.
	 *
	 * @var bool|null
	 * @access protected
	 */
	protected $is_valid = null;

	/**
	 * Licensing_Manager constructor.
	 */
	public function __construct() {
		$this->is_valid = $this->_check_license();

		$this->_maybe_show_notice();
	}

	/**
	 * Checks the license validity against our database. To check if the license is valid, use method `is_valid()`.
	 *
	 * @param string $license_key The license key to check. Will used saved one if parameter empty. Pass false or null
	 *                            to return false.
	 * @param bool   $force_now   Set to true to force checking now, instead of using cron.
	 *
	 * @return bool|string Bool if valid or not, string as error message otherwise (only if $force_now is set to true).
	 */
	protected function _check_license( $license_key = '', $force_now = false ) {
		// Get the license key if it's not set.
		$license_key = '' !== $license_key ? $license_key : SermonManager::getOption( 'license_key' );

		// Do not use local key if we are forcing the check.
		$local_key = $force_now ? '' : get_option( 'smp_local_key', '' );

		// Do not check the license if we do not have the license key.
		if ( ! $license_key ) {
			return false;
		}

		// Remove transient if we are forcing the check.
		if ( $force_now ) {
			delete_transient( 'smp_license_good' );
		}

		// Check if we do not have to send the request to the server.
		$license_checked = get_transient( 'smp_license_good' );
		if ( false !== $license_checked ) {
			return 1 == $license_checked;
		}

		// Check if it's club license.
		$request = wp_safe_remote_request( 'https://wpforchurch.com/?WPFC=check_license&license_key=' . urlencode( $license_key ) . '&domain=' . urlencode( $_SERVER['SERVER_NAME'] ) );

		if ( ! $request instanceof \WP_Error && 200 === $request['response']['code'] ) {
			$response = json_decode( $request['body'], true );

			if ( $response && 'Valid' === $response['message'] ) {
				set_transient( 'smp_license_good', 1, DAY_IN_SECONDS );

				return true;
			}
		}

		// Validate the license.
		$data = $this->_validate_license( $license_key, $local_key );

		// Add default data.
		$data += array(
			'status'   => 'Invalid',
			'localkey' => '',
			'message'  => '',
		);

		// If there was no error.
		if ( ! $data['message'] ) {
			switch ( $data['status'] ) {
				case 'Active':
					set_transient( 'smp_license_good', 1, DAY_IN_SECONDS );
					update_option( 'smp_local_key', $data['localkey'] );

					return true;
				case 'Suspended':
				case 'Expired':
				case 'Invalid':
				case 'Terminated':
					set_transient( 'smp_license_good', 0, DAY_IN_SECONDS );
					update_option( 'smp_local_key', '' );

					return false;
			}
		} else { // If there was an error.
			set_transient( 'smp_license_good', 0, DAY_IN_SECONDS );
			update_option( 'smp_local_key', '' );
		}

		// Return the error message only if we are forcing the check.
		if ( $force_now ) {
			if ( $data['message'] ) {
				return $data['message'] . '.';
			} else {
				return __( 'Unknown error.', 'sermon-manager-pro' );
			}
		} else {
			return false;
		}
	}

	/**
	 * Activates the license.
	 *
	 * @param string $license_key The license key to validate.
	 * @param string $local_key   The local key to validate.
	 *
	 * @return array License data.
	 */
	protected function _validate_license( $license_key = '', $local_key = '' ) {
		// WHMCS URL.
		$whmcs_url = 'https://www.wpforchurch.com/my/';
		// Product MD5 Hash.
		$licensing_secret_key = 'HZh1vpLImh3yyRP8lrIuv5u6fbeP9uXTyvLhkG621tmJWLDy7Zc04XgGRHfz';
		// The number of days to wait between performing remote license checks.
		$local_key_days = 15;
		// The number of days to allow failover for after local key expiry.
		$allow_check_fail_days = 5;
		// Plugin path.
		$dir_path = SMP_PATH;

		// Do the magic.
		$check_token            = time() . md5( mt_rand( 1000000000, 9999999999 ) . $license_key );
		$check_date             = date( 'Ymd' );
		$domain                 = $_SERVER['SERVER_NAME'];
		$users_ip               = isset( $_SERVER['SERVER_ADDR'] ) ? $_SERVER['SERVER_ADDR'] : $_SERVER['LOCAL_ADDR'];
		$verify_file_path       = 'modules/servers/licensing/verify.php';
		$local_key_valid        = false;
		$return                 = array(
			'license_key' => $license_key,
			'check_token' => $check_token,
			'checkdate'   => $check_date,
			'domain'      => $domain,
			'ip'          => $users_ip,
			'path'        => $dir_path,
		);
		$return['is_local_key'] = false;
		if ( $local_key ) {
			$local_key                     = str_replace( "\n", '', $local_key );
			$local_data                    = substr( $local_key, 0, strlen( $local_key ) - 32 );
			$md5hash                       = substr( $local_key, strlen( $local_key ) - 32 );
			$return['local_key_md5_valid'] = false;
			if ( md5( $local_data . $licensing_secret_key ) === $md5hash ) {
				$return['local_key_second_md5_valid'] = false;
				$return['local_key_first_md5_valid']  = true;
				$local_data                           = strrev( $local_data );
				$md5hash                              = substr( $local_data, 0, 32 );
				$local_data                           = substr( $local_data, 32 );
				$local_data                           = base64_decode( $local_data );
				$local_key_results                    = unserialize( $local_data );
				$original_check_date                  = $local_key_results['checkdate'];
				if ( md5( $original_check_date . $licensing_secret_key ) === $md5hash ) {
					$return['local_key_second_md5_valid'] = true;
					$return['local_key_expired']          = true;
					$local_expiry                         = date( 'Ymd', mktime( 0, 0, 0, date( 'm' ), date( 'd' ) - $local_key_days, date( 'y' ) ) );
					if ( $original_check_date > $local_expiry ) {
						$return['local_key_expired'] = false;
						$return['is_local_key']      = true;
						$return['domain_valid']      = true;
						$return['ip_valid']          = true;
						$return['dir_valid']         = true;
						$local_key_valid             = true;
						$results                     = $local_key_results;
						$valid_domains               = explode( ',', $results['validdomain'] );
						if ( ! in_array( $_SERVER['SERVER_NAME'], $valid_domains ) ) {
							$return['domain_valid']      = false;
							$local_key_valid             = false;
							$local_key_results['status'] = 'Invalid';
							$results                     = array();
						}
						$valid_ips          = explode( ',', $results['validip'] );
						$return['ip_valid'] = false;
						if ( ! in_array( $users_ip, $valid_ips ) ) {
							$local_key_valid             = false;
							$local_key_results['status'] = 'Invalid';
							$results                     = array();
						}
						$valid_dirs = explode( ',', $results['validdirectory'] );
						if ( ! in_array( $dir_path, $valid_dirs ) ) {
							$return['dir_valid']         = false;
							$local_key_valid             = false;
							$local_key_results['status'] = 'Invalid';
							$results                     = array();
						}
					}
				}
			}
		}
		if ( ! $local_key_valid ) {
			$response_code = 0;
			$post_fields   = array(
				'licensekey' => $license_key,
				'domain'     => $domain,
				'ip'         => $users_ip,
				'dir'        => $dir_path,
			);
			if ( $check_token ) {
				$post_fields['check_token'] = $check_token;
			}
			$query_string = '';
			foreach ( $post_fields as $k => $v ) {
				$query_string .= $k . '=' . urlencode( $v ) . '&';
			}
			if ( function_exists( 'curl_exec' ) ) {
				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_URL, $whmcs_url . $verify_file_path );
				curl_setopt( $ch, CURLOPT_POST, 1 );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $query_string );
				curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
				$data          = curl_exec( $ch );
				$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				curl_close( $ch );
			} else {
				$response_pattern = '/^HTTP\/\d+\.\d+\s+(\d+)/';
				$fp               = @fsockopen( $whmcs_url, 80, $err_no, $err_str, 5 ); // phpcs:ignore
				if ( $fp ) {
					$new_line_feed = "\r\n";
					$header        = '';

					$header .= 'POST ' . $whmcs_url . $verify_file_path . ' HTTP/1.0' . $new_line_feed;
					$header .= 'Host: ' . $whmcs_url . $new_line_feed;
					$header .= 'Content-type: application/x-www-form-urlencoded' . $new_line_feed;
					$header .= 'Content-length: ' . strlen( $query_string ) . $new_line_feed;
					$header .= 'Connection: close' . $new_line_feed . $new_line_feed;
					$header .= $query_string;

					$data = '';
					@stream_set_timeout( $fp, 20 ); // phpcs:ignore
					@fputs( $fp, $header ); // phpcs:ignore
					$status = @socket_get_status( $fp ); // phpcs:ignore
					while ( ! @feof( $fp ) && $status ) { // phpcs:ignore
						$line            = @fgets( $fp, 1024 ); // phpcs:ignore
						$pattern_matches = array();
						if ( ! $response_code && preg_match( $response_pattern, trim( $line ), $pattern_matches )
						) {
							$response_code = ( empty( $pattern_matches[1] ) ) ? 0 : $pattern_matches[1];
						}
						$data .= $line;

						$status = @socket_get_status( $fp ); // phpcs:ignore
					}
					@fclose( $fp ); // phpcs:ignore
				}
			}

			$return['response_code'] = $response_code;
			$return['response_data'] = isset( $data ) ? $data : '';

			if ( 200 !== intval( $response_code ) ) {
				$local_expiry = date( 'Ymd', mktime( 0, 0, 0, date( 'm' ), date( 'd' ) - ( $local_key_days + $allow_check_fail_days ), date( 'Y' ) ) );
				if ( isset( $original_check_date ) && ( $original_check_date > $local_expiry ) ) {
					$return['remove_check_valid'] = true;
					$results                      = isset( $local_key_results ) ? $local_key_results : array();
				} else {
					$return['remove_check_valid'] = false;
					$results                      = array();
					$results['status']            = 'Invalid';
					$results['description']       = 'Remote Check Failed. Response code: ' . $response_code;

					return array_merge( $return, $results );
				}
			} else {
				preg_match_all( '/<(.*?)>([^<]+)<\/\\1>/i', isset( $data ) ? $data : '', $matches );
				$results = array();
				foreach ( $matches[1] as $k => $v ) {
					$results[ $v ] = $matches[2][ $k ];
				}
			}
			if ( ! is_array( $results ) ) {
				return array(
					'status'      => 'Invalid',
					'description' => 'Invalid License Server Response',
				);
			}
			$return['remote_md5_valid'] = false;
			if ( isset( $results['md5hash'] ) && $results['md5hash'] ) {
				if ( md5( $licensing_secret_key . $check_token ) !== $results['md5hash'] ) {
					$results['status']      = 'Invalid';
					$results['description'] = 'MD5 Checksum Verification Failed';

					return array_merge( $return, $results );
				}
			}
			$return['remote_md5_valid'] = true;
			if ( 'Active' === $results['status'] ) {
				$results['checkdate'] = $check_date;
				$data_encoded         = serialize( $results );
				$data_encoded         = base64_encode( $data_encoded );
				$data_encoded         = md5( $check_date . $licensing_secret_key ) . $data_encoded;
				$data_encoded         = strrev( $data_encoded );
				$data_encoded         = $data_encoded . md5( $data_encoded . $licensing_secret_key );
				$data_encoded         = wordwrap( $data_encoded, 80, "\n", true );
				$results['localkey']  = $data_encoded;
			}
			$results['remotecheck'] = true;
		}
		unset( $post_fields, $data, $matches, $whmcs_url, $licensing_secret_key, $check_date, $users_ip, $local_key_days, $allow_check_fail_days, $md5hash );

		return array_merge( $return, isset( $results ) ? $results : array() );
	}

	/**
	 * Shows notice if license is not active.
	 */
	protected function _maybe_show_notice() {
		if ( false === $this->is_valid && ! SermonManager::getOption( 'license_key' ) ) {
			Plugin::instance()->notice_manager->add_info( 'licensing_activate', 'Please <a href="' . admin_url( 'edit.php?post_type=wpfc_sermon&page=sm-settings&tab=licensing' ) . '" target="_self">enter and activate</a> your license key to enable automatic updates.', 10, 'licensing', true );
		} else {
			Plugin::instance()->notice_manager->set_seen( 'licensing_activate', true );
		}
	}

	/**
	 * Used to do a license recheck, useful for Ajax.
	 *
	 * @param string $license_key The license key to check. Will used saved one if parameter empty.
	 *
	 * @return bool|string Bool if valid or not, string as error message otherwise.
	 */
	public function recheck_license( $license_key = '' ) {
		$status         = $this->_check_license( $license_key, true );
		$this->is_valid = true === $status;

		return $status;
	}

	/**
	 * Checks if the license is valid.
	 *
	 * @return bool|null Null means that it is still checking. Otherwise the validity status.
	 */
	public function is_valid() {
		return $this->is_valid;
	}
}
