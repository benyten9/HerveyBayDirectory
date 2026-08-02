<?php
/**
 * Automation trigger: support ticket created.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Support;

use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Pro\Modules\Automations\Support\SupportConversationHelper;

defined( 'ABSPATH' ) || exit;

final class TicketCreated extends AbstractSupportLifecycleTrigger {

	public $name = 'Ticket created';

	public $slug = 'ticket_created';

	public $description = 'Fires when a new support ticket is opened.';

	public $attributes = array();

	public function load_hooks() {
		add_action( 'doublescale_support_ticket_created', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * @param mixed $ticket {@see \DoubleScale\Modules\Support\Models\TicketModel} instance.
	 */
	public function handle( $ticket ): void {
		$extra = array();
		if ( $ticket instanceof TicketModel ) {
			$opening = SupportConversationHelper::get_opening_activity( $ticket );
			if ( $opening ) {
				$extra['activity_id'] = (int) $opening->id;
			}
		}
		$this->enroll_from_ticket( $ticket, $extra );
	}
}
