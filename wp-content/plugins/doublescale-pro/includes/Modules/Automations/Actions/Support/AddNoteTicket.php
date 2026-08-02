<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Support;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Automation action: add an internal (agent-only) note to the contact's ticket(s).
 *
 * @since 1.3.0
 */
class AddNoteTicket extends BaseSupportAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add a ticket note';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'add_note_ticket';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add an internal note to a support ticket.';

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
		$note = $this->parse_text( $step->get_setting( 'note' ), $automation_contact );
		if ( '' === trim( wp_strip_all_tags( (string) $note ) ) ) {
			return false;
		}

		$service = $this->ticket_service();
		$tickets = $this->get_target_tickets(
			array( 'affects' => $step->get_setting( 'affects' ) ),
			$automation_contact
		);

		foreach ( $tickets as $ticket ) {
			$service->add_note( $ticket, array( 'content' => $note ) );
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
			'note'    => array(
				'label'   => $this->t( 'Internal Note' ),
				'type'    => 'textarea',
				'tooltip' => $this->t( 'Visible to agents only. Supports merge tags.' ),
			),
			'affects' => array(
				'label'   => $this->t( 'Affects' ),
				'type'    => 'select',
				'options' => $this->get_effects_options(),
				'tooltip' => $this->t( 'Which ticket(s) belonging to this contact should receive the note.' ),
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
				'note'    => array(
					'type'     => 'string',
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
