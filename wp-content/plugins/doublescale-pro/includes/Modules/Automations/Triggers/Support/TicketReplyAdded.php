<?php
/**
 * Automation trigger: reply added to a support ticket.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Support;

defined( 'ABSPATH' ) || exit;

final class TicketReplyAdded extends AbstractSupportLifecycleTrigger {

	public $name = 'Ticket reply added';

	public $slug = 'ticket_reply_added';

	public $description = 'Fires when a reply is posted to a support ticket.';

	public $attributes = array();

	public function load_hooks() {
		add_action( 'doublescale_support_reply_created', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $activity The reply activity.
	 * @param mixed $ticket   {@see \DoubleScale\Modules\Support\Models\TicketModel} instance.
	 */
	public function handle( $activity, $ticket = null ): void {
		$this->enroll_from_activity( $ticket, $activity );
	}
}
