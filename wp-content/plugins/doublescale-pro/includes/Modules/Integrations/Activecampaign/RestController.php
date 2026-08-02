<?php
/**
 * Class ActiveCampaign Rest Controller
 *
 * This class is responsible for handling the ActiveCampaign REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Activecampaign;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;

/**
 * ActiveCampaign Rest Controller
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
				'api_url' => array(
					'label'       => __( 'Api URL', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => function( $value ) {
							return filter_var( $value, FILTER_VALIDATE_URL );
						},
					),
				),
				'api_key' => array(
					'label'       => __( 'Api Key', 'doublescale'),
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
