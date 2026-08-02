<?php
/**
 * Class Drip Remote Data
 *
 * This class is responsible for handling the Drip Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Drip;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationRemoteData;

/**
 * Drip Remote Data class
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
		'accounts'  => array(
			'callback' => 'fetch_accounts',
		),
		'campaigns' => array(
			'callback' => 'fetch_campaigns',
		),
		'tags'      => array(
			'callback' => 'fetch_tags',
		),
		'fields'    => array(
			'callback' => 'fetch_fields',
		),
		'workflows' => array(
			'callback' => 'fetch_workflows',
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

		foreach ( $response['data']['custom_field_identifiers'] ?? array() as $field ) {
			$result[] = array(
				'label' => $field,
				'value' => $field,
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
				'label' => $tag,
				'value' => $tag,
			);
		}

		return $result;
	}

	/**
	 * Fetch campaigns.
	 *
	 * @return array
	 */
	public function fetch_campaigns() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_campaigns();
		$result   = array();

		if ( ! $response['success'] ) {
			return $result;
		}

		foreach ( $response['data']['campaigns'] ?? array() as $campaign ) {
			$result[] = array(
				'label' => $campaign['name'],
				'value' => $campaign['id'],
			);
		}

		return $result;
	}

	/**
	 * Fetch accounts.
	 *
	 * @return array
	 */
	public function fetch_accounts() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_accounts();
		$result   = array();

		if ( ! $response['success'] ) {
			return $result;
		}

		foreach ( $response['data']['accounts'] ?? array() as $account ) {
			$result[] = array(
				'label' => $account['name'],
				'value' => $account['id'],
			);
		}

		return $result;
	}

	/**
	 * Fetch workflows.
	 *
	 * @return array
	 */
	public function fetch_workflows() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_workflows();
		$result   = array();

		if ( ! $response['success'] ) {
			return $result;
		}

		foreach ( $response['data']['workflows'] ?? array() as $workflow ) {
			$result[] = array(
				'label' => $workflow['name'],
				'value' => $workflow['id'],
			);
		}

		return $result;
	}
}
