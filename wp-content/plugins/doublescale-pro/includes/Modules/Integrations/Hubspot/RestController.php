<?php
/**
 * Class Hubspot Rest Controller
 *
 * This class is responsible for handling the Hubspot REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Hubspot;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Hubspot Rest Controller
 */
class RestController extends RestIntegrationController {

	/**
	 * Get settings schema
	 *
	 * @return array
	 */
	public function get_settings_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'access_token' => array(
					'label'       => __( 'Access Token', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		);
	}
}
