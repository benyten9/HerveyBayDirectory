<?php
/**
 * Settings_Controller class.
 *
 * @since 1.0.0
 * @package smtp
 */

namespace DoubleScale\Modules\Smtp\Providers\Outlook\REST;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Settings_Controller as Abstract_Settings_Controller;

/**
 * Settings_Controller class.
 *
 * @since 1.0.0
 */
class Settings_Controller extends Abstract_Settings_Controller {

	/**
	 * Retrieves schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_schema() {
		$schema = array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'settings',
			'type'                 => 'object',
			'context'              => array( 'view' ),
			'properties'           => array(
				'app' => array(
					'type'       => 'object',
					'context'    => array( 'view' ),
					'properties' => array(
						'client_id'     => array(
							'type'     => 'string',
							'required' => true,
							'context'  => array( 'view' ),
						),
						'client_secret' => array(
							'type'     => 'string',
							'required' => true,
							'context'  => array( 'view' ),
						),
					),
				),
			),
			'additionalProperties' => array(
				'context' => array(),
			),
		);

		return rest_default_additional_properties_to_false( $schema );
	}
}
