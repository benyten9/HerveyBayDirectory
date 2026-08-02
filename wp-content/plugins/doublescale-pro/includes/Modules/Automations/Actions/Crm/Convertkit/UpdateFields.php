<?php
/**
 * Class UpdateFields
 *
 * This class is responsible for adding a contact to Converkit
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Convertkit;

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
	public $slug = 'convertkit_update_fields';

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
	public $group = 'convertkit';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the fields of a contact in Converkit';

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
				__( 'Convertkit Update Fields: Mapped Fields is empty.', 'doublescale'),
				array(
					'code'          => 'converkit_update_fields',
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

		$data = array();

		foreach ( $mapped_fields as $field ) {
			$field_key = $field['key'];
			$value     = $field['value'];
			if ( empty( $value ) || empty( $field_key ) ) {
				continue;
			}

			$data['fields'][ $field_key ] = $this->merge_tags_manager->process_merge_tags( $value, $automation_contact );
		}

		$convertkit = IntegrationsManager::instance()->get_integration( 'convertkit' );
		$api        = $convertkit->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Convertkit Add Tags: Api connection failed.', 'doublescale'),
				array(
					'code' => 'convertkit_connect',
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

		$email  = $automation_contact->contact->email;
		$result = $api->get_subscriber( $email );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to get subscriber from Convertkit.', 'doublescale'),
				array(
					'code'     => 'convertkit_get_subscriber',
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

		$subscriber = $result['data']['subscribers'][0] ?? null;
		if ( ! $subscriber ) {
			doublescale_get_logger()->error(
				__( 'Subscriber not found in Convertkit.', 'doublescale'),
				array(
					'code'     => 'convertkit_get_subscriber',
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

		$subscriber_id = $subscriber['id'] ?? null;
		$result        = $api->update_subscriber( $subscriber_id, $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to update subscriber in Convertkit.', 'doublescale'),
				array(
					'code'     => 'convertkit_update_subscriber',
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

		doublescale_get_logger()->info(
			__( 'Subscriber updated in Convertkit.', 'doublescale'),
			array(
				'code'     => 'convertkit_update_subscriber',
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
				'endpoint' => 'convertkit/fields',
			),
		);
	}
}
