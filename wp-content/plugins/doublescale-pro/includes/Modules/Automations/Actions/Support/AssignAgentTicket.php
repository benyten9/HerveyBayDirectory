<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Support;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Automation action: assign an agent to the contact's ticket(s).
 *
 * @since 1.3.0
 */
class AssignAgentTicket extends BaseSupportAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Assign a ticket agent';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'assign_agent_ticket';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will assign an agent to a support ticket.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'support';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'support';

	/**
	 * Process Action
	 *
	 * @param AutomationModel        $automation         Automation Model.
	 * @param AutomationStepModel    $step               Automation Step Model.
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$agent = $step->get_setting( 'agent' );
		if ( empty( $agent ) ) {
			return false;
		}

		$service = $this->ticket_service();
		$tickets = $this->get_target_tickets(
			array( 'affects' => $step->get_setting( 'affects' ) ),
			$automation_contact
		);

		foreach ( $tickets as $ticket ) {
			$service->update_ticket( $ticket, array( 'agent_user_id' => (int) $agent ) );
		}

		return true;
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'agent'   => array(
				'label'   => $this->t( 'Agent' ),
				'type'    => 'select',
				'options' => $this->get_agents_options(),
			),
			'affects' => array(
				'label'   => $this->t( 'Affects' ),
				'type'    => 'select',
				'options' => $this->get_effects_options(),
				'tooltip' => $this->t( 'Which ticket(s) belonging to this contact should be reassigned.' ),
			),
		);
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
				'agent'   => array(
					'type'     => 'integer',
					'required' => true,
				),
				'affects' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}
