<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Support;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Support\Constants\TicketStatus;

/**
 * Automation action: close the contact's ticket(s).
 *
 * @since 1.3.0
 */
class CloseTicket extends BaseSupportAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Close a ticket';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'close_ticket';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will close a support ticket for the contact.';

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
		$service = $this->ticket_service();
		$tickets = $this->get_target_tickets(
			array( 'affects' => $step->get_setting( 'affects' ) ),
			$automation_contact
		);

		foreach ( $tickets as $ticket ) {
			$service->update_ticket( $ticket, array( 'status' => TicketStatus::CLOSED ) );
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
			'affects' => array(
				'label'   => $this->t( 'Affects' ),
				'type'    => 'select',
				'options' => $this->get_effects_options(),
				'tooltip' => $this->t( 'Which ticket(s) belonging to this contact should be closed.' ),
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
				'affects' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}
