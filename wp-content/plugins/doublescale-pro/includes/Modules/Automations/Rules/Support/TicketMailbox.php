<?php
/**
 * Automation rule: support ticket mailbox.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Support\Models\MailboxModel;

defined( 'ABSPATH' ) || exit;

class TicketMailbox extends BaseSupportRule {

	public $name = 'Ticket Mailbox';

	public $slug = 'ticket_mailbox';

	public $type = 'select';

	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	public function get_options() {
		if ( ! self::storage_ready() ) {
			return array();
		}

		$options = array();
		foreach ( MailboxModel::all() as $mailbox ) {
			$options[ (string) $mailbox->id ] = $mailbox->name ?? $mailbox->email;
		}
		return $options;
	}

	public function get_value( $automation_contact ) {
		$ticket = $this->resolve_ticket( $automation_contact );
		return $ticket ? (string) $ticket->mailbox_id : '';
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

BaseSupportRule::register( new TicketMailbox() );
