<?php
/**
 * Class MailerLite Remote Data
 *
 * This class is responsible for handling the MailerLite Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Mailerlite;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationRemoteData;

/**
 * MailerLite Remote Data class
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
		'groups' => array(
			'callback' => 'fetch_groups',
		),
	);

	/**
	 * Fetch groups.
	 *
	 * @return array
	 */
	public function fetch_groups() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_groups();
		$result   = array();

		if ( ! empty( $response ) ) {
			foreach ( $response['data'] as $group ) {
				$result[] = array(
					'value' => $group['id'],
					'label' => $group['name'],
				);
			}
		}

		return $result;
	}
}
