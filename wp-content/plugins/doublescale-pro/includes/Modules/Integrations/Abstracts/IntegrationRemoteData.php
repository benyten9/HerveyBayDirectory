<?php

/**
 * Class Integration Remote Data
 *
 * This class is responsible for handling the Integration Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Abstracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration;
use DoubleScale\Core\UserRoles\Permissions;

/**
 * Integration Remote Data class
 */
abstract class IntegrationRemoteData {


	/**
	 * Integration
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'doublescale/v1';

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Entities.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $entities;

	/**
	 * Constructor
	 *
	 * @param Integration $integration
	 */
	public function __construct( Integration $integration ) {
		$this->integration = $integration;
		$this->rest_base   = $this->integration->rest_controller->rest_base;

		add_action( 'rest_api_init', array( $this, 'register_entities_routes' ) );
	}

	/**
	 * Get entities.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_entities() {
		return $this->entities;
	}

	/**
	 * Registers the entities routes for remote data retrieval.
	 */
	public function register_entities_routes() {
		$entities = $this->get_entities();

		foreach ( $entities ?? array() as $entity => $data ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/' . $entity,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_remote_data' ),
					'permission_callback' => array( $this, 'get_entity_permissions_check' ),
					'args'                => array(
						'entity' => array(
							'description' => __( 'The ID of the entity.', 'doublescale'),
							'type'        => 'integer',
							'required'    => false,
						),
					),
				)
			);
		}
	}

	/**
	 * Get remote data
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function get_remote_data( $request ) {
		$entity    = explode( '/', $request->get_route() );
		$entity    = end( $entity );
		$entity_id = $request->get_param( 'entity_id' );

		// remote account data.
		$result = $this->{$this->entities[ $entity ]['callback']}( $entity_id );

		return rest_ensure_response( $result );
	}

	/**
	 * Get entity permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_entity_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
