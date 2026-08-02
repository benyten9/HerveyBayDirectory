<?php
/**
 * Base class for support-ticket lifecycle automation triggers.
 *
 * Listens on the canonical `doublescale_support_ticket_*` actions fired by
 * {@see \DoubleScale\Modules\Support\Services\TicketService} and enrolls the
 * ticket's contact into matching automations. Mirrors the Booking trigger
 * family ({@see \DoubleScale\Pro\Modules\Automations\Triggers\Booking\AbstractBookingLifecycleTrigger}).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Support;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Support\Models\TicketModel;

defined( 'ABSPATH' ) || exit;

abstract class AbstractSupportLifecycleTrigger extends Trigger {

	/**
	 * Source.
	 *
	 * @var string
	 */
	public $source = 'support';

	/**
	 * Group.
	 *
	 * @var string
	 */
	public $group = 'support';

	/**
	 * Build the enrollment args for a ticket and run the automation match.
	 *
	 * The ticket's contact is required: automation enrollment keys on a contact
	 * ({@see \DoubleScale\Modules\Automations\Engine\ContactEnrollment::add_contact()}),
	 * so a ticket with no resolved contact is skipped. `data.ticket_id` is the
	 * canonical handle that support rules and merge tags read back.
	 *
	 * @param mixed $ticket {@see TicketModel} instance.
	 * @param array $extra  Extra entries to merge into `data` (e.g. status from/to).
	 * @return void
	 */
	/**
	 * Enroll using a ticket plus the activity that fired (reply / note).
	 *
	 * Stores `activity_id` in enrollment data so merge tags and rules can read
	 * the message that triggered the automation.
	 *
	 * @param mixed $ticket   {@see TicketModel} instance.
	 * @param mixed $activity {@see \DoubleScale\Modules\Activities\Models\ActivityModel} instance.
	 * @param array $extra    Extra entries merged into `data`.
	 * @return void
	 */
	protected function enroll_from_activity( $ticket, $activity, array $extra = array() ): void {
		if ( is_object( $activity ) && isset( $activity->id ) ) {
			$extra['activity_id'] = (int) $activity->id;
		}
		$this->enroll_from_ticket( $ticket, $extra );
	}

	protected function enroll_from_ticket( $ticket, array $extra = array() ): void {
		if ( ! $ticket instanceof TicketModel ) {
			return;
		}
		$contact = $ticket->contact;
		if ( ! $contact ) {
			return;
		}

		$data = array_merge(
			array( 'ticket_id' => (int) $ticket->id ),
			$extra
		);

		$this->process(
			array(
				'contact' => $contact,
				'ticket'  => $ticket,
				'data'    => $data,
			)
		);
	}
}
