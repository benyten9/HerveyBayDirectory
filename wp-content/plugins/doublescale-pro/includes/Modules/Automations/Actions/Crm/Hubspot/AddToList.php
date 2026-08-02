<?php
/**
 * Class AddToList
 *
 * This class is responsible for adding a contact to a Hubspot list
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Hubspot;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Add To List class
 */
class AddToList extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add To List';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'hubspot_add_to_list';

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
	public $group = 'hubspot';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a contact to a Hubspot list.';

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
		$list_id = $step->get_setting( 'list_id', '' );
		if ( empty( $list_id ) ) {
			doublescale_get_logger()->error(
				__( 'Hubspot Add To List: List ID is required.', 'doublescale'),
				array(
					'code' => 'hubspot_add_to_list',
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

		$email = $automation_contact->contact->email;
		$data  = array(
			'properties' => array(
				'email'     => $email,
				'firstname' => $automation_contact->contact->first_name,
				'lastname'  => $automation_contact->contact->last_name,
			),
		);

		$hubspot = IntegrationsManager::instance()->get_integration( 'hubspot' );
		$api     = $hubspot->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Hubspot Api connection failed.', 'doublescale'),
				array(
					'code' => 'hubspot_connect',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
					),
				)
			);
			return false;
		}

		$result = $api->get_or_create_contact( $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to get or create contact in Hubspot.', 'doublescale'),
				array(
					'code' => 'hubspot_get_or_create_contact',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
					),
				)
			);
			return false;
		}

		$contact_id = $result['data']['id'];
		$result     = $api->add_contact_to_list( $contact_id, $list_id );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to add contact to Hubspot list.', 'doublescale'),
				array(
					'code' => 'hubspot_add_contact_to_list',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
					),
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'Contact added to Hubspot list.', 'doublescale'),
			array(
				'code' => 'hubspot_add_contact_to_list',
				'data' => array(
					'automation' => array(
						'id'   => $automation->id,
						'name' => $automation->name,
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
				'list_id' => array(
					'type'     => array( 'string', 'number' ),
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
			'list_id' => array(
				'label'    => __( 'List ID', 'doublescale'),
				'type'     => 'api_select',
				'endpoint' => 'hubspot/lists',
			),
		);
	}
}
