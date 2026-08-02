<?php
/**
 * Class Mailchimp Api
 *
 * This class is responsible for handling the Mailchimp Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Mailchimp;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Mailchimp Api class
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
	 * @param string $api_url
	 * @param string $api_key
	 *
	 * @since 1.0.0
	 */
	public function __construct( $api_key ) {
		$dc             = substr( $api_key, strpos( $api_key, '-' ) + 1 );
		$this->endpoint = "https://$dc.api.mailchimp.com/3.0";
		$this->api_key  = $api_key;
	}

	/**
	 * Ping
	 *
	 * @return array
	 */
	public function ping() {
		return $this->get( 'ping' );
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
	 * Add subscriber
	 *
	 * @param string $list_id List ID.
	 * @param array  $data Data.
	 *
	 * @return array
	 */
	public function add_subscriber( $list_id, $data ) {
		return $this->post( "lists/$list_id/members", $data );
	}

	/**
	 * Remove subscriber
	 *
	 * @param string $list_id List ID.
	 * @param string $subscriber Subscriber.
	 *
	 * @return array
	 */
	public function remove_subscriber( $list_id, $subscriber ) {
		$subscriber_hash = md5( strtolower( $subscriber ) );
		// Check if subscriber exists.
		$subscriber_info = $this->get( "lists/$list_id/members/$subscriber_hash" );
		if ( ! $subscriber_info['success'] ) {
			return $subscriber_info;
		}

		return $this->delete( "lists/$list_id/members/$subscriber_hash" );
	}

	/**
	 * Get tags
	 *
	 * @param string $list_id List ID.
	 *
	 * @return array
	 */
	public function get_tags( $list_id ) {
		return $this->get( "lists/$list_id/segments" );
	}

	/**
	 * Add tags
	 *
	 * @param string $list_id List ID.
	 * @param string $subscriber Subscriber.
	 * @param array  $data Data.
	 *
	 * @return array
	 */
	public function add_tags( $list_id, $subscriber, $data ) {
		$subscriber_hash = md5( strtolower( $subscriber ) );
		return $this->post( "lists/$list_id/members/$subscriber_hash/tags", $data );
	}

	/**
	 * Remove tags
	 *
	 * @param string $list_id List ID.
	 * @param string $subscriber Subscriber.
	 * @param array  $data Data.
	 *
	 * @return array
	 */
	public function remove_tags( $list_id, $subscriber, $data ) {
		$subscriber_hash = md5( strtolower( $subscriber ) );
		return $this->post( "lists/$list_id/members/$subscriber_hash/tags", $data );
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
					'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key ),
				),
				'timeout' => 30,
			)
		);
	}
}
