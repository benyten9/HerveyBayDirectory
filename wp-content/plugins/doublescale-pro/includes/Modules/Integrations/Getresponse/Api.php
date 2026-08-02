<?php
/**
 * Class GetResponse Api
 *
 * This class is responsible for handling the GetResponse Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Getresponse;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * GetResponse Api class
 */
class Api extends IntegrationApi {

	/**
	 * Api Key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $api_key;

	/**
	 * Constructor
	 *
	 * @param string $api_key
	 *
	 * @since 1.0.0
	 */
	public function __construct( $api_key ) {
		$this->endpoint = 'https://api.getresponse.com/v3';
		$this->api_key  = $api_key;
	}

	/**
	 * Get accounts
	 *
	 * @return array
	 */
	public function get_accounts() {
		return $this->get( 'accounts' );
	}

	/**
	 * Get lists
	 *
	 * @return array
	 */
	public function get_lists() {
		return $this->get( 'campaigns' );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return $this->get( 'custom-fields' );
	}

	/**
	 * Get tags
	 *
	 * @return array
	 */
	public function get_tags() {
		return $this->get( 'tags' );
	}

	/**
	 * Add contact
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function add_contact( $data ) {
		$contact = $this->get_contact( $data['email'] );
		if ( ! empty( $contact['data'] ) ) {
			return $contact;
		}

		return $this->post( 'contacts', $data );
	}

	/**
	 * Get contact
	 *
	 * @param string $email
	 *
	 * @return array
	 */
	public function get_contact( $email ) {
		$contacts = $this->get( 'contacts', array( 'query' => array( 'email' => $email ) ) );
		if ( ! empty( $contacts['data'] ) ) {
			$contacts['data'] = $contacts['data'][0];
			return $contacts;
		}

		return $contacts;
	}

	/**
	 * Update contact
	 *
	 * @param string $email
	 * @param array  $data
	 *
	 * @return array
	 */
	public function update_contact( $email, $data ) {
		$contact = $this->get_contact( $email );
		if ( empty( $contact['data'] ) ) {
			return $contact;
		}

		return $this->post( "contacts/{$contact['data']['contactId']}", $data );
	}

	/**
	 * Get or create contact
	 *
	 * @param string $email
	 *
	 * @return array
	 */
	public function get_or_create_contact( $email ) {
		$contact = $this->get_contact( $email );
		if ( ! empty( $contact['data'] ) ) {
			return $contact;
		}

		return $this->add_contact( array( 'email' => $email ) );
	}

	/**
	 * Create or update contact
	 *
	 * @param string $email
	 * @param array  $data
	 *
	 * @return array
	 */
	public function create_or_update_contact( $email, $data ) {
		$contact = $this->get_contact( $email );
		if ( empty( $contact['data'] ) ) {
			return $this->add_contact( array_merge( $data, array( 'email' => $email ) ) );
		}

		return $this->update_contact( $email, $data );
	}

	/**
	 * Remove contact from list
	 *
	 * @param string $contact_id
	 * @param string $list_id
	 *
	 * @return array
	 */
	public function remove_contact_from_list( $contact_id ) {
		return $this->delete( "contacts/{$contact_id}" );
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
					'X-Auth-Token'  => 'api-key ' . $this->api_key,
				),
				'timeout' => 30,
			)
		);
	}
}
