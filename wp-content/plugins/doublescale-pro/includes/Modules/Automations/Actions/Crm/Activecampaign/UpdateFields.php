<?php
/**
 * Class UpdateFields
 *
 * This class is responsible for adding a contact to ActiveCampaign
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Activecampaign;

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
	public $slug = 'activecampaign_update_fields';

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
	public $group = 'activecampaign';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the fields of a contact in ActiveCampaign';

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
				__( 'ActiveCampaign Update Fields: Mapped Fields is empty.', 'doublescale'),
				array(
					'code'          => 'activecampaign_update_fields',
					'data'          => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
					),
					'mapped_fields' => $mapped_fields,
				)
			);
			return false;
		}

		$data = array(
			'email' => $automation_contact->contact->email,
		);

		foreach ( $mapped_fields as $field ) {
			$field_key = $field['key'];
			$value     = $field['value'];
			if ( empty( $value ) || empty( $field_key ) ) {
				continue;
			}

			if ( in_array( $field_key, array( 'email', 'first_name', 'last_name' ) ) ) {
				$data[ $field_key ] = $this->merge_tags_manager->process_merge_tags( $value, $automation_contact );
				continue;
			}

			$data['fieldValues'][] = array(
				'field' => $field_key,
				'value' => $this->merge_tags_manager->process_merge_tags( $value, $automation_contact ),
			);
		}

		$activecampaign = IntegrationsManager::instance()->get_integration( 'activecampaign' );
		$api            = $activecampaign->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'ActiveCampaign Api connection failed.', 'doublescale'),
				array(
					'code' => 'activecampaign_connect',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
					),
				)
			);
			return false;
		}

		$result = $api->create_or_update(
			array(
				'contact' => $data,
			)
		);

		if ( $result['success'] ) {
			doublescale_get_logger()->info(
				__( 'ActiveCampaign Update Fields: Contact updated successfully.', 'doublescale'),
				array(
					'code'     => 'activecampaign_update_fields',
					'data'     => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
					),
					'response' => $result,
				)
			);
			return true;
		}

		if ( 422 === $result['code'] ) {
			doublescale_get_logger()->error(
				__( 'ActiveCampaign Update Fields: Failed to update contact.', 'doublescale'),
				array(
					'code'     => 'activecampaign_update_fields',
					'data'     => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
					),
					'response' => $result,
				)
			);
			return true;
		}

		doublescale_get_logger()->error(
			__( 'ActiveCampaign Update Fields: Failed to update contact.', 'doublescale'),
			array(
				'code'     => 'activecampaign_update_fields',
				'data'     => array(
					'automation' => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'step'       => array(
						'id'   => $step->id,
						'type' => $step->type,
					),
				),
				'response' => $result,
			)
		);
		return false;
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
				'fields'   => array(
					'email'      => array(
						'label' => __( 'Email', 'doublescale'),
					),
					'first_name' => array(
						'label' => __( 'First Name', 'doublescale'),
					),
					'last_name'  => array(
						'label' => __( 'Last Name', 'doublescale'),
					),
				),
				'endpoint' => 'activecampaign/fields',
			),
		);
	}
}
