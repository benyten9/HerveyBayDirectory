<?php
/**
 * Class Keap Api
 *
 * This class is responsible for handling the Keap Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Keap;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Keap Api class
 */
class Api extends IntegrationApi {

	/**
	 * App
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
	 * Constructor
	 *
	 * @param string $access_token Access token.
	 * @param string $refresh_token Refresh token.
	 * @param App    $app App.
	 * @since 1.0.0
	 */
	public function __construct( $access_token, $refresh_token, $app = null ) {
		$this->endpoint      = 'https://api.infusionsoft.com/crm/rest/v1';
		$this->app           = $app;
		$this->access_token  = $access_token;
		$this->refresh_token = $refresh_token;
	}

	/**
	 * Get account.
	 *
	 * @return array
	 */
	public function get_account() {
		return $this->get( 'account/profile' );
	}

	/**
	 * Get tags.
	 *
	 * @return array
	 */
	public function get_tags() {
		return $this->get( 'tags' );
	}

	/**
	 * Create or update contact.
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function create_or_update( $data ) {
		return $this->put( 'contacts', $data );
	}

	/**
	 * Get fields.
	 *
	 * @return array
	 */
	public function get_fields() {
		return $this->get( 'contacts/model' );
	}

	/**
	 * Get or create contact.
	 *
	 * @param string $email Email.
	 *
	 * @return array
	 */
	public function get_or_create_contact( $email ) {
		$result = $this->get_contact( $email );
		if ( $result['success'] ) {
			return $result;
		}

		$data = array(
			'email_addresses'  => array(
				array(
					'email' => $email,
					'field' => 'EMAIL1',
				),
			),
			'duplicate_option' => 'Email',
		);

		return $this->post( 'contacts', $data );
	}

	/**
	 * Get contact.
	 *
	 * @param string $email Email.
	 *
	 * @return array
	 */
	public function get_contact( $email ) {
		$result = $this->get( 'contacts', array( 'email' => $email ) );
		if ( ! empty( $result['data']['contacts'] ?? array() ) ) {
			$result['data'] = $result['data']['contacts'][0];
			return $result;
		}

		return $this->prepare_response( false, 404, array() );
	}

	/**
	 * Remove tags.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function remove_tags( $contact_id, $data ) {
		return $this->delete( "contacts/$contact_id/tags?idoublescale=" . implode( ',', $data['ids'] ) );
	}

	/**
	 * Add tags.
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function add_tags( $contact_id, $data ) {
		return $this->post( "contacts/$contact_id/tags", $data );
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
		$tokens = $this->app->refresh_tokens( $this->account_id, $this->refresh_token );
		if ( ! is_array( $tokens ) ) {
			return false;
		}

		$this->access_token  = $tokens['access_token'];
		$this->refresh_token = $tokens['refresh_token'];
		return true;
	}
}
