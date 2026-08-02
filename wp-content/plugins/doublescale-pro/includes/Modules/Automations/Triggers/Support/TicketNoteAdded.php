<?php
/**
 * Automation trigger: internal note added to a support ticket.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Support;

defined( 'ABSPATH' ) || exit;

final class TicketNoteAdded extends AbstractSupportLifecycleTrigger {

	public $name = 'Ticket note added';

	public $slug = 'ticket_note_added';

	public $description = 'Fires when an internal note is added to a support ticket.';

	public $attributes = array();

	public function load_hooks() {
		add_action( 'doublescale_support_note_created', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $activity The note activity.
	 * @param mixed $ticket   {@see \DoubleScale\Modules\Support\Models\TicketModel} instance.
	 */
	public function handle( $activity, $ticket = null ): void {
		$this->enroll_from_activity( $ticket, $activity );
	}
}
