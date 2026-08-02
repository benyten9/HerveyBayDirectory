<?php
/**
 * Automation rule: support ticket opening channel (web vs email).
 *
 * Reads the source on the ticket's opening reply activity (`data.source`).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Pro\Modules\Automations\Support\SupportConversationHelper;

defined( 'ABSPATH' ) || exit;

class TicketSource extends BaseSupportRule {

	public $name = 'Ticket Source';

	public $slug = 'ticket_source';

	public $type = 'select';

	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	public function get_options() {
		return array(
			'web'   => __( 'Web / Portal', 'doublescale' ),
			'email' => __( 'Email', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$ticket = $this->resolve_ticket( $automation_contact );
		if ( ! $ticket ) {
			return '';
		}
		return SupportConversationHelper::get_opening_source( $ticket );
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

BaseSupportRule::register( new TicketSource() );
