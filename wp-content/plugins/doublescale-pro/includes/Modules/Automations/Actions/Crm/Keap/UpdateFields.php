<?php
/**
 * Class UpdateFields
 *
 * This class is responsible for adding a contact to Keap
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Keap;

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
	public $slug = 'keap_update_fields';

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
	public $group = 'keap';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the fields of a contact in Keap';

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
				__( 'Keap Update Fields: Mapped Fields are empty.', 'doublescale'),
				array(
					'code' => 'keap_update_fields',
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

		$data = array(
			'email_addresses'  => array(
				array(
					'email' => $automation_contact->contact->email,
					'field' => 'EMAIL1',
				),
			),
			'duplicate_option' => 'Email',
		);

		foreach ( $mapped_fields as $field ) {
			$field_key = $field['key'];
			$value     = $field['value'];
			if ( empty( $value ) || empty( $field_key ) ) {
				continue;
			}

			$data['custom_fields'][] = array(
				'id'      => $field_key,
				'content' => $this->merge_tags_manager->process_merge_tags( $value, $automation_contact ),
			);
		}

		$keap = IntegrationsManager::instance()->get_integration( 'keap' );
		$api  = $keap->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Keap Update Fields: Could not connect to Keap.', 'doublescale'),
				array(
					'code' => 'keap_connect',
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

		$result = $api->create_or_update( $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Keap Update Fields: Could not update fields.', 'doublescale'),
				array(
					'code' => 'keap_update_fields',
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
			__( 'Keap Update Fields: Fields updated successfully.', 'doublescale'),
			array(
				'code' => 'keap_update_fields',
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
				'endpoint' => 'keap/fields',
			),
		);
	}
}
