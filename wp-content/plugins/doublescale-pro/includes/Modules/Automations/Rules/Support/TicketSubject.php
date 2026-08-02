<?php
/**
 * Automation rule: support ticket subject (title).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

defined( 'ABSPATH' ) || exit;

class TicketSubject extends BaseSupportRule {

	public $name = 'Ticket Subject';

	public $slug = 'ticket_subject';

	public $type = 'text';

	public function get_operators() {
		return array(
			'contains'         => __( 'Contains', 'doublescale' ),
			'not_contains'     => __( 'Does not contain', 'doublescale' ),
			'is'               => __( 'Is', 'doublescale' ),
			'is_not'           => __( 'Is not', 'doublescale' ),
			'starts_with'      => __( 'Starts with', 'doublescale' ),
			'ends_with'        => __( 'Ends with', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$ticket = $this->resolve_ticket( $automation_contact );
		return $ticket ? (string) $ticket->title : '';
	}

	/**
	 * Reuses the base operator engine (contains/starts_with/…).
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param array                  $rule               Rule config.
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		return parent::is_met( $automation_contact, $rule );
	}
}

BaseSupportRule::register( new TicketSubject() );
