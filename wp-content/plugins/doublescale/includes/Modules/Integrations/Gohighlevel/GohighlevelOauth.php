<?php
/**
 * GoHighLevel OAuth handler (import + REST flows).
 *
 * @package DoubleScale\Modules\Integrations\Gohighlevel
 */

namespace DoubleScale\Modules\Integrations\Gohighlevel;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GoHighLevel OAuth Handler
 */
class GohighlevelOauth {

	/**
	 * OAuth provider name
	 */
	const PROVIDER = 'gohighlevel';

	/**
	 * GoHighLevel OAuth URLs
	 */
	const AUTHORIZE_URL = 'https://marketplace.gohighlevel.com/oauth/chooselocation';
	const TOKEN_URL     = 'https://services.leadconnectorhq.com/oauth/token';
	const API_BASE      = 'https://services.leadconnectorhq.com';

	/**
	 * Whether admin hooks were registered (avoid duplicate admin_init callbacks).
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Class constructor - Initialize admin hooks
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_authorize' ) );
		add_action( 'admin_init', array( $this, 'maybe_add_account' ) );
	}

	/**
	 * Initialize OAuth handling (for static access).
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		new self();
	}

	/**
	 * Get redirect URI
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin.php' );
	}

	/**
	 * Get OAuth authorization URL
	 *
	 * @param string $client_id The client ID from session credentials
	 * @return string Authorization URL
	 */
	public static function get_authorization_url( $client_id ) {
		set_transient( 'doublescale_ghl_temp_client_id', $client_id, 600 );

		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $client_id,
				'scope'         => 'contacts.readonly',
				'redirect_uri'  => self::get_redirect_uri(),
				'state'         => 'doublescale-ghl',
			),
			self::AUTHORIZE_URL
		);
	}

	/**
	 * Redirect the user to authorization page
	 *
	 * @return void
	 */
	public function maybe_authorize() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public OAuth entry point: GHL only fires this when the admin clicks "Authorize"; subsequent admin-level access is enforced before any persistent state is written.
		$action = isset( $_GET['doublescale-ghl'] ) ? sanitize_text_field( wp_unslash( $_GET['doublescale-ghl'] ) ) : ( isset( $_GET['ds-ghl'] ) ? sanitize_text_field( wp_unslash( $_GET['ds-ghl'] ) ) : null );
		if ( $action !== 'authorize' ) {
			return;
		}

		$client_id     = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$client_secret = isset( $_GET['client_secret'] ) ? sanitize_text_field( wp_unslash( $_GET['client_secret'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			echo esc_html__( 'Cannot find client credentials!', 'doublescale' );
			exit;
		}

		set_transient( 'doublescale_ghl_temp_client_secret', $client_secret, 600 );

		$auth_url = self::get_authorization_url( $client_id );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect to external OAuth provider
		wp_redirect( esc_url_raw( $auth_url ) );
		exit;
	}

	/**
	 * Add account after authorization
	 *
	 * @return void
	 */
	public function maybe_add_account() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback URL hit by GoHighLevel; the `state` parameter is GHL's CSRF token (we set it during authorize) and is validated below.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( $state !== 'doublescale-ghl' && $state !== 'ds-ghl' ) {
			return;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( empty( $code ) ) {
			echo esc_html__( 'Error, There is no authorize code passed!', 'doublescale' );
			exit;
		}

		$client_id     = get_transient( 'doublescale_ghl_temp_client_id' );
		$client_secret = get_transient( 'doublescale_ghl_temp_client_secret' );

		if ( ! $client_id || ! $client_secret ) {
			echo esc_html__( 'Error, OAuth session expired!', 'doublescale' );
			exit;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'grant_type'    => 'authorization_code',
						'code'          => $code,
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'redirect_uri'  => self::get_redirect_uri(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			echo esc_html__( 'Error, Cannot exchange code for tokens!', 'doublescale' );
			exit;
		}

		$tokens = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $tokens['access_token'] ) ) {
			echo esc_html__( 'Error, Invalid token response!', 'doublescale' );
			exit;
		}

		$token_data = array(
			'access_token' => $tokens['access_token'],
			'created_at'   => time(),
			'expires_at'   => time() + 600,
		);

		self::store_tokens( $token_data );

		delete_transient( 'doublescale_ghl_temp_client_id' );
		delete_transient( 'doublescale_ghl_temp_client_secret' );

		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta http-equiv="X-UA-Compatible" content="IE=edge">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title>Authorization Complete</title>
		</head>
		<body>
			<p><?php echo esc_html__( "The account is added successfully. If this window isn't closed automatically. Please close it and refresh your accounts select menu.", 'doublescale' ); ?></p>
			<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML response for OAuth popup that reloads the opener and closes itself; outside WP page lifecycle, wp_enqueue cannot run here. ?>
			<script>
				if ( window.opener ) {
					try {
						window.opener.location.reload();
						window.close();
					} catch(e) {
						console.log('Could not communicate with parent window');
					}
				}
			</script>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Store OAuth tokens in session
	 *
	 * @param array $token_data Token data to store
	 * @return bool
	 */
	public static function store_tokens( $token_data ) {
		$session_key = 'doublescale_ghl_tokens_' . get_current_user_id();
		return set_transient( $session_key, $token_data, 600 );
	}

	/**
	 * Get stored OAuth tokens
	 *
	 * @return array|false
	 */
	public static function get_stored_tokens() {
		$session_key = 'doublescale_ghl_tokens_' . get_current_user_id();
		return get_transient( $session_key );
	}

	/**
	 * Clear stored OAuth tokens
	 *
	 * @return bool
	 */
	public static function clear_stored_tokens() {
		$session_key = 'doublescale_ghl_tokens_' . get_current_user_id();
		return delete_transient( $session_key );
	}
}
