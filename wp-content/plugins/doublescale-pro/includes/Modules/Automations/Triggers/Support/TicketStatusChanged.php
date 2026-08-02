<?php
/**
 * Automation trigger: support ticket status changed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Support;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Support\Constants\TicketStatus;

defined( 'ABSPATH' ) || exit;

final class TicketStatusChanged extends AbstractSupportLifecycleTrigger {

	public $name = 'Ticket status changed';

	public $slug = 'ticket_status_changed';

	public $description = 'Fires when a support ticket changes status.';

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
		$this->enroll_from_ticket(
			$ticket,
			array(
				'old_status' => is_array( $before ) ? ( $before['status'] ?? '' ) : '',
				'new_status' => $effective['status'],
			)
		);
	}

	/**
	 * When a target status is configured, only proceed if the ticket moved to it.
	 *
	 * @param AutomationModel $automation Automation model.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$target = $automation->get_setting( 'status', '' );
		if ( '' === $target ) {
			return true;
		}
		return ( $args['data']['new_status'] ?? '' ) === $target;
	}

	public function get_fields() {
		return array(
			'status' => array(
				'type'    => 'select',
				'label'   => __( 'Status changes to', 'doublescale' ),
				'tooltip' => __( 'Leave empty to run on any status change.', 'doublescale' ),
				'options' => array(
					''                     => __( 'Any status', 'doublescale' ),
					TicketStatus::OPEN     => __( 'Open', 'doublescale' ),
					TicketStatus::PENDING  => __( 'Pending', 'doublescale' ),
					TicketStatus::RESOLVED => __( 'Resolved', 'doublescale' ),
					TicketStatus::CLOSED   => __( 'Closed', 'doublescale' ),
				),
			),
		);
	}

	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status' => array(
					'type' => 'string',
				),
			),
		);
	}
}
