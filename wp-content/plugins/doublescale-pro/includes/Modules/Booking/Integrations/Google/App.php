<?php

/**
 * App class.
 *
 * @since 1.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Google;

use Illuminate\Support\Arr;
use DoubleScale\Pro\Modules\Booking\Integrations\OAuthConfig;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use WP_Error;

/**
 * App class.
 *
 * @since 1.0.0
 */
class App {
	/**
	 * State prefix for Google authentication
	 *
	 * @var string
	 */
	const STATE_PREFIX = 'doublescale-booking-g-';

	/**
	 * Provider
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * Auth proxy URL - Update this to point to your secure proxy server
	 *
	 * @var string
	 */
	protected $auth_proxy_url;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Integration $integration Provider.
	 */
	public function __construct( $integration ) {
		$this->integration    = $integration;
		$this->auth_proxy_url = OAuthConfig::google_auth_proxy_url();

		\add_action( 'admin_init', array( $this, 'maybe_add_settings' ) );
	}

	/**
	 * Integration instance (exposed for API client construction).
	 *
	 * @return Integration
	 */
	public function get_integration() {
		return $this->integration;
	}

	/**
	 * Normalize OAuth token arrays from Google or the booking proxy (snake_case vs camelCase).
	 *
	 * @param array<string, mixed> $tokens Raw token payload.
	 * @return array<string, mixed>
	 */
	public static function normalize_token_array( array $tokens ): array {
		if ( empty( $tokens['access_token'] ) && ! empty( $tokens['accessToken'] ) ) {
			$tokens['access_token'] = $tokens['accessToken'];
		}
		if ( empty( $tokens['refresh_token'] ) && ! empty( $tokens['refreshToken'] ) ) {
			$tokens['refresh_token'] = $tokens['refreshToken'];
		}
		return $tokens;
	}

	/**
	 * Redirect the user to authorization page
	 *
	 * @param int   $host_id Host ID.
	 * @param array $app_credentials App credentials.
	 *
	 * @return string|WP_Error Auth URL or error.
	 */
	public function get_auth_uri( $host_id, $app_credentials = array() ) {
		if ( empty( $host_id ) ) {
			return new \WP_Error( 'no_host_id', \esc_html__( 'No host ID found!', 'doublescale' ) );
		}

		// Check if we have custom app credentials
		$custom_credentials = $this->get_app_credentials();
		$use_custom_app     = ! empty( $custom_credentials );

		$scopes = array(
			'https://www.googleapis.com/auth/userinfo.email',
			'https://www.googleapis.com/auth/calendar.readonly',
			'https://www.googleapis.com/auth/calendar.events',
		);

		if ( $use_custom_app ) {
			// Use direct authentication with custom credentials
			$auth_url = \add_query_arg(
				array(
					'response_type' => 'code',
					'client_id'     => $custom_credentials['client_id'],
					'scope'         => \urlencode_deep( implode( ' ', $scopes ) ),
					'redirect_uri'  => \urlencode( $this->get_redirect_uri() ),
					'state'         => self::STATE_PREFIX . $host_id,
					'prompt'        => 'consent',
					'access_type'   => 'offline',
				),
				'https://accounts.google.com/o/oauth2/v2/auth'
			);

			return $auth_url;
		} else {
			// Use the secure proxy
			$response = \wp_remote_post(
				$this->auth_proxy_url,
				OAuthConfig::prepare_proxy_remote_post_args(
					array(
						'headers' => array(
							'Content-Type' => 'application/json',
						),
						'body'    => \json_encode(
							array(
								'action'       => 'get_auth_url',
								'host_id'      => $host_id,
								'redirect_uri' => $this->get_redirect_uri(),
								'scopes'       => implode( ' ', $scopes ),
								'state'        => self::STATE_PREFIX . $host_id . '-' . OAuthConfig::encode_booking_oauth_return_url( admin_url() ),
							)
						),
					),
					$this->auth_proxy_url
				)
			);

			if ( \is_wp_error( $response ) ) {
				return OAuthConfig::new_proxy_transport_wp_error( $this->auth_proxy_url, $response );
			}

			$http_code = (int) \wp_remote_retrieve_response_code( $response );
			if ( $http_code < 200 || $http_code >= 300 ) {
				return OAuthConfig::new_proxy_http_wp_error(
					$this->auth_proxy_url,
					$http_code,
					(string) \wp_remote_retrieve_body( $response )
				);
			}

			$body = \json_decode( \wp_remote_retrieve_body( $response ), true );

			if ( empty( $body ) || isset( $body['error'] ) ) {
				return new \WP_Error( 'proxy_error', isset( $body['error'] ) ? $body['error'] : \esc_html__( 'Unknown error from authentication server!', 'doublescale' ) );
			}

			$url = isset( $body['auth_url'] ) ? $body['auth_url'] : ( $body['auth_uri'] ?? '' );
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				return new \WP_Error(
					'proxy_error',
					\esc_html__( 'OAuth proxy did not return a valid authorization URL.', 'doublescale' ),
					array( 'status' => 502 )
				);
			}

			return $url;
		}
	}

	/**
	 * Add settings after authorization
	 *
	 * @return void
	 */
	public function maybe_add_settings() {
		$state = $_GET['state'] ?? '';
		if ( strpos( $state, self::STATE_PREFIX ) !== 0 ) {
			return;
		}

		$host_id = self::parse_state_host_id( $state );
		if ( null === $host_id ) {
			return;
		}
		$calendar = CalendarModel::find( $host_id );
		if ( empty( $calendar ) ) {
			return;
		}

		// ensure authorize code.
		$code = $_GET['code'] ?? null;
		if ( empty( $code ) ) {
			echo \esc_html__( 'Error, There is no authorize code passed!', 'doublescale' );
			exit;
		}

		// Check if we have custom app credentials
		$custom_credentials = $this->get_app_credentials();
		$use_custom_app     = ! empty( $custom_credentials );

		$tokens = array();

		if ( $use_custom_app ) {
			// Use direct token exchange with custom credentials
			$tokens = $this->get_tokens(
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $custom_credentials['client_id'],
					'client_secret' => $custom_credentials['client_secret'],
					'redirect_uri'  => $this->get_redirect_uri(),
				)
			);
		} else {
			// Request tokens from the secure proxy
			$response = \wp_remote_post(
				$this->auth_proxy_url,
				OAuthConfig::prepare_proxy_remote_post_args(
					array(
						'headers' => array(
							'Content-Type' => 'application/json',
						),
						'body'    => \json_encode(
							array(
								'action'       => 'get_tokens',
								'code'         => $code,
								'redirect_uri' => $this->get_redirect_uri(),
							)
						),
					),
					$this->auth_proxy_url
				)
			);

			if ( \is_wp_error( $response ) ) {
				$err = OAuthConfig::new_proxy_transport_wp_error( $this->auth_proxy_url, $response );
				echo \esc_html( $err->get_error_message() );
				exit;
			}

			$http_code = (int) \wp_remote_retrieve_response_code( $response );
			if ( $http_code < 200 || $http_code >= 300 ) {
				$err = OAuthConfig::new_proxy_http_wp_error(
					$this->auth_proxy_url,
					$http_code,
					(string) \wp_remote_retrieve_body( $response )
				);
				echo \esc_html( $err->get_error_message() );
				exit;
			}

			$tokens = \json_decode( \wp_remote_retrieve_body( $response ), true );
		}

		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}
		$tokens = self::normalize_token_array( $tokens );

		if ( empty( $tokens ) || isset( $tokens['error'] ) || empty( $tokens['access_token'] ) ) {
			echo \esc_html__( 'Error, Cannot get tokens!', 'doublescale' );
			exit;
		}

		$account_data = new API( $tokens['access_token'], $tokens['refresh_token'] ?? null, $this );
		$account_data = $account_data->get_account_info();

		if ( ! $account_data['success'] ) {
			echo \esc_html__( 'Error, Cannot get account data!', 'doublescale' );
			exit;
		}

		$account      = $account_data;
		$account_id   = Arr::get( $account, 'data.id' );
		$account_name = Arr::get( $account, 'data.email' );
		if ( empty( $account_id ) || empty( $account_name ) ) {
			echo \esc_html__( 'Error, Cannot get account ID!', 'doublescale' );
			exit;
		}

		// Check if account already exists
		$this->integration->set_host( $host_id );
		if ( ! is_numeric( $account_id ) ) {
			$hash       = crc32( $account_id );
			$account_id = abs( $hash );
		}
		$existing_account = $this->integration->accounts->get_account( $account_id );
		if ( ! empty( $existing_account ) ) {
			echo \esc_html__( 'Error, This account is already connected!', 'doublescale' );
			\wp_redirect(
				\admin_url(
					'admin.php?page=doublescale&path=booking/calendars'
				)
			);
			exit;
		}

		$this->integration->accounts->add_account(
			$account_id,
			array(
				'name'   => $account_name,
				'tokens' => $tokens,
				'config' => array(),
			)
		);

		// Redirect to settings page.
		echo \esc_html__( 'Success, Account added!', 'doublescale' );
		\wp_redirect(
			\admin_url(
				'admin.php?page=doublescale&path=booking/calendars'
			)
		);
		exit;
	}

	/**
	 * Refresh tokens
	 *
	 * @param string|null $refresh_token Refresh token.
	 * @param string|null $account_id Account ID.
	 * @return array|false|WP_Error
	 */
	public function refresh_tokens( $refresh_token = null, $account_id = null ) {
		if ( empty( $refresh_token ) ) {
			return false;
		}

		// Check if we have custom app credentials
		$custom_credentials = $this->get_app_credentials();
		$use_custom_app     = ! empty( $custom_credentials );

		$refreshed_tokens = array();

		if ( $use_custom_app ) {
			// Use direct token refresh with custom credentials
			$refreshed_tokens = $this->get_tokens(
				array(
					'client_id'     => $custom_credentials['client_id'],
					'client_secret' => $custom_credentials['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				)
			);
		} else {
			// Request token refresh from the secure proxy
			$response = \wp_remote_post(
				$this->auth_proxy_url,
				OAuthConfig::prepare_proxy_remote_post_args(
					array(
						'headers' => array(
							'Content-Type' => 'application/json',
						),
						'body'    => \json_encode(
							array(
								'action'        => 'refresh_tokens',
								'refresh_token' => $refresh_token,
							)
						),
					),
					$this->auth_proxy_url
				)
			);

			if ( \is_wp_error( $response ) ) {
				return false;
			}

			$refreshed_tokens = \json_decode( \wp_remote_retrieve_body( $response ), true );
		}

		if ( ! is_array( $refreshed_tokens ) ) {
			$refreshed_tokens = array();
		}
		$refreshed_tokens = self::normalize_token_array( $refreshed_tokens );

		if ( empty( $refreshed_tokens ) || isset( $refreshed_tokens['error'] ) || empty( $refreshed_tokens['access_token'] ) ) {
			return false;
		}

		$account_data           = $this->integration->accounts->get_account( $account_id );
		$tokens                 = Arr::get( $account_data, 'tokens', array() );
		$tokens['access_token'] = Arr::get( $refreshed_tokens, 'access_token' );

		$this->integration->accounts->update_account(
			$account_id,
			array(
				'tokens' => $tokens,
			)
		);

		return $tokens;
	}

	/**
	 * Get tokens
	 *
	 * @param array $query Query to get tokens.
	 * @return boolean|array
	 */
	public function get_tokens( $query ) {
		$response = \wp_remote_post(
			'https://accounts.google.com/o/oauth2/token',
			array(
				'body' => $query,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return false;
		}

		$tokens = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $tokens ) ) {
			return false;
		}

		$tokens = self::normalize_token_array( $tokens );

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
	 * @return array|false Array of client_id & client_secret. false if not configured.
	 */
	public function get_app_credentials() {
		 $app_settings = $this->integration->get_setting( 'app' ) ?? array();

		// Early return if app is empty
		if (
			empty( $app_settings ) || ! isset( $app_settings['client_id'] ) || ! isset( $app_settings['client_secret'] ) ||
			( isset( $app_settings['app'] ) && empty( $app_settings['app'] ) )
		) {
			return false;
		}

		// Return credentials if both client_id and client_secret are provided
		if ( ! empty( $app_settings['client_id'] ) && ! empty( $app_settings['client_secret'] ) ) {
			return array(
				'client_id'     => $app_settings['client_id'],
				'client_secret' => $app_settings['client_secret'],
			);
		}

		return false;
	}


	/**
	 * Get redirect uri
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		return OAuthConfig::callback_url();
	}

	/**
	 * Host ID from proxy or custom-app OAuth state (`prefix` + digits [ + suffix ]).
	 */
	private static function parse_state_host_id( string $state ): ?string {
		if ( strpos( $state, self::STATE_PREFIX ) !== 0 ) {
			return null;
		}
		$rest = substr( $state, strlen( self::STATE_PREFIX ) );
		return preg_match( '/^(\d+)/', $rest, $m ) ? $m[1] : null;
	}
}
