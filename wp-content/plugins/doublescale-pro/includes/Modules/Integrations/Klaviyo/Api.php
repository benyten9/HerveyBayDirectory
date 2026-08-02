<?php
/**
 * Class Klaviyo Api
 *
 * This class is responsible for handling the Klaviyo Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Klaviyo;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Klaviyo Api class
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
		$this->endpoint = 'https://a.klaviyo.com/api';
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
		return $this->get( 'lists' );
	}

	/**
	 * Create or update a contact
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function create_or_update_profile( $data ) {
		return $this->post( 'profile-import', $data );
	}

	/**
	 * Add a contact to a list
	 *
	 * @param string $list_id
	 * @param array  $data
	 *
	 * @return array
	 */
	public function add_profile_to_list( $list_id, $data ) {
		return $this->post( "lists/{$list_id}/relationships/profiles", $data );
	}

	/**
	 * Get a profile
	 *
	 * @param string $email
	 * @param string $list_id
	 *
	 * @return array
	 */
	public function get_profile( $email, $list_id ) {
		$email = '"' . $email . '"'; // Klaviyo requires the email to be wrapped in ''
		return $this->get( "lists/{$list_id}/profiles?filter=equals(email,$email)" );
	}

	/**
	 * Remove a contact from a list
	 *
	 * @param string $list_id
	 * @param array  $data
	 *
	 * @return array
	 */
	public function remove_profile_from_list( $list_id, $data ) {
		return $this->delete( "lists/{$list_id}/relationships/profiles", $data );
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
					'Authorization' => 'Klaviyo-Api-Key ' . $this->api_key,
					'revision'      => '2024-06-15',
				),
				'timeout' => 30,
			)
		);
	}
}
