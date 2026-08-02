<?php
/**
 * Class Klaviyo Add To List
 *
 * This class is responsible for handling the Klaviyo Add To List action
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
 * Klaviyo Add To List class
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
	public $slug = 'klaviyo_add_to_list';

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
	public $description = 'This action will add a contact to a list in Klaviyo';

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
				__( 'Klaviyo Add To List action failed. List ID is empty.', 'doublescale'),
				array(
					'code' => 'klaviyo_add_to_list',
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
				__( 'Klaviyo Add To List action failed. Unable to connect to Klaviyo.', 'doublescale'),
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

		$email = $automation_contact->contact->email;
		$data  = array(
			'data' => array(
				'type'       => 'profile',
				'attributes' => array(
					'email' => $email,
				),
			),
		);

		$result = $api->create_or_update_profile( $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Klaviyo Add To List action failed. Failed to create or update profile.', 'doublescale'),
				array(
					'code'     => 'klaviyo_create_or_update_profile',
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

		$profile_id = $result['data']['data']['id'];
		$list_data  = array(
			'data' => array(
				array(
					'type' => 'profile',
					'id'   => $profile_id,
				),
			),
		);

		$result = $api->add_profile_to_list( $list_id, $list_data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Klaviyo Add To List action failed. Failed to add profile to list.', 'doublescale'),
				array(
					'code'     => 'klaviyo_add_profile_to_list',
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
			__( 'Klaviyo Add To List action completed successfully.', 'doublescale'),
			array(
				'code'     => 'klaviyo_add_to_list',
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
