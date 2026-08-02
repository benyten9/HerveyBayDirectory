<?php
/**
 * Class RemoveFromCampaign
 *
 * This class is responsible for removing a contact from a Mautic campaign
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
 * Remove From Campaign class
 */
class RemoveFromCampaign extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove From Campaign';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'mautic_remove_from_campaign';

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
	public $description = 'This action will remove a contact from a Mautic campaign.';

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
				__( 'Mautic Remove From Campaign action is missing campaign_id setting.', 'doublescale'),
				array(
					'code' => 'mautic_remove_from_campaign',
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
				__( 'Mautic Remove From Campaign: Could not connect to Mautic.', 'doublescale'),
				array(
					'code' => 'mautic_remove_from_campaign',
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
				__( 'Mautic Remove From Campaign: Could not get or create contact.', 'doublescale'),
				array(
					'code'     => 'mautic_remove_from_campaign',
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

		$contact_id = isset( $result['data']['contact'] ) ? $result['data']['contact']['id'] : $result['data']['id'];
		$result     = $api->remove_contact_from_campaign( $contact_id, $campaign_id );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Mautic Remove From Campaign: Could not remove contact from campaign.', 'doublescale'),
				array(
					'code'     => 'mautic_remove_from_campaign',
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
			__( 'Mautic Remove From Campaign: Contact removed from campaign.', 'doublescale'),
			array(
				'code'     => 'mautic_remove_from_campaign',
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
				'endpoint' => 'mautic/campaigns',
			),
		);
	}
}
