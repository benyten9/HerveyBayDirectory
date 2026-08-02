<?php
/**
 * Class MailerLite Api
 *
 * This class is responsible for handling the MailerLite Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Mailerlite;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * MailerLite Api class
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
		$this->endpoint = 'https://api.mailerlite.com/api/v2';
		$this->api_key  = $api_key;
	}

	/**
	 * Get groups
	 *
	 * @return array
	 */
	public function get_groups() {
		return $this->get( 'groups' );
	}

	/**
	 * Get subscribers
	 *
	 * @return array
	 */
	public function get_subscribers() {
		return $this->get( 'subscribers' );
	}

	/**
	 * Add subscriber
	 *
	 * @param array $data
	 *
	 * @return array|WP_Error
	 */
	public function add_subscriber( $data ) {
		return $this->post( 'subscribers', $data );
	}

	/**
	 * Get subscriber
	 *
	 * @param string $email
	 *
	 * @return array
	 */
	public function get_subscriber( $email ) {
		return $this->get( "subscribers/$email" );
	}

	/**
	 * Delete subscriber from group
	 *
	 * @param int $group_id
	 * @param int $subscriber_id
	 *
	 * @return array|WP_Error
	 */
	public function delete_subscriber_from_group( $group_id, $subscriber_id ) {
		return $this->delete( "groups/$group_id/subscribers/$subscriber_id" );
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
	 * Get subscriber by offset
	 *
	 * @param int $offset
	 * @param int $group_id
	 *
	 * @return array
	 */
	public function get_subscribers_by_offset( $offset, $group_id ) {
		return $this->get(
			"groups/$group_id/subscribers",
			array(
				'offset' => $offset,
				'limit'  => 20,
			)
		);
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
					'Accept'              => 'application/json',
					'Content-Type'        => 'application/json; charset=' . get_option( 'blog_charset' ),
					'X-MailerLite-ApiKey' => $this->api_key,
				),
				'timeout' => 30,
			)
		);
	}

}
