<?php
/**
 * Class RemoveList
 *
 * This class is responsible for removeing list to a contact in ActiveCampaign
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
 * Remove List class
 */
class RemoveList extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove List';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'activecampaign_remove_list';

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
	public $description = 'This action will remove list to a contact in ActiveCampaign.';

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
		$list = $step->get_setting( 'list', '' );
		if ( empty( $list ) ) {
			doublescale_get_logger()->error(
				__( 'ActiveCampaign Remove List: List is empty.', 'doublescale'),
				array(
					'code' => 'activecampaign_remove_list',
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

		$result = $api->get_contact( $automation_contact->contact->email );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to get contact from ActiveCampaign.', 'doublescale'),
				array(
					'code'     => 'activecampaign_get_contact',
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

		$contact_id = $result['data']['contacts'][0]['id'] ?? null;
		if ( ! $contact_id ) {
			doublescale_get_logger()->error(
				__( 'Failed to get contact ID from ActiveCampaign.', 'doublescale'),
				array(
					'code'     => 'activecampaign_get_contact_id',
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

		$data = array(
			'contactList' => array(
				'list'    => $list,
				'contact' => $contact_id,
				'status'  => '2',
			),
		);

		$result = $api->sync_contact_list( $data );
		if ( $result['success'] ) {
			doublescale_get_logger()->info(
				__( 'List removed from ActiveCampaign.', 'doublescale'),
				array(
					'code' => 'activecampaign_remove_list',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
						'response'   => $result,
					),
				)
			);
			return true;
		}

		doublescale_get_logger()->error(
			__( 'Failed to remove list from ActiveCampaign.', 'doublescale'),
			array(
				'code' => 'activecampaign_remove_list',
				'data' => array(
					'automation' => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'step'       => array(
						'id'   => $step->id,
						'type' => $step->type,
					),
					'response'   => $result,
				),
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
				'list' => array(
					'type'     => 'string',
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
			'list' => array(
				'type'     => 'api_select',
				'label'    => __( 'List', 'doublescale'),
				'endpoint' => 'activecampaign/lists',
			),
		);
	}
}
