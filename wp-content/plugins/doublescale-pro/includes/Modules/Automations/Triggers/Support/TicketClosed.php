<?php
/**
 * Automation trigger: support ticket closed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Support;

use DoubleScale\Modules\Support\Constants\TicketStatus;

defined( 'ABSPATH' ) || exit;

final class TicketClosed extends AbstractSupportLifecycleTrigger {

	public $name = 'Ticket closed';

	public $slug = 'ticket_closed';

	public $description = 'Fires when a support ticket is closed.';

	public $attributes = array();

	public function load_hooks() {
		add_action( 'doublescale_support_ticket_updated', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * @param mixed $ticket    {@see \DoubleScale\Modules\Support\Models\TicketModel} instance.
	 * @param mixed $effective Changed keys with their new values.
	 * @param mixed $before    Same keys with their pre-save values.
	 */
	public function handle( $ticket, $effective = array(), $before = array() ): void {
		if ( ! is_array( $effective ) || ! array_key_exists( 'status', $effective ) ) {
			return;
		}
		if ( TicketStatus::CLOSED !== $effective['status'] ) {
			return;
		}
		$this->enroll_from_ticket(
			$ticket,
			array(
				'old_status' => is_array( $before ) ? ( $before['status'] ?? '' ) : '',
				'new_status' => TicketStatus::CLOSED,
			)
		);
	}
}
