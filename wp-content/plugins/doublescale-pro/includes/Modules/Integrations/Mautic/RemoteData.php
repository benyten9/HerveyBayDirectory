<?php
/**
 * Class Mautic Remote Data
 *
 * This class is responsible for handling the Mautic Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Mautic;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationRemoteData;

/**
 * Mautic Remote Data class
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
		'fields'    => array(
			'callback' => 'fetch_fields',
		),
		'lists'     => array(
			'callback' => 'fetch_lists',
		),
		'campaigns' => array(
			'callback' => 'fetch_campaigns',
		),
	);

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

		foreach ( $response['data']['tags'] as $group ) {
			$result[] = array(
				'value' => $group['id'],
				'label' => $group['tag'],
			);
		}

		return $result;
	}

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

		foreach ( $response['data']['fields'] as $field ) {
			$result[] = array(
				'label' => $field['label'],
				'value' => $field['alias'],
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

		foreach ( $response['data']['lists'] as $list ) {
			$result[] = array(
				'label' => $list['name'],
				'value' => $list['id'],
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

		foreach ( $response['data']['campaigns'] as $key => $campaign ) {
			$result[] = array(
				'label' => $campaign['name'],
				'value' => $campaign['id'],
			);
		}

		return $result;
	}
}
