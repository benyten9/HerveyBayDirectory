<?php //phpcs:ignore
/**
 * SEOPress PRO License callbacks.
 *
 * @package SEOPress PRO
 * @subpackage Callbacks
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Clear EDD updater version info caches.
 *
 * Removes all edd_sl_* and edd_api_request_* options so that stale
 * package URLs (which embed the old license key) are not reused after
 * a license change.
 *
 * @return void
 */
function seopress_clear_edd_updater_cache() {
	global $wpdb;

	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'edd\_sl\_%' OR option_name LIKE 'edd\_api\_request\_%' OR option_name LIKE 'edd\_sl\_failed\_http\_%'" );

	// Also clear the site transient so WordPress re-checks for updates.
	delete_site_transient( 'update_plugins' );
}

/**
 * Build a read-only snapshot of the license / updater state.
 *
 * Used by the debug panel on the License page to help diagnose failed or
 * unauthorized update downloads without editing code on the site. Kept as a
 * standalone gatherer so it can back both the REST endpoint and any CLI use.
 *
 * The license key is never returned in full: only its length and a masked
 * fragment are exposed.
 *
 * @return array Associative array of debug fields.
 */
function seopress_get_license_debug_data() {
	$store_url = defined( 'STORE_URL_SEOPRESS' ) ? STORE_URL_SEOPRESS : '';

	// Mirror the key the updater builds in SEOPRESS_Updater::__construct().
	$failed_key   = '' !== $store_url ? 'edd_sl_failed_http_' . md5( trailingslashit( $store_url ) ) : '';
	$failed_until = '' !== $failed_key ? (int) get_option( $failed_key ) : 0;

	$license = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY ) ? SEOPRESS_LICENSE_KEY : (string) get_option( 'seopress_pro_license_key' );
	$expiry  = get_option( 'seopress_pro_license_expiry' );

	$curl = function_exists( 'curl_version' ) ? curl_version() : array();

	return array(
		'license_status'        => (string) get_option( 'seopress_pro_license_status', '' ),
		'license_key_defined'   => defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ),
		'license_key_length'    => strlen( $license ),
		'license_key_masked'    => seopress_pro_mask_license_key( $license ),
		'license_key_error'     => (string) get_option( 'seopress_pro_license_key_error', '' ),
		'license_expiry'        => $expiry ? gmdate( 'Y-m-d H:i:s', (int) $expiry ) . ' UTC' : '',
		'home_url'              => (string) get_option( 'home' ),
		'stored_home_url'       => (string) get_option( 'seopress_pro_license_home_url', '' ),
		'store_url'             => $store_url,
		'item_id'               => defined( 'ITEM_ID_SEOPRESS' ) ? ITEM_ID_SEOPRESS : '',
		'failed_request_until'  => $failed_until,
		'failed_request_active' => $failed_until > 0 && time() < $failed_until,
		'environment'           => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		'wp_version'            => get_bloginfo( 'version' ),
		'php_version'           => PHP_VERSION,
		'curl_version'          => isset( $curl['version'] ) ? $curl['version'] : '',
		'ssl_version'           => isset( $curl['ssl_version'] ) ? $curl['ssl_version'] : ( defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : '' ),
	);
}

/**
 * Mask a license key, keeping only the last 4 characters.
 *
 * @param string $license The license key.
 *
 * @return string The masked key, or an empty string when there is nothing to mask.
 */
function seopress_pro_mask_license_key( $license ) {
	$license = (string) $license;

	return strlen( $license ) > 4 ? str_repeat( '*', strlen( $license ) - 4 ) . substr( $license, -4 ) : '';
}

/**
 * Build the UI-facing license state consumed by the React License page.
 *
 * @return array
 */
function seopress_pro_get_license_state() {
	$defined_in_config = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY );
	$license           = $defined_in_config ? SEOPRESS_LICENSE_KEY : (string) get_option( 'seopress_pro_license_key' );
	$expiry            = get_option( 'seopress_pro_license_expiry' );

	return array(
		'status'            => (string) get_option( 'seopress_pro_license_status', '' ),
		'has_key'           => '' !== $license,
		'key_masked'        => seopress_pro_mask_license_key( $license ),
		'key_length'        => strlen( $license ),
		'defined_in_config' => $defined_in_config,
		'error'             => (string) get_option( 'seopress_pro_license_key_error', '' ),
		'expiry'            => $expiry ? date_i18n( get_option( 'date_format' ), (int) $expiry ) : '',
	);
}

/**
 * Translate an EDD Software Licensing error code into a human message.
 *
 * @param string      $error_code   The EDD error code.
 * @param object|null $license_data The decoded API response, when available.
 *
 * @return string
 */
function seopress_pro_license_error_message( $error_code, $license_data = null ) {
	switch ( $error_code ) {
		case 'expired':
			$expires = isset( $license_data->expires ) ? $license_data->expires : 'now';
			return sprintf(
				/* translators: %s: localized expiration date */
				__( 'Your license key expired on %s.', 'wp-seopress-pro' ),
				date_i18n( get_option( 'date_format' ), strtotime( $expires, current_time( 'timestamp' ) ) )
			);

		case 'disabled':
		case 'revoked':
			return __( 'Your license key has been disabled.', 'wp-seopress-pro' );

		case 'missing':
			return __( 'Invalid license.', 'wp-seopress-pro' );

		case 'invalid':
		case 'site_inactive':
			return __( 'Your license is not active for this URL.', 'wp-seopress-pro' );

		case 'item_name_mismatch':
			/* translators: %s: SEOPress PRO */
			return sprintf( __( 'This appears to be an invalid license key for %s.', 'wp-seopress-pro' ), ITEM_NAME_SEOPRESS );

		case 'no_activations_left':
			return __( 'Your license key has reached its activation limit.', 'wp-seopress-pro' );

		default:
			return __( 'An error occurred, please try again.', 'wp-seopress-pro' );
	}
}

/**
 * Run a license activation request against the store and persist the result.
 *
 * Shared core used by the legacy admin_init handler, the automatic activation
 * callback and the REST endpoint so the three paths behave identically.
 *
 * @param string $license The license key to activate.
 *
 * @return array {
 *     @type bool   $success Whether the license is now valid.
 *     @type string $status  The returned license status ('valid' / 'invalid' / '').
 *     @type string $message Error message on failure, empty on success.
 * }
 */
function seopress_pro_run_license_activation( $license ) {
	$api_params = array(
		'edd_action'  => 'activate_license',
		'license'     => $license,
		'item_id'     => ITEM_ID_SEOPRESS, // The ID of our product in EDD.
		'url'         => get_option( 'home' ),
		'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
	);

	$response = wp_remote_post(
		STORE_URL_SEOPRESS,
		array(
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
			'timeout'    => 15,
			'body'       => $api_params,
		)
	);

	// Make sure the response came back okay.
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		$message = is_wp_error( $response )
			? $response->get_error_message()
			: __( 'An error occurred, please try again. Response code: ', 'wp-seopress-pro' ) . wp_remote_retrieve_response_code( $response );

		return array(
			'success' => false,
			'status'  => '',
			'message' => $message,
		);
	}

	$license_data = json_decode( wp_remote_retrieve_body( $response ) );

	if ( is_object( $license_data ) && isset( $license_data->success ) && false === $license_data->success ) {
		return array(
			'success' => false,
			'status'  => '',
			'message' => seopress_pro_license_error_message( isset( $license_data->error ) ? $license_data->error : '', $license_data ),
		);
	}

	// $license_data->license will be either "valid" or "invalid".
	if ( ! defined( 'SEOPRESS_LICENSE_KEY' ) && isset( $license_data->license ) && 'valid' === $license_data->license ) {
		update_option( 'seopress_pro_license_key', $license );
	}

	update_option( 'seopress_pro_license_status', isset( $license_data->license ) ? $license_data->license : '' );

	// Store the expiry timestamp for condition evaluation.
	if ( isset( $license_data->expires ) && 'lifetime' !== $license_data->expires ) {
		update_option( 'seopress_pro_license_expiry', strtotime( $license_data->expires ), false );
	} elseif ( isset( $license_data->expires ) && 'lifetime' === $license_data->expires ) {
		// Lifetime license - set expiry far in the future.
		update_option( 'seopress_pro_license_expiry', strtotime( '+100 years' ), false );
	}

	// A successful activation clears any previously stored error.
	delete_option( 'seopress_pro_license_key_error' );

	// Invalidate EDD updater caches so the new license key is used for updates.
	seopress_clear_edd_updater_cache();

	return array(
		'success' => isset( $license_data->license ) && 'valid' === $license_data->license,
		'status'  => isset( $license_data->license ) ? $license_data->license : '',
		'message' => '',
	);
}

/**
 * Run a license deactivation request and clean up local state.
 *
 * Local state is cleared regardless of the store response: "failed" means the
 * activation was not found remotely (site URL change, migration, already
 * removed), and in every case the user's intent is to deactivate locally.
 * Mirrors the WP-CLI command.
 *
 * @return array {
 *     @type bool   $success Whether the store request succeeded.
 *     @type string $message Error message on transport failure, empty otherwise.
 * }
 */
function seopress_pro_run_license_deactivation() {
	$license = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY ) ? SEOPRESS_LICENSE_KEY : trim( (string) get_option( 'seopress_pro_license_key' ) );

	$response = wp_remote_post(
		STORE_URL_SEOPRESS,
		array(
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
			'timeout'    => 15,
			'body'       => array(
				'edd_action'  => 'deactivate_license',
				'license'     => $license,
				'item_id'     => ITEM_ID_SEOPRESS, // The ID of our product in EDD.
				'url'         => get_option( 'home' ),
				'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array(
			'success' => false,
			'message' => is_wp_error( $response ) ? $response->get_error_message() : __( 'An error occurred, please try again.', 'wp-seopress-pro' ),
		);
	}

	// Clean up local state regardless of "deactivated" / "failed".
	delete_option( 'seopress_pro_license_status' );
	delete_option( 'seopress_pro_license_expiry' );
	delete_option( 'seopress_pro_license_key_error' );

	// Stop the weekly validation cron from re-activating a license the user
	// just deactivated, and let automatic (wp-config.php) activation run again
	// only if the site URL changes later.
	delete_option( 'seopress_pro_license_automatic_attempt' );

	// Invalidate EDD updater caches to prevent stale package URLs.
	seopress_clear_edd_updater_cache();

	return array(
		'success' => true,
		'message' => '',
	);
}

/**
 * Reset all local license state.
 *
 * @return void
 */
function seopress_pro_run_license_reset() {
	delete_option( 'seopress_pro_license_status' );
	delete_option( 'seopress_pro_license_key' );
	delete_option( 'seopress_pro_license_key_error' );
	delete_option( 'seopress_pro_license_automatic_attempt' );
	delete_option( 'seopress_pro_license_home_url' );
	delete_option( 'seopress_pro_license_expiry' );

	seopress_clear_edd_updater_cache();
}

/**
 * Sanitize license key.
 *
 * Registered as the sanitize callback for the `seopress_pro_license_key`
 * setting. Kept here (always loaded in admin) rather than in the page view.
 *
 * @param string $new_key The new license key.
 *
 * @return string The sanitized license key.
 */
function seopress_sanitize_license( $new_key ) {
	$old = get_option( 'seopress_pro_license_key' );
	if ( $old && $old !== $new_key ) {
		delete_option( 'seopress_pro_license_status' ); // New license has been entered, so must reactivate.
	}
	if ( '********************************' === $new_key ) {
		return $old;
	}
	return $new_key;
}

/**
 * Automatic license key activation callback.
 *
 * @return void
 */
function seopress_automatic_license_activation() {
	if ( ! current_user_can( seopress_capability( 'manage_options', 'license' ) ) ) {
		return;
	}

	// Get license key from SEOPRESS_LICENSE_KEY define callback.
	$license = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY ) ? SEOPRESS_LICENSE_KEY : null;
	if ( ! isset( $license ) ) {
		return;
	}

	// Handle domain name changes (local / staging / live site) callback.
	$prev_home_url = get_option( 'seopress_pro_license_home_url' );
	if ( empty( $prev_home_url ) ) {
		update_option( 'seopress_pro_license_home_url', get_option( 'home' ) );
	}
	if ( isset( $prev_home_url ) && get_option( 'home' ) !== $prev_home_url ) {
		delete_option( 'seopress_pro_license_status' );
		delete_option( 'seopress_pro_license_automatic_attempt' );
		update_option( 'seopress_pro_license_home_url', get_option( 'home' ) );
	} else {
		return false;
	}

	// Already activated?
	if ( 'valid' === get_option( 'seopress_pro_license_status' ) ) {
		return;
	}

	// Already executed?
	if ( get_option( 'seopress_pro_license_automatic_attempt' ) === '1' ) {
		return;
	}
	update_option( 'seopress_pro_license_automatic_attempt', 1 );

	$result = seopress_pro_run_license_activation( $license );

	// Persist the failure message for display on the License page.
	if ( ! $result['success'] && '' !== $result['message'] ) {
		update_option( 'seopress_pro_license_key_error', $result['message'] );
	}
}
add_action( 'admin_init', 'seopress_automatic_license_activation' );

/**
 * Activate license key callback (legacy non-JS form submit).
 *
 * @return void
 */
function seopress_activate_license() {
	// Listen for our activate button to be clicked.
	if ( ! isset( $_POST['seopress_license_activate'] ) ) {
		return;
	}

	// Run a quick security check.
	if ( ! check_admin_referer( 'seopress_nonce', 'seopress_nonce' ) ) {
		return;
	}

	// Only license managers can change the license state.
	if ( ! current_user_can( seopress_capability( 'manage_options', 'license' ) ) ) {
		return;
	}

	// Retrieve the license from the database.
	$license = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY ) ? SEOPRESS_LICENSE_KEY : trim( (string) get_option( 'seopress_pro_license_key' ) );

	$result = seopress_pro_run_license_activation( $license );

	// Check if anything passed on a message constituting a failure.
	if ( ! $result['success'] && '' !== $result['message'] ) {
		$redirect = add_query_arg(
			array(
				'sl_activation' => 'false',
				'message'       => rawurlencode( $result['message'] ),
			),
			admin_url( 'admin.php?page=' . SEOPRESS_LICENSE_PAGE )
		);

		wp_safe_redirect( $redirect );
		exit();
	}

	wp_safe_redirect( admin_url( 'admin.php?page=' . SEOPRESS_LICENSE_PAGE ) );
	exit();
}
add_action( 'admin_init', 'seopress_activate_license' );

/**
 * Deactivate license key callback (legacy non-JS form submit).
 *
 * @return void
 */
function seopress_deactivate_license() {
	// Listen for our deactivate button to be clicked.
	if ( ! isset( $_POST['seopress_license_deactivate'] ) ) {
		return;
	}

	// Run a quick security check.
	if ( ! check_admin_referer( 'seopress_nonce', 'seopress_nonce' ) ) {
		return;
	}

	// Only license managers can change the license state.
	if ( ! current_user_can( seopress_capability( 'manage_options', 'license' ) ) ) {
		return;
	}

	$result = seopress_pro_run_license_deactivation();

	if ( ! $result['success'] ) {
		$redirect = add_query_arg(
			array(
				'sl_activation' => 'false',
				'message'       => rawurlencode( $result['message'] ),
			),
			admin_url( 'admin.php?page=' . SEOPRESS_LICENSE_PAGE )
		);

		wp_safe_redirect( $redirect );
		exit();
	}

	$redirect = add_query_arg( array( 'sl_activation' => 'deactivated' ), admin_url( 'admin.php?page=' . SEOPRESS_LICENSE_PAGE ) );

	wp_safe_redirect( $redirect );
	exit();
}
add_action( 'admin_init', 'seopress_deactivate_license' );
