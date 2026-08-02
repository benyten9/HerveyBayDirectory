<?php
/**
 * Class Mautic Api
 *
 * This class is responsible for handling the Mautic Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Mautic;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Mautic Api class
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
	 * @param string $base_url Base URL.
	 * @param App    $app App.
	 * @since 1.0.0
	 */
	public function __construct( $access_token, $refresh_token, $base_url, $app = null ) {
		$this->endpoint      = "{$base_url}/api";
		$this->app           = $app;
		$this->access_token  = $access_token;
		$this->refresh_token = $refresh_token;
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
	 * Create contact.
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function create( $data ) {
		return $this->post( 'contacts/new', $data );
	}

	/**
	 * Get contact by email.
	 *
	 * @param string $email Email.
	 *
	 * @return array
	 */
	public function get_contact( $email ) {
		$contacts = $this->get(
			'contacts',
			array(
				'search'  => $email,
				'orderBy' => 'email',
				'limit'   => 1,
				'minimal' => true,
			)
		);

		if ( ! $contacts['success'] ) {
			return $contacts;
		}

		if ( empty( $contacts['data']['contacts'] ) ) {
			return $this->prepare_response( false, 404, array() );
		}

		// Get first of object.
		$contact = reset( $contacts['data']['contacts'] );

		return $this->prepare_response( true, 200, $contact );
	}

	/**
	 * Get or create contact.
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function get_or_create_contact( $data ) {
		$contact = $this->get_contact( $data['email'] );
		if ( ! $contact['success'] ) {
			$contact = $this->create( $data );
		}

		return $contact;
	}

	/**
	 * Create or update contact.
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function create_or_update_contact( $data ) {
		$contact = $this->get_contact( $data['email'] );
		if ( ! $contact['success'] ) {
			$contact = $this->create( $data );
		} else {
			$contact = $this->update( $contact['data']['id'], $data );
		}

		return $contact;
	}

	/**
	 * Update contact.
	 *
	 * @param int   $id ID.
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function update( $id, $data ) {
		return $this->patch( "contacts/$id/edit", $data );
	}

	/**
	 * Add contact to list.
	 *
	 * @param int $contact_id Contact ID.
	 * @param int $list_id List ID.
	 *
	 * @return array
	 */
	public function add_contact_to_list( $contact_id, $list_id ) {
		return $this->post( "segments/$list_id/contacts/add", array( 'ids' => array( $contact_id ) ) );
	}

	/**
	 * Remove contact from list.
	 *
	 * @param int $contact_id Contact ID.
	 * @param int $list_id List ID.
	 *
	 * @return array
	 */
	public function remove_contact_from_list( $contact_id, $list_id ) {
		return $this->post( "segments/$list_id/contact/$contact_id/remove" );
	}

	/**
	 * Add contact to campaign.
	 *
	 * @param int $contact_id Contact ID.
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return array
	 */
	public function add_contact_to_campaign( $contact_id, $campaign_id ) {
		return $this->post( "campaigns/$campaign_id/contact/$contact_id/add" );
	}

	/**
	 * Remove contact from campaign.
	 *
	 * @param int $contact_id Contact ID.
	 * @param int $campaign_id Campaign ID.
	 *
	 * @return array
	 */
	public function remove_contact_from_campaign( $contact_id, $campaign_id ) {
		return $this->post( "campaigns/$campaign_id/contact/$contact_id/remove" );
	}

	/**
	 * Get fields.
	 *
	 * @return array
	 */
	public function get_fields() {
		return $this->get( 'fields/contact' );
	}

	/**
	 * Get lists.
	 *
	 * @return array
	 */
	public function get_lists() {
		return $this->get( 'segments' );
	}

	/**
	 * Get campaigns.
	 *
	 * @return array
	 */
	public function get_campaigns() {
		return $this->get( 'campaigns' );
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
