<?php
/**
 * Automation rule: support ticket priority.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Support\Constants\TicketPriority as PriorityConst;

defined( 'ABSPATH' ) || exit;

class TicketPriority extends BaseSupportRule {

	public $name = 'Ticket Priority';

	public $slug = 'ticket_priority';

	public $type = 'select';

	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	public function get_options() {
		return array(
			PriorityConst::LOW    => __( 'Low', 'doublescale' ),
			PriorityConst::NORMAL => __( 'Normal', 'doublescale' ),
			PriorityConst::HIGH   => __( 'High', 'doublescale' ),
			PriorityConst::URGENT => __( 'Urgent', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$ticket = $this->resolve_ticket( $automation_contact );
		return $ticket ? (string) $ticket->priority : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value = $this->get_value( $automation_contact );
		switch ( $rule['operator'] ?? '' ) {
			case 'is':
				return $value == $rule['value']; // phpcs:ignore
			case 'is_not':
				return $value != $rule['value']; // phpcs:ignore
			default:
				return false;
		}
	}
}

BaseSupportRule::register( new TicketPriority() );
