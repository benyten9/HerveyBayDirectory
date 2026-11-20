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
		// Someone who does not work deals gets none: the contact's owner does
		// when they can, else the deal starts without an owner. The log says so.
		$owner = (int) $step->get_setting( 'owner' );
		$note  = '';
		if ( $owner > 0 && \DoubleScale\Pro\Modules\Deals\Services\DealOwnerRule::error( $owner ) ) {
			$picked        = get_userdata( $owner );
			$picked_name   = $picked ? $picked->display_name : '#' . $owner;
			$contact_owner = (int) ( $automation_contact->contact->owner_id ?? 0 );
			if ( $contact_owner > 0 && null === \DoubleScale\Pro\Modules\Deals\Services\DealOwnerRule::error( $contact_owner ) ) {
				$owner = $contact_owner;
				$note  = sprintf(
					/* translators: 1: person picked in the step, 2: the contact's owner, who got the deal */
					__( '%1$s cannot own deals, so the deal went to the contact\'s owner, %2$s.', 'doublescale' ),
					$picked_name,
					get_userdata( $contact_owner )->display_name
				);
			} else {
				$owner = 0;
				/* translators: %s: person picked in the step */
				$note = sprintf( __( '%s cannot own deals, so the deal was created without an owner.', 'doublescale' ), $picked_name );
			}
		}

		$data = array(
			'title'       => $title,
			'owner_id'    => $owner > 0 ? $owner : null,
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
		return '' === $note ? true : array(
			'success' => true,
			'message' => $note,
		);
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
