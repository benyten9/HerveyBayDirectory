<?php
/**
 * Class Mailchimp Remote Data
 *
 * This class is responsible for handling the Mailchimp Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Mailchimp;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationRemoteData;

/**
 * Mailchimp Remote Data class
 */
class RemoteData extends IntegrationRemoteData {

	/**
	 * Entities.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $entities = array(
		'lists' => array(
			'callback' => 'fetch_lists',
		),
		'tags'  => array(
			'callback' => 'fetch_tags',
		),
	);

	/**
	 * Fetch lists.
	 *
	 * @return array
	 */
	public function fetch_lists() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_lists();
		$result   = array();

		if ( ! empty( $response ) ) {
			foreach ( $response['data']['lists'] ?? array() as $list ) {
				$result[] = array(
					'value' => $list['id'],
					'label' => $list['name'],
				);
			}
		}

		return $result;
	}

	/**
	 * Fetch tags.
	 *
	 * @return array
	 */
	public function fetch_tags() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_tags( $this->integration->get_setting( 'list_id' ) );
		$result   = array();

		if ( ! empty( $response ) ) {
			foreach ( $response['data']['tags'] ?? array() as $tag ) {
				$result[] = array(
					'value' => $tag['id'],
					'label' => $tag['name'],
				);
			}
		}

		return $result;
	}
}
