<?php

/**
 * Zoom Meet Integration API
 *
 * This class is responsible for handling the Zoom Meet Integration API
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Zoom;

use DoubleScale\Modules\Booking\Integration\API as Abstract_API;
use Illuminate\Support\Arr;
use Exception;

/**
 * Zoom Integration HTTP client (Zoom REST API).
 */
class Api extends Abstract_API {



	/**
	 * Access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Account id
	 *
	 * @var string
	 */
	private $account_id;

	/**
	 * Integration instance
	 *
	 * @var \DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Integration
	 */
	protected $integration;

	/**
	 * App instance
	 *
	 * @var \DoubleScale\Pro\Modules\Booking\Integrations\Zoom\App
	 */
	private $app;

	/**
	 * Constructor
	 *
	 * @param string                                      $access_token  Access token.
	 * @param \DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Integration $integration   Integration instance.
	 * @param string                                      $refresh_token Refresh token.
	 * @param \DoubleScale\Pro\Modules\Booking\Integrations\Zoom\App         $app           App instance.
	 * @param string                                      $account_id    Account ID.
	 * @since 1.0.0
	 */
	public function __construct( $access_token, $integration, $refresh_token = '', $account_id = null ) {
		parent::__construct( $integration );
		$this->endpoint     = 'https://api.zoom.us/v2';
		$this->access_token = $access_token;
		$this->account_id   = $account_id;
	}

	/**
	 * Get account info
	 *
	 * @return array
	 */
	public function get_account_info() {
		return $this->get( 'users/me' );
	}

	/**
	 * Create a meeting
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function create_meeting( $data ) {
		return $this->post( 'users/me/meetings', $data );
	}

	/**
	 * Update a meeting
	 *
	 * @param string $meeting_id Meeting ID.
	 * @param array  $data Data.
	 *
	 * @return array
	 */
	public function update_meeting( $meeting_id, $data ) {
		return $this->patch( "meetings/$meeting_id", $data );
	}

	/**
	 * Delete a meeting
	 *
	 * @param string $meeting_id Meeting ID.
	 *
	 * @return array
	 */
	public function delete_meeting( $meeting_id ) {
		return $this->delete( "meetings/$meeting_id" );
	}

	/**
	 * Send request to the api.
	 *
	 * @param string      $method Method.
	 * @param string      $path URL.
	 * @param string|null $body Body.
	 * @return array|WP_Error
	 */
	public function request_remote( $method, $path, $body = null ) {
		return wp_remote_request(
			"{$this->endpoint}/$path",
			array(
				'method'  => $method,
				'body'    => $body,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json; charset=' . get_option( 'blog_charset' ),
					'Cache-Control' => 'no-cache',
					'Authorization' => 'Bearer ' . $this->access_token,
				),
				'timeout' => 30,
			)
		);
	}
	/**
	 * Send request to the api.
	 *
	 * @param string      $method Method.
	 * @param string      $path Path.
	 * @param string|null $body Body.
	 * @param boolean     $maybe_refresh_token Refresh token if expired or no.
	 * @return array
	 */
	public function request( $method, $path, $body = null, $maybe_refresh_token = true ) {
		$response = $this->request_remote( $method, $path, $body );

		if ( is_wp_error( $response ) ) {
			return $this->prepare_response(
				false,
				null,
				array(
					'wp_error' => array(
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
					),
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( $response['body'], true );

		if ( $response_code === 401 ) {
			if ( $maybe_refresh_token ) {
				$refreshed = $this->refresh_tokens();
				if ( $refreshed ) {
					// try the request again but don't try to refresh tokens again!
					return $this->request( $method, $path, $body, false );
				}
			}

			return $this->prepare_response(
				false,
				$response_code,
				$response_body
			);
		} elseif ( $response_code >= 300 ) {
			return $this->prepare_response(
				false,
				$response_code,
				$response_body
			);
		}

		unset( $response_body['_links'] );
		return $this->prepare_response(
			true,
			$response_code,
			$response_body
		);
	}

	/**
	 * Refresh tokens
	 *
	 * @return boolean
	 */
	private function refresh_tokens() {
		try {
			// Check if integration and app are available
			if ( ! $this->integration ) {
				return false;
			}

			// Check if accounts is available in integration
			if ( ! isset( $this->integration->accounts ) || ! $this->integration->accounts ) {
				return false;
			}

			// will make handle if $this->account_id == global
			$account = null;
			if ( $this->account_id === 'global' ) {
				// Get global account
				$account = $this->integration->accounts['global'] ?? null;
			} else {
				// Get specific account
				$account = $this->integration->accounts->get_account( $this->account_id );
			}

			if ( empty( $account ) ) {
				return false;
			}

			$app_credentials = Arr::get( $account, 'app_credentials', array() );
			if ( empty( $app_credentials ) ) {
				return false;
			}

			// Log the credentials we're using (only first few characters for security)
			$client_id     = Arr::get( $app_credentials, 'client_id', '' );
			$client_secret = Arr::get( $app_credentials, 'client_secret', '' );

			// Get new tokens
			$tokens = $this->get_tokens( $app_credentials );

			// Check if we got tokens
			if ( empty( $tokens ) || ! isset( $tokens['access_token'] ) ) {
				return false;
			}

			// Log only the first part of the token for security

			// Update the account with new tokens
			if ( $this->account_id === 'global' ) {
				$this->integration->update_setting( 'tokens', $tokens );
				// Check if update was successful
				$updated_account = $this->integration->get_settings();
				if ( empty( $updated_account ) || empty( $updated_account['tokens']['access_token'] ) ) {
					return false;
				}
			} else {
				// Update specific account
				$this->integration->accounts->update_account(
					$this->account_id,
					array(
						'tokens' => $tokens,
					)
				);
				// Check if update was successful
				$updated_account = $this->integration->accounts->get_account( $this->account_id );

				// Check the access token inside the updated account
				if ( empty( $updated_account ) || empty( $updated_account['tokens']['access_token'] ) ) {
					return false;
				}
			}
			// Set the new access token for immediate use
			$this->access_token = $tokens['access_token'];
			return true;
		} catch ( \Exception $e ) {
			error_log( 'Zoom Integration Debug - Exception during token refresh: ' . $e->getMessage() );
			error_log( 'Zoom Integration Debug - Exception trace: ' . $e->getTraceAsString() );
			return false;
		}
	}

	/**
	 * Get tokens
	 *
	 * @param array $app_credentials App credentials.
	 * @return array
	 */
	private function get_tokens( $app_credentials ) {
		// Direct API request as fallback

		$client_id     = Arr::get( $app_credentials, 'client_id' );
		$client_secret = Arr::get( $app_credentials, 'client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			throw new \Exception( 'Missing client_id or client_secret' );
		}

		$response = \wp_remote_post(
			'https://zoom.us/oauth/token',
			array(
				'body'    => array(
					'grant_type' => 'account_credentials',
					'account_id' => Arr::get( $app_credentials, 'account_id' ),
				),
				'headers' => array(
					'Authorization' => 'Basic ' . \base64_encode( Arr::get( $app_credentials, 'client_id' ) . ':' . Arr::get( $app_credentials, 'client_secret' ) ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			throw new \Exception( $error_message );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

		$data = json_decode( $body, true );

		if ( empty( $data ) || ! isset( $data['access_token'] ) ) {
			throw new \Exception( __( 'Invalid response from Zoom.', 'doublescale' ) );
		}

		return $data;
	}
}
