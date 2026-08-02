<?php
/**
 * Class UpdateFields
 *
 * This class is responsible for updating fields of a contact in GetResponse
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Getresponse;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Update Fields class
 */
class UpdateFields extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Update Fields';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'getresponse_update_fields';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'send_data';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'getresponse';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update fields of a contact in GetResponse';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$mapped_fields = $step->get_setting( 'mapped_fields', array() );
		if ( empty( $mapped_fields ) ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Update Fields: Mapped Fields are empty', 'doublescale'),
				array(
					'code' => 'getresponse_update_fields',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
					),
				)
			);
			return false;
		}

		$data = array();

		foreach ( $mapped_fields as $field ) {
			$field_key = $field['key'];
			$value     = $field['value'];
			if ( empty( $value ) || empty( $field_key ) ) {
				continue;
			}

			$data['customFieldValues'][] = array(
				'customFieldId' => $field_key,
				'value'         => $this->merge_tags_manager->process_merge_tags( $value, $automation_contact ),
			);
		}

		$getresponse = IntegrationsManager::instance()->get_integration( 'getresponse' );
		$api         = $getresponse->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Update Fields: Api connection failed', 'doublescale'),
				array(
					'code' => 'getresponse_connect',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
					),
				)
			);
			return false;
		}

		$email  = $automation_contact->contact->email;
		$result = $api->create_or_update_contact( $email, $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to update fields of contact in GetResponse', 'doublescale'),
				array(
					'code' => 'getresponse_update_fields',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
					),
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'Fields of contact updated in GetResponse', 'doublescale'),
			array(
				'code' => 'getresponse_update_fields',
				'data' => array(
					'automation' => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'step'       => array(
						'id' => $step->id,
					),
				),
			)
		);

		return true;
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mapped_fields' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		);
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'mapped_fields' => array(
				'label'    => __( 'Mapped Fields', 'doublescale'),
				'type'     => 'api_mapped_fields',
				'fields'   => array(),
				'endpoint' => 'getresponse/fields',
			),
		);
	}
}
