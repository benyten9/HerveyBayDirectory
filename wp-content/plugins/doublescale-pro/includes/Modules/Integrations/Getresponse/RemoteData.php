<?php
/**
 * Class GetResponse Remote Data
 *
 * This class is responsible for handling the GetResponse Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Getresponse;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationRemoteData;

/**
 * GetResponse Remote Data class
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
		'tags'   => array(
			'callback' => 'fetch_tags',
		),
		'fields' => array(
			'callback' => 'fetch_fields',
		),
		'lists'  => array(
			'callback' => 'fetch_lists',
		),
	);

	/**
	 * Fetch fields.
	 *
	 * @return array
	 */
	public function fetch_fields() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_fields();
		$result   = array();

		if ( ! $response['success'] ) {
			return $result;
		}

		foreach ( $response['data'] as $field ) {
			$result[] = array(
				'label' => $field['name'],
				'value' => $field['customFieldId'],
			);
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
		$response = $api->get_tags();
		$result   = array();

		if ( ! $response['success'] ) {
			return $result;
		}

		foreach ( $response['data'] as $tag ) {
			$result[] = array(
				'label' => $tag['name'],
				'value' => $tag['tagId'],
			);
		}

		return $result;
	}

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

		if ( ! $response['success'] ) {
			return $result;
		}

		foreach ( $response['data'] as $list ) {
			$result[] = array(
				'label' => $list['name'],
				'value' => $list['campaignId'],
			);
		}

		return $result;
	}
}
