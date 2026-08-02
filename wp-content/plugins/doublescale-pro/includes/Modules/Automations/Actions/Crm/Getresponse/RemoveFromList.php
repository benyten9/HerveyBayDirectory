<?php
/**
 * Class RemoveFromList
 *
 * This class is responsible for removing a contact from a list in GetResponse
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
 * Remove From List class
 */
class RemoveFromList extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove From List';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'getresponse_remove_from_list';

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
	public $description = 'This action will remove a contact from a list in GetResponse';

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
		$list_id = $step->get_setting( 'list_id' );

		if ( empty( $list_id ) ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Remove From List: List ID is required.', 'doublescale'),
				array(
					'code' => 'getresponse_remove_from_list',
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

		$getresponse = IntegrationsManager::instance()->get_integration( 'getresponse' );
		$api         = $getresponse->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Api connection failed.', 'doublescale'),
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

		$email   = $automation_contact->contact->email;
		$contact = $api->get_contact( $email );
		if ( ! $contact ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Remove From List: Contact not found.', 'doublescale'),
				array(
					'code' => 'getresponse_remove_from_list',
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

		// First, we need to check if the contact is already in the list
		$contact_list = $contact['campaign']['campaignId'];
		if ( $contact_list !== $list_id ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Remove From List: Contact is not in the list.', 'doublescale'),
				array(
					'code' => 'getresponse_remove_from_list',
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

		$result = $api->remove_contact( $contact['contactId'], $list_id );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Remove From List: Failed to remove contact from list.', 'doublescale'),
				array(
					'code'     => 'getresponse_remove_from_list',
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
			return false;
		}

		doublescale_get_logger()->info(
			__( 'GetResponse Remove From List: Contact removed from list.', 'doublescale'),
			array(
				'code'     => 'getresponse_remove_from_list',
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
					'description' => __( 'List ID', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
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
				'endpoint' => 'getresponse/lists',
			),
		);
	}
}
