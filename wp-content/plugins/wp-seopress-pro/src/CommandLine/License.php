<?php
/**
 * SEOPress PRO WP-CLI License commands.
 *
 * @package SEOPress PRO
 * @subpackage CommandLine
 */

namespace SEOPressPro\CommandLine;

use WP_CLI_Command;
use WP_CLI;

/**
 * Manages the SEOPress PRO license via WP-CLI.
 */
class License extends WP_CLI_Command {

	/**
	 * Activate the SEOPress PRO license key.
	 *
	 * ## OPTIONS
	 *
	 * [--key=<value>]
	 * : The license key to activate. If omitted, uses the key already stored in the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress-pro license activate --key=YOUR_LICENSE_KEY
	 *     wp seopress-pro license activate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function activate( $args, $assoc_args ) {
		if ( ! defined( 'STORE_URL_SEOPRESS' ) || ! filter_var( STORE_URL_SEOPRESS, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'SEOPress PRO store URL is not configured. Please use a production build of the plugin.' );
		}

		$key = isset( $assoc_args['key'] ) ? trim( $assoc_args['key'] ) : '';

		if ( ! empty( $key ) ) {
			update_option( 'seopress_pro_license_key', $key );
		}

		$license = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY )
			? SEOPRESS_LICENSE_KEY
			: trim( (string) get_option( 'seopress_pro_license_key' ) );

		if ( empty( $license ) ) {
			WP_CLI::error( 'No license key provided. Use --key=YOUR_LICENSE_KEY.' );
		}

		WP_CLI::line( 'Activating license...' );

		$api_params = array(
			'edd_action'  => 'activate_license',
			'license'     => $license,
			'item_id'     => ITEM_ID_SEOPRESS,
			'url'         => get_option( 'home' ),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);

		$response = wp_remote_post(
			STORE_URL_SEOPRESS,
			array(
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
				'timeout'    => 15,
				'sslverify'  => false,
				'body'       => $api_params,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( $response->get_error_message() );
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::error( 'An error occurred. Response code: ' . wp_remote_retrieve_response_code( $response ) );
		}

		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! $license_data->success ) {
			WP_CLI::error( $this->get_error_message( $license_data ) );
		}

		if ( ! defined( 'SEOPRESS_LICENSE_KEY' ) ) {
			update_option( 'seopress_pro_license_key', $license );
		}

		update_option( 'seopress_pro_license_status', $license_data->license );

		if ( isset( $license_data->expires ) ) {
			if ( 'lifetime' === $license_data->expires ) {
				update_option( 'seopress_pro_license_expiry', strtotime( '+100 years' ), false );
			} else {
				update_option( 'seopress_pro_license_expiry', strtotime( $license_data->expires ), false );
			}
		}

		$this->clear_edd_cache();

		WP_CLI::success( 'License activated successfully.' );
	}

	/**
	 * Deactivate the SEOPress PRO license key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress-pro license deactivate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function deactivate( $args, $assoc_args ) {
		if ( ! defined( 'STORE_URL_SEOPRESS' ) || ! filter_var( STORE_URL_SEOPRESS, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'SEOPress PRO store URL is not configured. Please use a production build of the plugin.' );
		}

		$license = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY )
			? SEOPRESS_LICENSE_KEY
			: trim( (string) get_option( 'seopress_pro_license_key' ) );

		if ( empty( $license ) ) {
			WP_CLI::error( 'No license key found in the database.' );
		}

		WP_CLI::line( 'Deactivating license...' );

		$api_params = array(
			'edd_action'  => 'deactivate_license',
			'license'     => $license,
			'item_id'     => ITEM_ID_SEOPRESS,
			'url'         => get_option( 'home' ),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);

		$response = wp_remote_post(
			STORE_URL_SEOPRESS,
			array(
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
				'timeout'    => 15,
				'sslverify'  => false,
				'body'       => $api_params,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( $response->get_error_message() );
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::error( 'An error occurred. Response code: ' . wp_remote_retrieve_response_code( $response ) );
		}

		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		// "failed" means the license was already inactive on the server — treat as success
		// and clean up local state in both cases.
		if ( isset( $license_data->license ) && 'deactivated' !== $license_data->license && 'failed' !== $license_data->license ) {
			WP_CLI::error( 'Deactivation failed. Please try again or deactivate from the SEOPress settings page.' );
		}

		delete_option( 'seopress_pro_license_status' );
		delete_option( 'seopress_pro_license_expiry' );
		$this->clear_edd_cache();

		WP_CLI::success( 'License deactivated successfully.' );
	}

	/**
	 * Display the current SEOPress PRO license status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress-pro license status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$key    = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) ? SEOPRESS_LICENSE_KEY : get_option( 'seopress_pro_license_key', '' );
		$status = get_option( 'seopress_pro_license_status', '' );
		$expiry = get_option( 'seopress_pro_license_expiry', '' );

		if ( empty( $key ) ) {
			WP_CLI::line( 'Key    : (none)' );
		} elseif ( defined( 'SEOPRESS_LICENSE_KEY' ) ) {
			WP_CLI::line( 'Key    : defined in wp-config.php' );
		} else {
			WP_CLI::line( 'Key    : ' . substr( $key, 0, 4 ) . str_repeat( '*', max( 0, strlen( $key ) - 4 ) ) );
		}

		WP_CLI::line( 'Status : ' . ( $status ? $status : '(none)' ) );

		if ( $expiry ) {
			WP_CLI::line( 'Expiry : ' . date_i18n( get_option( 'date_format' ), (int) $expiry ) );
		} else {
			WP_CLI::line( 'Expiry : (none)' );
		}
	}

	/**
	 * Reset the SEOPress PRO license key and all related options.
	 *
	 * Removes the stored license key, status, expiry, error and auto-activation
	 * flags from the database, and clears the EDD updater cache.
	 * Equivalent to clicking the Reset button on the license settings page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress-pro license reset
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function reset( $args, $assoc_args ) {
		delete_option( 'seopress_pro_license_status' );
		delete_option( 'seopress_pro_license_key' );
		delete_option( 'seopress_pro_license_key_error' );
		delete_option( 'seopress_pro_license_expiry' );
		delete_option( 'seopress_pro_license_automatic_attempt' );
		delete_option( 'seopress_pro_license_home_url' );
		$this->clear_edd_cache();

		WP_CLI::success( 'License reset successfully.' );
	}

	/**
	 * Check for and install available SEOPress PRO updates.
	 *
	 * Clears the EDD updater cache, forces WordPress to re-check for updates,
	 * then installs the update if one is available.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress-pro license update
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function update( $args, $assoc_args ) {
		$status = get_option( 'seopress_pro_license_status', '' );
		if ( 'valid' !== $status ) {
			WP_CLI::warning( 'License is not active. Updates may not be available.' );
		}

		WP_CLI::line( 'Clearing update cache...' );
		$this->clear_edd_cache();

		WP_CLI::line( 'Checking for updates...' );
		wp_update_plugins();

		$update_plugins = get_site_transient( 'update_plugins' );
		$plugin_file    = 'wp-seopress-pro/seopress-pro.php';

		if ( empty( $update_plugins->response[ $plugin_file ] ) ) {
			WP_CLI::success( 'SEOPress PRO is already up to date.' );
			return;
		}

		$update = $update_plugins->response[ $plugin_file ];
		WP_CLI::line( sprintf( 'Update available: %s. Installing...', $update->new_version ) );

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$skin     = new \WP_CLI\Utils\NoOp_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( false === $result ) {
			WP_CLI::error( 'Update failed. Check file permissions and try again.' );
		}

		WP_CLI::success( sprintf( 'SEOPress PRO updated to %s.', $update->new_version ) );
	}

	/**
	 * Clear EDD updater caches so stale package URLs are not reused.
	 *
	 * Mirrors seopress_clear_edd_updater_cache() from inc/admin/callbacks/License.php,
	 * which is only loaded in admin context and therefore unavailable under WP-CLI.
	 *
	 * @return void
	 */
	private function clear_edd_cache() {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'edd\_sl\_%' OR option_name LIKE 'edd\_api\_request\_%' OR option_name LIKE 'edd\_sl\_failed\_http\_%'" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Return a human-readable error message from an EDD license API response.
	 *
	 * @param object $license_data Decoded API response.
	 * @return string
	 */
	private function get_error_message( $license_data ) {
		if ( ! isset( $license_data->error ) ) {
			return 'An error occurred, please try again.';
		}

		switch ( $license_data->error ) {
			case 'expired':
				return sprintf(
					'Your license key expired on %s.',
					isset( $license_data->expires ) ? date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires ) ) : 'unknown'
				);
			case 'disabled':
			case 'revoked':
				return 'Your license key has been disabled.';
			case 'missing':
				return 'Invalid license.';
			case 'invalid':
			case 'site_inactive':
				return 'Your license is not active for this URL.';
			case 'item_name_mismatch':
				return sprintf( 'This appears to be an invalid license key for %s.', ITEM_NAME_SEOPRESS );
			case 'no_activations_left':
				return 'Your license key has reached its activation limit.';
			default:
				return 'An error occurred, please try again.';
		}
	}
}
