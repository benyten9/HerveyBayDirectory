<?php
/**
 * Automation trigger: support ticket assigned to an agent.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Support;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Core\UserRoles\UserRoles;

defined( 'ABSPATH' ) || exit;

final class TicketAgentAssigned extends AbstractSupportLifecycleTrigger {

	public $name = 'Ticket agent assigned';

	public $slug = 'ticket_agent_assigned';

	public $description = 'Fires when a support ticket is assigned to an agent.';

	public $attributes = array();

	public function load_hooks() {
		add_action( 'doublescale_support_ticket_updated', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Only fires on transitions to an actual agent (ignores un-assignment to NULL/0).
	 *
	 * @param mixed $ticket    {@see \DoubleScale\Modules\Support\Models\TicketModel} instance.
	 * @param mixed $effective Changed keys with their new values.
	 * @param mixed $before    Same keys with their pre-save values.
	 */
	public function handle( $ticket, $effective = array(), $before = array() ): void {
		if ( ! is_array( $effective ) || ! array_key_exists( 'agent_user_id', $effective ) ) {
			return;
		}
		$new_agent = (int) $effective['agent_user_id'];
		if ( $new_agent <= 0 ) {
			return;
		}
		$this->enroll_from_ticket(
			$ticket,
			array(
				'old_agent_user_id' => is_array( $before ) ? (int) ( $before['agent_user_id'] ?? 0 ) : 0,
				'new_agent_user_id' => $new_agent,
			)
		);
	}

	/**
	 * When specific agents are selected, only run if the ticket was assigned to
	 * one of them. An empty selection means "any agent".
	 *
	 * @param AutomationModel $automation Automation model.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$selected = array_filter( array_map( 'absint', (array) $automation->get_setting( 'agents', array() ) ) );
		if ( empty( $selected ) ) {
			return true;
		}
		$new_agent = (int) ( $args['data']['new_agent_user_id'] ?? 0 );
		return in_array( $new_agent, $selected, true );
	}

	public function get_fields() {
		return array(
			'agents' => array(
				'type'       => 'multiselect',
				'label'      => __( 'Assigned agents', 'doublescale' ),
				'options'    => $this->get_agents_options(),
				'helperText' => __( 'Optional: only when assigned to one of these agents. Leave empty for any agent.', 'doublescale' ),
			),
		);
	}

	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'agents' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'integer',
					),
				),
			),
		);
	}

	/**
	 * Support agents + managers + administrators, keyed by user ID.
	 *
	 * @return array<int|string, string>
	 */
	private function get_agents_options(): array {
		$users = get_users(
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
}
