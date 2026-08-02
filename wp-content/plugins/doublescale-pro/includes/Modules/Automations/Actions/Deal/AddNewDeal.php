<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Deal;


use DoubleScale\Pro\Modules\Deals\Services\DealManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;


// Use global function via fully-qualified call when needed.

class AddNewDeal extends BaseDealAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add a deal';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'add_new_deal';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a new deal.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'sales';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'deal';

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
		$title = $this->parse_deal_title( $step->get_setting( 'title' ), $automation_contact );
		if ( empty( $title ) ) {
			$title = 'no-title';
		}
		$data = array(
			'title'       => $title,
			'owner_id'    => $step->get_setting( 'owner' ),
			'pipeline_id' => $step->get_setting( 'pipeline' ),
			'stage_id'    => $step->get_setting( 'stage' ),
			'contact_id'  => $automation_contact->contact->id,
		);
		if ( $step->get_setting( 'value' ) ) {
			$data['value'] = $this->parse_deal_value( $step->get_setting( 'value' ), $automation_contact );
		}
		$deal = DealManager::instance()->create_deal( $data );
		if ( ! $deal ) {
			return false;
		}
		return true;
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'title'    => array(
				'label'   => $this->t( 'Deal Title' ),
				'type'    => 'text',
				'tooltip' => $this->t( 'If no title is provided, the deal will be created with a default title.' ),
			),
			'value'    => array(
				'label' => $this->t( 'Deal Value' ),
				'type'  => 'text',
			),
			'owner'    => array(
				'label'   => $this->t( 'Owner' ),
				'type'    => 'select',
				'options' => $this->get_users_options(),
			),
			'pipeline' => array(
				'label'         => $this->t( 'Pipeline' ),
				'type'          => 'pipeline_stage_change',
				'endpoint'      => 'pipelines',
				'multiple'      => false,
				'default-value' => '',
			),
			'stage'    => array(
				'label'         => $this->t( 'Stage' ),
				'type'          => 'pipeline_stage_change',
				'endpoint'      => 'pipeline-stages',
				'multiple'      => false,
				'default-value' => '',
			),
		);
	}

	/**
	 * Get users options
	 *
	 * @return array
	 */
	public function get_users_options() {
		return parent::get_users_options();
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
				'title'    => array(
					'type'     => 'string',
					'required' => true,
				),
				'value'    => array(
					'type'     => 'string',
					'required' => true,
				),
				'owner'    => array(
					'type'     => 'integer',
					'required' => true,
				),
				'pipeline' => array(
					'type'     => 'integer',
					'required' => true,
				),
				'stage'    => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}
}
