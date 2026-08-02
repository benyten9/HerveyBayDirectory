<?php
/**
 * Class ActiveCampaign Api
 *
 * This class is responsible for handling the ActiveCampaign Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Activecampaign;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * ActiveCampaign Api class
 */
class Api extends IntegrationApi {

	/**
	 * Api URL
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $api_url;

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
	 * @param string $api_url
	 * @param string $api_key
	 *
	 * @since 1.0.0
	 */
	public function __construct( $api_url, $api_key ) {
		$this->endpoint = $api_url . '/api/3';
		$this->api_key  = $api_key;
	}

	/**
	 * Add contact
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function create_or_update( $data ) {
		return $this->post( 'contact/sync', $data );
	}

	/**
	 * Get contact
	 *
	 * @param string $email Email.
	 *
	 * @return array
	 */
	public function get_contact( $email ) {
		return $this->get( 'contacts?filters[email]=' . urlencode( $email ) );
	}

	/**
	 * Add contact tag
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function add_contact_tag( $data ) {
		return $this->post( 'contactTags', $data );
	}

	/**
	 * Remove contact tag
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function remove_contact_tag( $data ) {
		return $this->delete( 'contactTags', $data );
	}

	/**
	 * Sync contact list
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function sync_contact_list( $data ) {
		return $this->post( 'contactLists', $data );
	}

	/**
	 * Add contact to automation
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function add_contact_to_automation( $data ) {
		return $this->post( 'contactAutomations', $data );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return $this->get( 'fields' );
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
	 * Get tags
	 *
	 * @return array
	 */
	public function get_tags() {
		return $this->get( 'tags' );
	}

	/**
	 * Get contacts by offset
	 *
	 * @param int $offset Offset.
	 *
	 * @return array
	 */
	public function get_contacts_by_offset( $offset ) {
		return $this->get(
			'contacts',
			array(
				'offset'  => $offset,
				'limit'   => 20,
				'include' => 'contactTags,contactLists,fieldValues',
			)
		);
	}

	/**
	 * Get contacts count
	 *
	 * @return array
	 */
	public function get_contacts_count() {
		return $this->get( 'contacts?limit=1' );
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
					'Api-Token'     => $this->api_key,
				),
				'timeout' => 30,
			)
		);
	}
}
