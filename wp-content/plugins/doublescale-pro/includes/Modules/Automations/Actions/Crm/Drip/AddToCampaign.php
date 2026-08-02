<?php
/**
 * Class AddToCampaign
 *
 * This class is responsible for adding a subscriber to a Drip campaign
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
 * Add To Campaign class
 */
class AddToCampaign extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add To Campaign';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'drip_add_to_campaign';

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
	public $description = 'This action will add a subscriber to a Drip campaign.';

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
		$campaign_id = $step->get_setting( 'campaign_id', '' );
		if ( empty( $campaign_id ) ) {
			doublescale_get_logger()->error(
				__( 'Drip Campaign ID is required.', 'doublescale'),
				array(
					'code' => 'drip_add_to_campaign',
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

		$result = $api->add_subscriber_to_campaign( $campaign_id, $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to add subscriber to Drip Campaign.', 'doublescale'),
				array(
					'code'     => 'drip_add_to_campaign',
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
			__( 'Subscriber added to Drip Campaign.', 'doublescale'),
			array(
				'code' => 'drip_add_to_campaign',
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
				'campaign_id' => array(
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
			'campaign_id' => array(
				'type'     => 'api_select',
				'label'    => __( 'Campaign', 'doublescale'),
				'endpoint' => 'drip/campaigns',
			),
		);
	}
}
