<?php
/**
 * Class AddToList
 *
 * This class is responsible for adding a contact to a Mautic list
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Mautic;

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
	public $slug = 'mautic_add_to_list';

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
	public $group = 'mautic';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a contact to a Mautic list.';

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
				__( 'Mautic Add To List action is missing list_id.', 'doublescale'),
				array(
					'code' => 'mautic_add_to_list',
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
			'email'     => $email,
			'firstname' => $automation_contact->contact->first_name,
			'lastname'  => $automation_contact->contact->last_name,
		);
		$mautic = IntegrationsManager::instance()->get_integration( 'mautic' );
		$api    = $mautic->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Mautic Add To List: Could not connect to Mautic.', 'doublescale'),
				array(
					'code' => 'mautic_connect',
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

		$result = $api->get_or_create_contact( $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Mautic Add To List: Failed to get or create contact.', 'doublescale'),
				array(
					'code' => 'mautic_add_to_list',
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

		$contact_id = isset( $result['data']['contact'] ) ? $result['data']['contact']['id'] : $result['data']['id'];
		$result     = $api->add_contact_to_list( $contact_id, $list_id );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Mautic Add To List: Failed to add contact to list.', 'doublescale'),
				array(
					'code'     => 'mautic_add_to_list',
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
			__( 'Mautic Add To List: Contact added to list.', 'doublescale'),
			array(
				'code'     => 'mautic_add_to_list',
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
				'type'     => 'api_select',
				'label'    => __( 'List', 'doublescale'),
				'endpoint' => 'mautic/lists',
			),
		);
	}
}
