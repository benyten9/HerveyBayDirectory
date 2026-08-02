<?php
/**
 * Class Drip Api
 *
 * This class is responsible for handling the Drip Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Drip;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Drip Api class
 */
class Api extends IntegrationApi {

	/**
	 * Api Key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $api_token;

	/**
	 * Account ID
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $account_id;

	/**
	 * Constructor
	 *
	 * @param string $api_token
	 *
	 * @since 1.0.0
	 */
	public function __construct( $api_token, $account_id ) {
		$this->endpoint   = 'https://api.getdrip.com/v2';
		$this->api_token  = $api_token;
		$this->account_id = $account_id;
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
	 * Get campaigns.
	 *
	 * @return array
	 */
	public function get_campaigns() {
		return $this->get( 'campaigns' );
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
	 * Get fields.
	 *
	 * @return array
	 */
	public function get_fields() {
		return $this->get( 'custom_field_identifiers' );
	}

	/**
	 * Get Workflows.
	 *
	 * @return array
	 */
	public function get_workflows() {
		return $this->get( 'workflows' );
	}

	/**
	 * Add subscriber.
	 *
	 * @param array $data Subscriber data.
	 * @return array
	 */
	public function add_subscriber( $data ) {
		return $this->post( 'subscribers', $data );
	}

	/**
	 * Add subscriber to campaign.
	 *
	 * @param string $compaign_id Campaign id.
	 * @param array  $data Subscriber data.
	 * @return array
	 */
	public function add_subscriber_to_campaign( $compaign_id, $data ) {
		return $this->post( "campaigns/{$compaign_id}/subscribers", $data );
	}

	/**
	 * Add subscriber to workflow.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param array  $data Subscriber data.
	 * @return array
	 */
	public function add_subscriber_to_workflow( $workflow_id, $data ) {
		return $this->post( "workflows/{$workflow_id}/subscribers", $data );
	}

	/**
	 * Remove subscriber from campaign.
	 *
	 * @param string $compaign_id Campaign id.
	 * @param string $subscriber Subscriber subscriber.
	 *
	 * @return array
	 */
	public function remove_subscriber_from_campaign( $compaign_id, $subscriber ) {
		return $this->post( "subscribers/$subscriber/remove?campaign_id=$compaign_id", array() );
	}

	/**
	 * Remove subscriber from workflow.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param string $subscriber Subscriber subscriber.
	 *
	 * @return array
	 */
	public function remove_subscriber_from_workflow( $workflow_id, $subscriber ) {
		return $this->delete( "workflows/$workflow_id/subscribers/$subscriber" );
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
		$endpoint = $this->account_id ? $this->endpoint . '/' . $this->account_id : $this->endpoint;

		return wp_remote_request(
			"$endpoint/$path",
			array(
				'method'  => $method,
				'body'    => $body,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json; charset=' . get_option( 'blog_charset' ),
					'Cache-Control' => 'no-cache',
					'Authorization' => 'Basic ' . base64_encode( $this->api_token . ':' ),
				),
				'timeout' => 30,
			)
		);
	}
}
