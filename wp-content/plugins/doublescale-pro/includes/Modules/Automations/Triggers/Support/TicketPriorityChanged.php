<?php
/**
 * Automation trigger: support ticket priority changed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Support;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Support\Constants\TicketPriority;

defined( 'ABSPATH' ) || exit;

final class TicketPriorityChanged extends AbstractSupportLifecycleTrigger {

	public $name = 'Ticket priority changed';

	public $slug = 'ticket_priority_changed';

	public $description = 'Fires when a support ticket changes priority.';

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
		if ( ! is_array( $effective ) || ! array_key_exists( 'priority', $effective ) ) {
			return;
		}
		$this->enroll_from_ticket(
			$ticket,
			array(
				'old_priority' => is_array( $before ) ? ( $before['priority'] ?? '' ) : '',
				'new_priority' => $effective['priority'],
			)
		);
	}

	/**
	 * When a target priority is configured, only proceed if the ticket moved to it.
	 *
	 * @param AutomationModel $automation Automation model.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$target = $automation->get_setting( 'priority', '' );
		if ( '' === $target ) {
			return true;
		}
		return ( $args['data']['new_priority'] ?? '' ) === $target;
	}

	public function get_fields() {
		return array(
			'priority' => array(
				'type'    => 'select',
				'label'   => __( 'Priority changes to', 'doublescale' ),
				'tooltip' => __( 'Leave empty to run on any priority change.', 'doublescale' ),
				'options' => array(
					''                     => __( 'Any priority', 'doublescale' ),
					TicketPriority::LOW    => __( 'Low', 'doublescale' ),
					TicketPriority::NORMAL => __( 'Normal', 'doublescale' ),
					TicketPriority::HIGH   => __( 'High', 'doublescale' ),
					TicketPriority::URGENT => __( 'Urgent', 'doublescale' ),
				),
			),
		);
	}

	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'priority' => array(
					'type' => 'string',
				),
			),
		);
	}
}
