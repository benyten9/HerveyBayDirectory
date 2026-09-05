<?php
/**
 * Google Analytics OAuth handling.
 *
 * Handles the OAuth code exchange callback and logout on the analytics settings page,
 * and provides AJAX endpoints for the React UI.
 *
 * @package SEOPress PRO
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Create a configured Google Client instance.
 *
 * @return \SEOPressPro\Vendor\Google\Client|null
 */
function seopress_ga_oauth_get_client() {
	$client_id     = seopress_get_service( 'GoogleAnalyticsOption' )->getAuthClientId();
	$client_secret = seopress_get_service( 'GoogleAnalyticsOption' )->getAuthSecretId();

	if ( empty( $client_id ) || empty( $client_secret ) ) {
		return null;
	}

	require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . '/vendor/autoload.php';

	$client = new \SEOPressPro\Vendor\Google\Client();
	$client->setApplicationName( 'SEOPress' );
	$client->setClientId( $client_id );
	$client->setClientSecret( $client_secret );
	$client->setRedirectUri( admin_url( 'admin.php?page=seopress-google-analytics' ) );
	$client->setScopes( array( 'https://www.googleapis.com/auth/analytics.readonly' ) );
	$client->setApprovalPrompt( 'force' );
	$client->setAccessType( 'offline' );
	$client->setIncludeGrantedScopes( true );
	$client->setPrompt( 'consent' );

	return $client;
}

/**
 * Build the OAuth state token tying a consent redirect to the user who started it.
 *
 * Without it, the callback below accepts any authorization code handed to it:
 * an attacker who gets a logged-in administrator to load
 * admin.php?page=seopress-google-analytics&code=<their own code> makes the site
 * exchange that code and store the attacker's refresh token, silently swapping
 * the site's Analytics connection to their Google account.
 *
 * @since 10.2.0
 *
 * @return string
 */
function seopress_ga_oauth_state() {
	return wp_create_nonce( 'seopress_ga_oauth_state' );
}

/**
 * Create the Google consent URL, bound to the current user with a state token.
 *
 * @since 10.2.0
 *
 * @param \Google\Client $client The configured client.
 *
 * @return string
 */
function seopress_ga_oauth_build_auth_url( $client ) {
	$client->setState( seopress_ga_oauth_state() );

	return $client->createAuthUrl();
}

/**
 * Handle OAuth code exchange and logout on the analytics settings page.
 *
 * Runs on admin_init so it catches the Google redirect before React renders.
 *
 * @return void
 */
function seopress_ga_oauth_handle_callback() {
	// Only run on the analytics settings page.
	if ( ! isset( $_GET['page'] ) || 'seopress-google-analytics' !== $_GET['page'] ) {
		return;
	}

	if ( ! current_user_can( seopress_capability( 'manage_options', 'analytics' ) ) ) {
		return;
	}

	// Handle logout.
	if ( isset( $_GET['logout'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'seopress-ga-logout' ) ) {
		$options                  = get_option( 'seopress_google_analytics_option_name1', array() );
		$options['refresh_token'] = null;
		$options['access_token']  = null;
		$options['code']          = '';
		$options['debug']         = '';
		update_option( 'seopress_google_analytics_option_name1', $options, 'yes' );
		update_option( 'seopress_google_analytics_lock_option_name', '', 'yes' );

		wp_safe_redirect( admin_url( 'admin.php?page=seopress-google-analytics' ) );
		exit;
	}

	// Handle code exchange from Google OAuth redirect.
	if ( isset( $_GET['code'] ) ) {
		// Only exchange a code for a consent flow this very user started, so a
		// crafted link cannot make the site adopt someone else's Google account.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( ! wp_verify_nonce( $state, 'seopress_ga_oauth_state' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=seopress-google-analytics&seopress_ga_oauth_error=state' ) );
			exit;
		}

		$client = seopress_ga_oauth_get_client();

		if ( null === $client ) {
			return;
		}

		if ( null !== seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getAccessToken() ) {
			return; // Already authenticated.
		}

		$client->authenticate( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
		$token = $client->getAccessToken();

		if ( is_array( $token ) && isset( $token['access_token'] ) ) {
			$options                  = get_option( 'seopress_google_analytics_option_name1', array() );
			$options['access_token']  = $token['access_token'];
			$options['refresh_token'] = isset( $token['refresh_token'] ) ? $token['refresh_token'] : null;
			// Keep the shape of the payload for support, never a second copy of
			// the tokens: they already live in their own keys.
			$options['debug'] = array_diff_key( $token, array_flip( array( 'access_token', 'refresh_token', 'id_token' ) ) );
			$options['code']  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			update_option( 'seopress_google_analytics_option_name1', $options, 'yes' );

			// If a property is already selected (e.g. reconnecting), refresh the
			// dashboard data right away instead of waiting for the hourly cron.
			seopress_ga_schedule_immediate_fetch();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=seopress-google-analytics' ) );
		exit;
	}
}
add_action( 'admin_init', 'seopress_ga_oauth_handle_callback', 5 );

/**
 * Schedule a one-off Google Analytics fetch a few seconds from now.
 *
 * The fetch function (seopress_request_google_analytics_fn) calls wp_send_json()
 * when it is not running under WP-Cron, so it cannot be called inline from an
 * AJAX handler or admin_init without hijacking the response. Scheduling a single
 * cron event runs it in a clean cron context, so the dashboard populates right
 * after connecting / picking a property instead of after the next hourly run.
 *
 * @return void
 */
function seopress_ga_schedule_immediate_fetch() {
	if ( function_exists( 'wp_schedule_single_event' ) ) {
		wp_schedule_single_event( time() + 5, 'seopress_google_analytics_cron' );
	}
}

/**
 * AJAX: Save the OAuth Client ID / Secret ID and return the Google auth URL.
 *
 * Lets the React UI start the OAuth flow without a prior full settings save:
 * it persists the credentials, then hands back the consent URL to redirect to.
 *
 * @return void
 */
function seopress_ga_oauth_save_and_connect() {
	check_ajax_referer( 'seopress_ga_oauth_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'analytics' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seopress-pro' ) ) );
	}

	$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
	$client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';

	if ( '' === $client_id || '' === $client_secret ) {
		wp_send_json_error( array( 'message' => __( 'Please enter both your Client ID and Secret ID first.', 'wp-seopress-pro' ) ) );
	}

	$options = get_option( 'seopress_google_analytics_option_name', array() );
	if ( ! is_array( $options ) ) {
		$options = array();
	}
	$options['seopress_google_analytics_auth_client_id'] = $client_id;
	$options['seopress_google_analytics_auth_secret_id'] = $client_secret;
	update_option( 'seopress_google_analytics_option_name', $options );

	$client = seopress_ga_oauth_get_client();
	if ( null === $client ) {
		wp_send_json_error( array( 'message' => __( 'Could not initialize the Google client. Please check your credentials.', 'wp-seopress-pro' ) ) );
	}

	wp_send_json_success( array( 'auth_url' => seopress_ga_oauth_build_auth_url( $client ) ) );
}
add_action( 'wp_ajax_seopress_ga_oauth_save_and_connect', 'seopress_ga_oauth_save_and_connect' );

/**
 * AJAX: Save the selected GA4 property ID and trigger an immediate fetch.
 *
 * @return void
 */
function seopress_ga_save_property() {
	check_ajax_referer( 'seopress_ga_oauth_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'analytics' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seopress-pro' ) ) );
	}

	$property_id = isset( $_POST['property_id'] ) ? sanitize_text_field( wp_unslash( $_POST['property_id'] ) ) : '';
	$property_id = preg_replace( '/[^0-9]/', '', $property_id );

	$options = get_option( 'seopress_google_analytics_option_name', array() );
	if ( ! is_array( $options ) ) {
		$options = array();
	}
	$options['seopress_google_analytics_ga4_property_id'] = $property_id;
	update_option( 'seopress_google_analytics_option_name', $options );

	if ( '' !== $property_id ) {
		seopress_ga_schedule_immediate_fetch();
	}

	wp_send_json_success( array( 'property_id' => $property_id ) );
}
add_action( 'wp_ajax_seopress_ga_save_property', 'seopress_ga_save_property' );

/**
 * AJAX: Get Google Analytics OAuth status and auth URL.
 *
 * @return void
 */
function seopress_ga_oauth_get_status() {
	check_ajax_referer( 'seopress_ga_oauth_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'analytics' ) ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}

	$access_token = seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getAccessToken();
	$is_connected = null !== $access_token;

	$auth_url   = '';
	$logout_url = '';

	if ( ! $is_connected ) {
		$client = seopress_ga_oauth_get_client();
		if ( null !== $client ) {
			$auth_url = seopress_ga_oauth_build_auth_url( $client );
		}
	} else {
		$logout_url = wp_nonce_url(
			admin_url( 'admin.php?page=seopress-google-analytics&logout=1' ),
			'seopress-ga-logout'
		);
	}

	wp_send_json_success( array(
		'is_connected' => $is_connected,
		'auth_url'     => $auth_url,
		'logout_url'   => $logout_url,
	) );
}
add_action( 'wp_ajax_seopress_ga_oauth_get_status', 'seopress_ga_oauth_get_status' );

/**
 * AJAX: Logout from Google Analytics.
 *
 * @return void
 */
function seopress_ga_oauth_logout() {
	check_ajax_referer( 'seopress_ga_oauth_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'analytics' ) ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}

	$options                  = get_option( 'seopress_google_analytics_option_name1', array() );
	$options['refresh_token'] = null;
	$options['access_token']  = null;
	$options['code']          = '';
	$options['debug']         = '';
	update_option( 'seopress_google_analytics_option_name1', $options, 'yes' );
	update_option( 'seopress_google_analytics_lock_option_name', '', 'yes' );

	wp_send_json_success();
}
add_action( 'wp_ajax_seopress_ga_oauth_logout', 'seopress_ga_oauth_logout' );
