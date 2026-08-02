<?php
/**
 * Class Klaviyo Remove From List
 *
 * This class is responsible for handling the Klaviyo Remove From List action
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Klaviyo;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Klaviyo Remove From List class
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
	public $slug = 'klaviyo_remove_from_list';

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
	public $group = 'klaviyo';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove a contact from a list in Klaviyo';

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
				__( 'Klaviyo Remove From List action failed. List ID is required.', 'doublescale'),
				array(
					'code' => 'klaviyo_remove_from_list',
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

		$klaviyo = IntegrationsManager::instance()->get_integration( 'klaviyo' );
		$api     = $klaviyo->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Klaviyo Remove From List action failed. Api connection failed.', 'doublescale'),
				array(
					'code' => 'klaviyo_connect',
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
		$result  = $api->get_profile( $email, $list_id );
		$profile = $result['data']['data'][0] ?? null;
		if ( ! $profile ) {
			doublescale_get_logger()->error(
				__( 'Klaviyo Remove From List action failed. Profile not found.', 'doublescale'),
				array(
					'code' => 'klaviyo_remove_from_list',
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

		$profile_id = $profile['id'];
		$data       = array(
			'data' => array(
				array(
					'type' => 'profile',
					'id'   => $profile_id,
				),
			),
		);
		$result     = $api->remove_profile_from_list( $list_id, $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Klaviyo Remove From List action failed. Failed to remove profile from list.', 'doublescale'),
				array(
					'code'     => 'klaviyo_remove_from_list',
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
			__( 'Klaviyo Remove From List action completed successfully.', 'doublescale'),
			array(
				'code'     => 'klaviyo_remove_from_list',
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
				'endpoint' => 'klaviyo/lists',
			),
		);
	}
}
