<?php
/**
 * Automation rule: support ticket assigned agent.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Core\UserRoles\UserRoles;

defined( 'ABSPATH' ) || exit;

class TicketAssignedAgent extends BaseSupportRule {

	public $name = 'Ticket Assigned Agent';

	public $slug = 'ticket_assigned_agent';

	public $type = 'select';

	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'is_empty'     => __( 'Is unassigned', 'doublescale' ),
			'is_not_empty' => __( 'Is assigned', 'doublescale' ),
		);
	}

	public function get_options() {
		$users   = get_users(
			array(
				'role__in' => array(
					UserRoles::SUPPORT_MANAGER,
					UserRoles::SUPPORT_AGENT,
					UserRoles::CRM_MANAGER,
					UserRoles::ADMINISTRATOR,
				),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		$options = array();
		foreach ( $users as $user ) {
			$options[ (string) $user->ID ] = $user->display_name;
		}
		return $options;
	}

	public function get_value( $automation_contact ) {
		$ticket = $this->resolve_ticket( $automation_contact );
		if ( ! $ticket ) {
			return '';
		}
		$agent_id = (int) $ticket->agent_user_id;
		return $agent_id > 0 ? (string) $agent_id : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value = $this->get_value( $automation_contact );
		switch ( $rule['operator'] ?? '' ) {
			case 'is':
				return $value == $rule['value']; // phpcs:ignore
			case 'is_not':
				return $value != $rule['value']; // phpcs:ignore
			case 'is_empty':
				return '' === $value;
			case 'is_not_empty':
				return '' !== $value;
			default:
				return false;
		}
	}
}

BaseSupportRule::register( new TicketAssignedAgent() );
