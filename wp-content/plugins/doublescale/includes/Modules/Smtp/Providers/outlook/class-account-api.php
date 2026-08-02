<?php
/**
 * Account_API class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Outlook;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Account_API class.
 *
 * @since 1.0.0
 */
class Account_API {

	/**
	 * Provider
	 *
	 * @var App
	 */
	private $app;

	/**
	 * Access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Refresh token
	 *
	 * @var string
	 */
	private $refresh_token;

	/**
	 * Account ID
	 *
	 * @var string
	 */
	private $account_id;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param App    $app Provider.
	 * @param string $account_id Account id.
	 * @param array  $account_data Account data.
	 */
	public function __construct( $app, $account_id, $account_data ) {
		$this->app           = $app;
		$this->access_token  = $account_data['credentials']['access_token'] ?? '';
		$this->refresh_token = $account_data['credentials']['refresh_token'] ?? '';
		$this->account_id    = $account_id;
	}

	/**
	 * Refresh tokens
	 *
	 * @return boolean
	 */
	private function refresh_tokens() {
		$tokens = $this->app->refresh_tokens( $this->account_id, $this->refresh_token );

		if ( ! is_array( $tokens ) ) {
			return false;
		}

		$this->access_token  = $tokens['access_token'];
		$this->refresh_token = $tokens['refresh_token'];
		return true;
	}

	/**
	 * Send email
	 *
	 * @param array $args Email arguments.
	 *
	 * @return WP_Error|array
	 */
	public function send( $args, $is_retry = false ) {
		$response = wp_remote_request(
			'https://graph.microsoft.com/v1.0/me/sendMail',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'  => 'text/plain',
					'Authorization' => 'Bearer ' . $this->access_token,
				),
				'body'    => $args,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body             = wp_remote_retrieve_body( $response );
		$response_message = wp_remote_retrieve_response_message( $response );
		$response_code    = wp_remote_retrieve_response_code( $response );

		// A 401 means the token was rejected. Refresh and retry EXACTLY ONCE.
		// A persistent 401 (e.g. a consumer-account opaque token that Graph never
		// accepts) must surface as an error, not recurse — otherwise each retry is
		// a fresh 60s request and the caller appears to hang indefinitely.
		if ( 401 === (int) $response_code ) {
			if ( $is_retry ) {
				return new WP_Error(
					'send_auth_error',
					__( 'Outlook rejected the access token (HTTP 401) after a refresh. The mailbox may not be provisioned for this connection.', 'doublescale' )
				);
			}

			$refreshed = $this->refresh_tokens();
			if ( ! $refreshed ) {
				return new WP_Error( 'refresh_error', __( 'Could not refresh tokens.', 'doublescale' ) );
			}
			return $this->send( $args, true );
		}

		$body = json_decode( $body, true );

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( 'send_error', wp_json_encode( $body['error'] ) );
		}

		return $response_message;
	}

	/**
	 * Get user profile
	 *
	 * @return object|WP_Error
	 */
	public function get_profile() {
		$response = wp_remote_get(
			'https://graph.microsoft.com/v1.0/me',
			array(
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body          = wp_remote_retrieve_body( $response );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code == 401 ) {
			$refreshed = $this->refresh_tokens();
			if ( ! $refreshed ) {
				return new WP_Error( 'refresh_error', __( 'Could not refresh tokens.', 'doublescale' ) );
			}
			return $this->get_profile();
		}

		if ( empty( $body ) ) {
			return new WP_Error( 'empty_response', __( 'Empty response.', 'doublescale' ) );
		}

		$body = json_decode( $body );

		if ( ! is_object( $body ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response.', 'doublescale' ) );
		}

		return $body;
	}
}
