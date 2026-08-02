<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Support;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Modules\Support\Constants\TicketStatus;

/**
 * Automation action: open a new support ticket for the contact.
 *
 * @since 1.3.0
 */
class CreateTicket extends BaseSupportAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Create a ticket';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'create_ticket';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will open a new support ticket for the contact.';

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
		$title   = $this->parse_text( $step->get_setting( 'title' ), $automation_contact );
		$content = $this->parse_text( $step->get_setting( 'content' ), $automation_contact );

		if ( '' === trim( (string) $title ) ) {
			$title = $this->t( 'New ticket' );
		}
		if ( '' === trim( wp_strip_all_tags( (string) $content ) ) ) {
			$content = $title;
		}

		$data = array(
			'title'      => $title,
			'content'    => $content,
			'contact_id' => $automation_contact->contact->id,
			'priority'   => $step->get_setting( 'priority' ) ?: TicketPriority::NORMAL,
			'status'     => TicketStatus::OPEN,
			'source'     => 'system',
		);

		if ( $step->get_setting( 'mailbox' ) ) {
			$data['mailbox_id'] = (int) $step->get_setting( 'mailbox' );
		}
		if ( $step->get_setting( 'agent' ) ) {
			$data['agent_user_id'] = (int) $step->get_setting( 'agent' );
		}

		$ticket = $this->ticket_service()->create_ticket( $data );

		return ! is_wp_error( $ticket );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'title'    => array(
				'label'    => $this->t( 'Ticket Subject' ),
				'type'     => 'text',
				'required' => true,
				'tooltip'  => $this->t( 'Supports merge tags.' ),
			),
			'content'  => array(
				'label'    => $this->t( 'Opening Message' ),
				'type'     => 'textarea',
				'required' => true,
			),
			'mailbox'  => array(
				'label'    => $this->t( 'Mailbox' ),
				'type'     => 'select',
				'required' => true,
				'options'  => $this->get_mailboxes_options(),
				'tooltip'  => $this->t( 'Channel the ticket is routed to.' ),
			),
			'priority' => array(
				'label'    => $this->t( 'Priority' ),
				'type'     => 'select',
				'required' => true,
				'options'  => array(
					TicketPriority::LOW    => $this->t( 'Low' ),
					TicketPriority::NORMAL => $this->t( 'Normal' ),
					TicketPriority::HIGH   => $this->t( 'High' ),
					TicketPriority::URGENT => $this->t( 'Urgent' ),
				),
			),
			'agent'    => array(
				'label'    => $this->t( 'Assign Agent' ),
				'type'     => 'select',
				'required' => true,
				'options'  => $this->get_agents_options(),
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
				'title'    => array(
					'type'     => 'string',
					'required' => true,
				),
				'content'  => array(
					'type'     => 'string',
					'required' => true,
				),
				'mailbox'  => array(
					'type'     => 'integer',
					'required' => true,
				),
				'priority' => array(
					'type'     => 'string',
					'required' => true,
				),
				'agent'    => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}
}
