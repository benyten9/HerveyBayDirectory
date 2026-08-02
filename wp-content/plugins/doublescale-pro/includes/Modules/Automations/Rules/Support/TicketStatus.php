<?php
/**
 * Automation rule: support ticket status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Support\Constants\TicketStatus as StatusConst;

defined( 'ABSPATH' ) || exit;

class TicketStatus extends BaseSupportRule {

	public $name = 'Ticket Status';

	public $slug = 'ticket_status';

	public $type = 'select';

	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	public function get_options() {
		return array(
			StatusConst::OPEN     => __( 'Open', 'doublescale' ),
			StatusConst::PENDING  => __( 'Pending', 'doublescale' ),
			StatusConst::RESOLVED => __( 'Resolved', 'doublescale' ),
			StatusConst::CLOSED   => __( 'Closed', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$ticket = $this->resolve_ticket( $automation_contact );
		return $ticket ? (string) $ticket->status : '';
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

BaseSupportRule::register( new TicketStatus() );
