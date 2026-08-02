<?php
/**
 * App class.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Slack;

/**
 * App class.
 *
 * @since 1.0.0
 */
class App {

	/**
	 * Provider
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Integration $integration Provider.
	 */
	public function __construct( $integration ) {
		$this->integration = $integration;

		add_action( 'admin_init', array( $this, 'maybe_add_settings' ) );
	}

	/**
	 * Redirect the user to authorization page
	 *
	 * @param array $app_credentials App credentials.
	 *
	 * @return string|\WP_Error
	 */
	public function get_auth_uri( $app_credentials = array() ) {
		if ( empty( $app_credentials ) ) {
			$app_credentials = $this->get_app_credentials();
		}

		if ( empty( $app_credentials ) ) {
			return new \WP_Error( 'no_app_credentials', esc_html__( 'No app credentials found!', 'doublescale') );
		}

		$auth_url = add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $app_credentials['client_id'],
				'scope'         => 'users:read,channels:read,groups:read,im:read,mpim:read,chat:write,chat:write.public',
				'redirect_uri'  => urlencode( $this->get_redirect_uri() ),
				'state'         => 'doublescale-slack',
			),
			'https://slack.com/oauth/v2/authorize'
		);

		return $auth_url;
	}

	/**
	 * Add settings after authorization
	 *
	 * @return void
	 */
	public function maybe_add_settings() {
		$state = $_GET['state'] ?? '';
		if ( $state !== 'doublescale-slack' && $state !== 'ds-slack' ) {
			return;
		}

		// ensure authorize code.
		$code = $_GET['code'] ?? null;
		if ( empty( $code ) ) {
			echo esc_html__( 'Error, There is no authorize code passed!', 'doublescale');
			exit;
		}

		// get tokens.
		$tokens = $this->get_tokens(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => $this->get_redirect_uri(),
			)
		);

		if ( empty( $tokens ) ) {
			echo esc_html__( 'Error, Cannot get tokens!', 'doublescale');
			exit;
		}

		$this->integration->update_setting(
			'credentials',
			$tokens
		);

		// Redirect to settings page.
		wp_redirect(
			admin_url(
				"admin.php?page=doublescale&path=integrations&id={$this->integration->slug}&tab=success"
			)
		);
		exit;
	}

	/**
	 * Refresh tokens
	 *
	 * @param string|null $account_id Account ID (not used for Slack).
	 * @param string|null $refresh_token Refresh token.
	 * @return array|false
	 */
	public function refresh_tokens( $account_id = null, $refresh_token = null ) {
		if ( empty( $refresh_token ) ) {
			$credentials   = $this->integration->get_setting( 'credentials', array() );
			$refresh_token = $credentials['refresh_token'] ?? '';
		}

		$refeshed_tokens = $this->get_tokens(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			)
		);

		if ( empty( $refeshed_tokens ) ) {
			return false;
		}

		$tokens                 = $this->integration->get_setting( 'credentials', array() );
		$tokens['access_token'] = $refeshed_tokens['access_token'];

		$updated = $this->integration->update_setting(
			'credentials',
			$tokens
		);
		if ( empty( $updated ) ) {
			return false;
		}
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return $tokens;
	}

	/**
	 * Get tokens
	 *
	 * @param array $query Query to get tokens.
	 * @return boolean|array
	 */
	public function get_tokens( $query ) {
		$response = wp_remote_post(
			add_query_arg( $query, 'https://slack.com/api/oauth.v2.access' ),
			array(
				'headers' => array(
					'Authorization' => $this->get_authorization_header(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$tokens = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $tokens['access_token'] ) ) {

			// log in case of first request.
			if ( $query['grant_type'] === 'authorization_code' && empty( $tokens['refresh_token'] ) ) {
				return false;
			}

			return false;
		}

		return $tokens;
	}

	/**
	 * Get app credentials
	 *
	 * @return array|false Array of client_id & client_secret. false on failure.
	 */
	public function get_app_credentials() {
		$app_settings = $this->integration->get_setting( 'app' ) ?? array();
		if ( empty( $app_settings['client_id'] ) || empty( $app_settings['client_secret'] ) ) {
			return false;
		} else {
			return $app_settings;
		}
	}

	/**
	 * Get Authorization header
	 *
	 * @return string
	 */
	private function get_authorization_header() {
		$app_settings = $this->integration->get_setting( 'app' ) ?? array();
		return 'Basic ' . base64_encode( $app_settings['client_id'] . ':' . $app_settings['client_secret'] );
	}

	/**
	 * Get redirect uri
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		return admin_url( 'admin.php' );
	}

}
