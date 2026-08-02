<?php
/**
 * Class Convertkit Remote Data
 *
 * This class is responsible for handling the Convertkit Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Convertkit;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationRemoteData;

/**
 * Convertkit Remote Data class
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
		'tags'      => array(
			'callback' => 'fetch_tags',
		),
		'sequences' => array(
			'callback' => 'fetch_sequences',
		),
		'fields'    => array(
			'callback' => 'fetch_fields',
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

		foreach ( $response['data']['custom_fields'] ?? array() as $field ) {
			$result[] = array(
				'label' => $field['name'],
				'value' => $field['id'],
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

		foreach ( $response['data']['tags'] ?? array() as $tag ) {
			$result[] = array(
				'label' => $tag['name'],
				'value' => $tag['id'],
			);
		}

		return $result;
	}

	/**
	 * Fetch sequences.
	 *
	 * @return array
	 */
	public function fetch_sequences() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_sequences();
		$result   = array();

		if ( ! $response['success'] ) {
			return $result;
		}

		foreach ( $response['data']['sequences'] ?? array() as $tag ) {
			$result[] = array(
				'label' => $tag['name'],
				'value' => $tag['id'],
			);
		}

		return $result;
	}
}
