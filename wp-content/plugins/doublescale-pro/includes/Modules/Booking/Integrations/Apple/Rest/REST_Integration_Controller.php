<?php
/**
 * Apple integration settings REST routes.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Apple\Rest;

use DoubleScale\Modules\Booking\Integration\Rest\REST_Integration_Controller as Abstract_REST_Integration_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * @property \DoubleScale\Pro\Modules\Booking\Integrations\Apple\Integration $integration
 */
class REST_Integration_Controller extends Abstract_REST_Integration_Controller {

	/**
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();
	}

	/**
	 * Settings schema.
	 */
	public function get_settings_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'app'   => array(
					'type'       => 'object',
					'context'    => array( 'view' ),
					'required'   => true,
					'properties' => array(
						'enabled'    => array(
							'label'       => __( 'Enable Apple Calendar Integration', 'doublescale' ),
							'description' => __( 'When disabled, Apple Calendar is not used for availability or booking sync.', 'doublescale' ),
							'type'        => 'boolean',
							'context'     => array( 'view', 'edit' ),
						),
						'cache_time' => array(
							'label'    => __( 'Cache Time', 'doublescale' ),
							'type'     => 'number',
							'required' => true,
							'context'  => array( 'view' ),
						),
					),
				),
				'hosts' => array(
					'type'                 => 'object',
					'context'              => array( 'view' ),
					'additionalProperties' => true,
				),
			),
			'required'   => array( 'app' ),
		);
	}
}
