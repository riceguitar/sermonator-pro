<?php // phpcs:ignore

/**
 * Handles all updating code, alternative to WPFC Manager.
 *
 * @since   1.0.0-beta.2
 * @package SMP\Licensing
 */
namespace SMP;

defined( 'ABSPATH' ) or exit;

/**
 * Class Updating_Manager
 */
class Updating_Manager {
	/**
	 * The plugin slug.
	 *
	 * @var string
	 */
	protected $plugin_slug = '';

	/**
	 * Plugin dir, in "dir/file" format.
	 *
	 * @var string
	 */
	protected $dir = '';

	/**
	 * Plugin file.
	 *
	 * @var string
	 */
	protected $file = '';

	/**
	 * Prefix to use for options in the database and message IDs.
	 *
	 * @var string
	 */
	protected $prefix;

	/**
	 * The plugin's current version.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Plugin's license key.
	 *
	 * @var string
	 */
	protected $license_key;

	/**
	 * Updating_Manager constructor.
	 *
	 * @param string $file    The plugin's main file, via __FILE__.
	 * @param string $version The plugin's current version.
	 * @param string $prefix  The prefix to use for database and notices.
	 */
	
	public function __construct( $file, $version, $prefix ) {
		// Define variables.
		$this->dir         = basename( dirname( $file ) ) . '/' . basename( $file );
		$this->file        = $file;
		$this->version     = $version;
		$this->prefix      = $prefix;
		$this->license_key = \SermonManager::getOption( 'license_key' );

		// Get the slug from the file.
		$this->plugin_slug = $this->_get_plugin_slug();

		// Hook into WP.
		$this->_hook();

		// Remove transients.
		$this->_maybe_delete_transients();
		
	}

	/**
	 * Returns the plugin slug.
	 *
	 * @return string the slug.
	 */
	protected function _get_plugin_slug() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = \get_plugin_data( $this->file, false );

		if ( ! isset( $plugin_data ) ) {
			Plugin::instance()->notice_manager->add_warning( 'updating_slug_fail', 'Could not get plugin slug. Aborting.', 10, 'updating' );
		}

		$slug = sanitize_title( $plugin_data['Name'] );

		if ( \SermonManager::getOption( 'update_branch' ) === 'nightly' ) {
			$slug .= '-dev';
		}

		return $slug;
	}

	/**
	 * Hooks into WordPress filters and actions.
	 */
	protected function _hook() {
		add_action( 'pre_set_site_transient_update_plugins', array( $this, 'add_update_info' ) );
		add_action( 'upgrader_process_complete', array( $this, 'maybe_update_dev_version' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'maybe_show_download_error' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'maybe_show_changelog' ), 10, 3 );
	}

	/**
	 * Removes update transients, so users can force update check for real.
	 */
	protected function _maybe_delete_transients() {
		global $pagenow;

		if ( 'update-core.php' === $pagenow && isset( $_GET['force-check'] ) ) {
			delete_transient( 'update_plugins' );
		}
	}

	/**
	 * Adds update info to WP data.
	 *
	 * @param \stdClass $data Transient data.
	 *
	 * @return mixed Modified data.
	 */
	public function add_update_info( $data ) {
		// Get out of here.
		if ( ! isset( $data->checked ) && ! isset( $data->response ) ) {
			return $data;
		}

		// If response for our product is already set, remove it
		// Sometimes WP finds another product with the same name in WP.org.
		if ( isset( $data->response[ $this->dir ] ) ) {
			unset( $data->response[ $this->dir ] );
		}

		// Allow updates even if wrong SM version on nightly.
		if ( \SermonManager::getOption( 'update_branch' ) !== 'nightly' ) {
			// Check if SM is at the right version before showing the notice for the update.
			if ( version_compare( SM_VERSION, SMP_SM_VERSION, '<' ) ) {
				return $data;
			}
		}

		if ( self::is_update_available() ) {
			$data->response[ $this->dir ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->dir,
				'new_version' => $this->get_remote_version(),
				'package'     => $this->_get_update_url(),
			);
		} else {
			$data->no_update[ $this->dir ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->dir,
				'new_version' => $this->get_current_version(),
				'url'         => '',
				'package'     => '',
			);
		}

		return $data;
	}

	/**
	 * Checks if there is an update available.
	 *
	 * @return bool|null True if it is, false otherwise. Null on failure.
	 */
	public function is_update_available() {
		$remote_version = $this->get_remote_version();
		$local_version  = $this->get_current_version();

		// Check dev.
		if ( is_numeric( $remote_version ) ) {
			$local_dev = get_option( $this->prefix . 'version_dev' );

			// If checking dev version for the first time.
			if ( false === $local_dev ) {
				update_option( $this->prefix . 'version_dev', $remote_version );

				return false;
			}

			if ( $local_dev != $remote_version ) {
				return true;
			} else {
				return false;
			}
		}

		if ( null !== $remote_version && null !== $local_version ) {
			if ( version_compare( $remote_version, $local_version, '>' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets latest remote version.
	 *
	 * @return null|string Version on success, null on failure.
	 */
	public function get_remote_version() {
		$version = null;

		if ( get_transient( $this->plugin_slug . '-remote_version' ) !== false ) {
			$version = get_transient( $this->plugin_slug . '-remote_version' );
		} else {
			$request = wp_safe_remote_request( 'https://www.wpforchurch.com/?WPFC=get_product_version&product=' . $this->plugin_slug );

			// Check if failed.
			if ( $request instanceof \WP_Error ) {
				set_transient( 'update_check_try_later_version', 1, 30 * MINUTE_IN_SECONDS );

				return null;
			}

			// Add defaults.
			$request += array(
				'body'     => '',
				'response' => array(
					'code'    => 0,
					'message' => 'Unknown error.',
				),
			);

			if ( 200 === $request['response']['code'] && $request['body'] ) {
				$data = json_decode( $request['body'], true );

				if ( ! $data ) {
					Plugin::instance()->notice_manager->add_warning( 'updating_decode_fail_version', 'Could not decode update data. Aborting.', 10, 'updating' );
					set_transient( 'update_check_try_later_version', 1, 30 * MINUTE_IN_SECONDS );
					
					if ( isset($data['message']) && 0 === $data['status'] ) {
						$version = $data['message'];
	
						set_transient( $this->plugin_slug . '-remote_version', $version, 5 * MINUTE_IN_SECONDS );
					} else {
						return null;
					}

				}				
			}
		}

		return $version;
	}

	/**
	 * Gets current product version.
	 *
	 * @return string|null Version on success, null on failure.
	 */
	public function get_current_version() {
		return null !== $this->version ? $this->version : null;
	}

	/**
	 * Gets temporary update URL.
	 *
	 * @param bool $force If it should skip transient check.
	 *
	 * @return string|false The URL or false on error (Notice will be shown).
	 */
	protected function _get_update_url( $force = false ) {
		$url = '';

		$license_key = $this->license_key;

		if ( $force ) {
			delete_transient( $this->plugin_slug . '-update_url' );
			delete_transient( 'update_check_try_later' );
		}

		if ( get_transient( $this->plugin_slug . '-update_url' ) !== false ) {
			return get_transient( $this->plugin_slug . '-update_url' );
		}

		if ( get_transient( 'update_check_try_later' ) ) {
			return false;
		}

		if ( ! $license_key ) {
			return false;
		}

		// Get temporary download URL.
		$request_url = 'https://www.wpforchurch.com/?GPAPI=get_product_update_url&product=' . $this->plugin_slug;

		$domain = $_SERVER['SERVER_NAME'];
		$ip     = isset( $_SERVER['SERVER_ADDR'] ) ? $_SERVER['SERVER_ADDR'] : $_SERVER['LOCAL_ADDR'];
		$path   = SMP_PATH;

		$request_url .= '&license_key=' . $license_key . '&ip=' . $ip . '&domain=' . urlencode( $domain ) . '&path=' . urlencode( $path );

		// Do the request.
		$request = wp_safe_remote_request( $request_url );

		// Check if failed.
		if ( $request instanceof \WP_Error ) {
			set_transient( 'update_check_try_later', 1, 30 * MINUTE_IN_SECONDS );

			return false;
		}

		// Add defaults.
		$request += array(
			'body'     => '',
			'response' => array(
				'code'    => 0,
				'message' => 'Unknown error.',
			),
		);

		if ( 200 === $request['response']['code'] && $request['body'] ) {
			$data = json_decode( $request['body'], true );

			if ( ! $data ) {
				Plugin::instance()->notice_manager->add_warning( 'updating_decode_fail_url', 'Could not decode update data. Aborting.', 10, 'updating' );
				set_transient( 'update_check_try_later', 1, 30 * MINUTE_IN_SECONDS );
			}

			if ( $data['message'] && 0 === $data['status'] ) {
				$url            = $data['message'];
				$url_data       = explode( '&', substr( $url, strpos( $url, '?' ) + 1 ) );
				$url_expiration = intval( substr( $url_data[1], 8 ) ) - time();

				set_transient( $this->plugin_slug . '-update_url', $url, $url_expiration );
			}
		}

		return $url;
	}

	/**
	 * Updates dev version of the plugin after update if we are on nightly.
	 *
	 * @param \Plugin_Upgrader $upgrader_object Class instance.
	 * @param array            $options         Upgrader data.
	 *
	 * @since 1.0.0-beta.2
	 */
	public function maybe_update_dev_version( $upgrader_object, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] && isset( $options['plugins'] ) ) {

			foreach ( $options['plugins'] as $plugin ) {
				if ( basename( $this->file ) === basename( $plugin ) ) {

					// Get out if update failed.
					if ( is_wp_error( $upgrader_object ) || ! is_array( $upgrader_object->result ) ) {
						return;
					}

					$remote_version = $this->get_remote_version();

					update_option( $this->prefix . 'version_dev', $remote_version );
				}
			}
		}
	}

	/**
	 * Shows a notice explaining why an update package is not available.
	 *
	 * @param false|mixed      $false           The default value, if it should be overriden. Defaults to false.
	 * @param string           $package         Download URL.
	 * @param \Plugin_Upgrader $upgrader_object The upgrader object.
	 *
	 * @return mixed
	 * @since 1.0.0-beta.3
	 */
	public function maybe_show_download_error( $false, $package, $upgrader_object ) {
		if ( ! $upgrader_object instanceof \Plugin_Upgrader ) {
			return $false;
		}

		$plugin_data = get_plugin_data( $this->file );

		/* @noinspection PhpUndefinedFieldInspection */
		if ( $upgrader_object->skin->plugin_info['Name'] !== $plugin_data['Name'] ) {
			return $false;
		}

		if ( '' === $package ) {
			// Check license.
			$license_status = Plugin::instance()->licensing_manager->recheck_license();

			if ( false !== $license_status ) {
				return new \WP_Error( $this->prefix . 'license_invalid', 'The license is invalid. Please make sure that the <a href="' . admin_url( 'edit.php?post_type=wpfc_sermon&page=sm-settings&tab=licensing' ) . '" target="_self">product is activated</a>.' );
			}

			// Try retrieving the URL again.
			$package = $this->_get_update_url( true );

			if ( '' !== $package ) {
				$upgrader_object->skin->feedback( 'downloading_package', $package );

				$download_file = download_url( $package );

				if ( is_wp_error( $download_file ) ) {
					return new \WP_Error( 'download_failed', 'Update package could not be retrieved, please try again later. Error message: "' . $download_file->get_error_message() . '"', $download_file->get_error_message() );
				}

				return $download_file;
			}

			return new \WP_Error( $this->prefix . 'no_package', 'Update package could not be retrieved, please try again later.' );
		}

		return $false;
	}

	/**
	 * Shows plugin changelog if it's requested.
	 *
	 * @param false|mixed $false  Default value.
	 * @param string      $action API action.
	 * @param array       $args   API args.
	 *
	 * @return string
	 */
	public function maybe_show_changelog( $false, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $false;
		}

		if ( ! isset( $args->slug ) || ! $args->slug ) {
			return $false;
		}

		// Get real plugin data.
		$plugin_data      = get_plugin_data( $this->file );
		$remote_version   = $this->get_remote_version();
		$remote_changelog = $this->get_remote_changelog();

		$remote_changelog = preg_replace( '/^### (.*) ###$/m', '<strong>$1</strong>', $remote_changelog );

		// Make sure to only override Sermon Manager Pro details.
		if ( ! in_array( $args->slug, array(
			sanitize_title( $plugin_data['Name'] ),
			sanitize_title( $plugin_data['Name'] ) . '-dev',
		) ) ) {
			return false;
		}

		$data = new \stdClass();

		$data->name     = $plugin_data['Name'];
		$data->slug     = sanitize_title( $plugin_data['Name'] );
		$data->version  = $remote_version . ( is_numeric( $remote_version ) ? ' (dev)<br><strong>Built on:</strong> ' . substr( date( 'r', intval( $remote_version ) ), 0, strlen( date( 'r', intval( $remote_version ) ) ) - 6 ) : '' );
		$data->author   = $plugin_data['Author'];
		$data->homepage = $plugin_data['Title'];
		$data->sections = array(
			'changelog' => nl2br( $remote_changelog ),
		);

		return $data;
	}

	/**
	 * Gets plugin changelog from API.
	 *
	 * @return string|null Changelog or null on failure.
	 */
	public function get_remote_changelog() {
		$changelog = null;

		if ( get_transient( $this->plugin_slug . '-remote_changelog' ) !== false ) {
			$changelog = get_transient( $this->plugin_slug . '-remote_changelog' );
		} else {
			$request = wp_safe_remote_request( 'https://www.wpforchurch.com/?WPFC=get_product_changelog&product=' . $this->plugin_slug );

			// Check if failed.
			if ( $request instanceof \WP_Error ) {
				set_transient( 'update_check_try_later_changelog', 1, 30 * MINUTE_IN_SECONDS );

				return null;
			}

			// Add defaults.
			$request += array(
				'body'     => '',
				'response' => array(
					'code'    => 0,
					'message' => 'Unknown error.',
				),
			);

			if ( 200 === $request['response']['code'] && $request['body'] ) {
				$data = json_decode( $request['body'], true );

				if ( ! $data ) {
					Plugin::instance()->notice_manager->add_warning( 'updating_decode_fail_changelog', 'Could not decode changelog data. Aborting.', 10, 'updating' );
					set_transient( 'update_check_try_later_changelog', 1, 30 * MINUTE_IN_SECONDS );
				}

				if ( is_array($data) && $data['message'] && 0 === $data['status'] ) {
					$changelog = $data['message'];

					set_transient( $this->plugin_slug . '-remote_changelog', $changelog, 5 * MINUTE_IN_SECONDS );
				} else {
					return null;
				}
			}
		}

		return $changelog;
	}
}
