<?php
/**
 * Class AddToWorkflow
 *
 * This class is responsible for adding a subscriber to a Drip workflow
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Drip;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Add To Workflow class
 */
class AddToWorkflow extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add To Workflow';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'drip_add_to_workflow';

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
	public $group = 'drip';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a subscriber to a Drip workflow.';

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
		$workflow_id = $step->get_setting( 'workflow_id', '' );
		if ( empty( $workflow_id ) ) {
			doublescale_get_logger()->error(
				__( 'Drip Workflow ID is required.', 'doublescale'),
				array(
					'code' => 'drip_add_to_workflow',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'name' => $step->type,
						),
					),
				)
			);
			return false;
		}

		$email = $automation_contact->contact->email;
		$data  = array(
			'subscribers' => array(
				array(
					'email' => $email,
				),
			),
		);

		$drip = IntegrationsManager::instance()->get_integration( 'drip' );
		$api  = $drip->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Drip Api connection failed.', 'doublescale'),
				array(
					'code' => 'drip_connect',
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

		$result = $api->add_subscriber_to_workflow( $workflow_id, $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to add subscriber to Drip Workflow.', 'doublescale'),
				array(
					'code' => 'drip_add_to_workflow',
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

		doublescale_get_logger()->info(
			__( 'Subscriber added to Drip Workflow.', 'doublescale'),
			array(
				'code'     => 'drip_add_to_workflow',
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
				'workflow_id' => array(
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
			'workflow_id' => array(
				'type'     => 'api_select',
				'label'    => __( 'Workflow', 'doublescale'),
				'endpoint' => 'drip/workflows',
			),
		);
	}
}
