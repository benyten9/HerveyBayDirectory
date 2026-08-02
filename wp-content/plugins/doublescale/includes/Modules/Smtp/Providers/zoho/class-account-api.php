<?php
/**
 * Account_API class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Zoho;

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
	 * Region
	 *
	 * @var string
	 */
	private $region;

	/**
	 * URL
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Account id
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
		$this->access_token  = $account_data['credentials']['access_token'];
		$this->refresh_token = $account_data['credentials']['refresh_token'] ?? null;
		$this->region        = $app->get_app_credentials()['region'];
		$this->account_id    = $account_id;
		$this->url           = "https://mail.zoho.{$this->region}/api/accounts/{$this->account_id}";
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
	public function send( $args ) {
		$response = wp_remote_request(
			$this->url . '/messages',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Zoho-oauthtoken ' . $this->access_token,
					'Content-Type'  => 'application/json; charset=' . get_option( 'blog_charset' ),
					'Cache-Control' => 'no-cache',
				),
				'body'    => wp_json_encode( $args ),
				'timeout' => 60,
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
			return $this->send( $args );
		}

		$body = json_decode( $body, true );

		if ( $body['status']['code'] != 200 ) {
			return new WP_Error( 'send_error', wp_json_encode( $body['data'] ) );
		}

		return $body;
	}

	/**
	 * Upload attachment
	 *
	 * @param string $filename Filename.
	 * @param array  $args args.
	 *
	 * @return WP_Error|array
	 */
	public function upload_attachment( $filename, $args ) {
		$url = add_query_arg(
			'fileName',
			$filename,
			$this->url . '/messages/attachments'
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Zoho-oauthtoken ' . $this->access_token,
					'Content-Type'  => 'application/octet-stream',
				),
				'body'    => $args,
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
			return $this->upload_attachment( $url, $args );
		}

		$body = json_decode( $body, true );

		if ( $body['status']['code'] != 200 ) {
			return new WP_Error( 'upload_error', wp_json_encode( $body['data'] ) );
		}

		return $body;
	}

	/**
	 * Get user accounts
	 *
	 * @return object|WP_Error
	 */
	public function get_accounts() {
		$response = wp_remote_get(
			"https://mail.zoho.{$this->region}/api/accounts",
			array(
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Zoho-oauthtoken ' . $this->access_token,
					'Content-Type'  => 'application/json; charset=' . get_option( 'blog_charset' ),
					'Cache-Control' => 'no-cache',
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
