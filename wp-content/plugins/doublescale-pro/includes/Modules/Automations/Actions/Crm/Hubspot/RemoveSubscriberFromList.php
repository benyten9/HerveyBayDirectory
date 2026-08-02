<?php
/**
 * Class RemoveSubscriberFromList
 *
 * This class is responsible for removing a contact from a Hubspot list
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
 * Remove Subscriber From List class
 */
class RemoveSubscriberFromList extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove Subscriber From List';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'hubspot_remove_contact_from_list';

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
	public $description = 'This action will remove a contact from a Hubspot list.';

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
				__( 'Hubspot Remove Subscriber From List: List ID is required.', 'doublescale'),
				array(
					'code' => 'hubspot_remove_contact_from_list',
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

		$email   = $automation_contact->contact->email;
		$hubspot = IntegrationsManager::instance()->get_integration( 'hubspot' );
		$api     = $hubspot->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Could not connect to Hubspot.', 'doublescale'),
				array(
					'code' => 'hubspot_connect',
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

		$result = $api->get_contact_by_email( $email );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Hubspot Remove Subscriber From List: Could not get contact.', 'doublescale'),
				array(
					'code'     => 'hubspot_remove_contact_from_list',
					'data'     => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
					),
					'response' => $result,
				)
			);
			return true;
		}

		$result = $api->remove_contact_from_list( $result['data']['id'], $list_id );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Hubspot Remove Subscriber From List: Could not remove contact from list.', 'doublescale'),
				array(
					'code' => 'hubspot_remove_contact_from_list',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
						'response'   => $result,
					),
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'Hubspot Remove Subscriber From List: Contact removed from list.', 'doublescale'),
			array(
				'code'     => 'hubspot_remove_contact_from_list',
				'data'     => array(
					'automation' => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'step'       => array(
						'id' => $step->id,
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
