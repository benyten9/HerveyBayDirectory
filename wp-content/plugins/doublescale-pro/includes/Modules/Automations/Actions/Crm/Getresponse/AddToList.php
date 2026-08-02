<?php
/**
 * Class AddToList
 *
 * This class is responsible for adding a contact to a list in GetResponse
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
	public $slug = 'getresponse_add_to_list';

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
	public $description = 'This action will add a contact to a list in GetResponse';

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
				__( 'GetResponse Add To List action is missing list_id', 'doublescale'),
				array(
					'code' => 'getresponse_add_to_list',
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
				__( 'GetResponse failed to connect to Api', 'doublescale'),
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
		$data   = array(
			'email'    => $email,
			'campaign' => array(
				'campaignId' => $list_id,
			),
		);
		$result = $api->create_or_update_contact( $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'GetResponse failed to add contact to list', 'doublescale'),
				array(
					'code' => 'getresponse_add_to_list',
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
			__( 'GetResponse added contact to list', 'doublescale'),
			array(
				'code' => 'getresponse_add_to_list',
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
