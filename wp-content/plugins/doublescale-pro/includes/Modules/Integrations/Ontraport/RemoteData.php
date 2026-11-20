<?php
/**
 * Class Ontraport Remote Data
 *
 * This class is responsible for handling the Ontraport Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Ontraport;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationRemoteData;

/**
 * Ontraport Remote Data class
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
		// None yet: Api has no get_groups() and no screen asks for Ontraport
		// groups. This used to be a plain list ( 'groups' ), which registered
		// `/ontraport/0` — a route that crashed with a PHP fatal. Add entities
		// as 'name' => array( 'callback' => 'fetch_name' ) once the Api has them.
	);

	/**
	 * Get remote data
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $entity Entity.
	 * @param int             $entity_id Entity ID.
	 * @return array|WP_Error
	 */
	public function fetch( $request, $entity, $entity_id ) {
		$result = array();

		switch ( $entity ) {
			case 'groups':
				$result = $this->fetch_groups();
				break;
		}

		return $result;
	}

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

		return $response;
	}
}
