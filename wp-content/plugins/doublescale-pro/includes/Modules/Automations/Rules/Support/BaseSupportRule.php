<?php
/**
 * Shared base for support-ticket automation rules (conditions).
 *
 * Resolves the ticket the rule is evaluated against from the enrollment data
 * (`data.ticket_id`, set by the support lifecycle triggers).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Automations\Support\SupportConversationHelper;

defined( 'ABSPATH' ) || exit;

abstract class BaseSupportRule extends Rule {

	/**
	 * Group.
	 *
	 * @var string
	 */
	public $group = 'support';

	/**
	 * Only meaningful alongside the support triggers.
	 *
	 * @var array
	 */
	public $required_triggers = array(
		'ticket_created',
		'ticket_reply_added',
		'ticket_note_added',
		'ticket_status_changed',
		'ticket_priority_changed',
		'ticket_agent_assigned',
		'ticket_closed',
	);

	/**
	 * Resolve the ticket for the current enrollment.
	 *
	 * @param object $automation_contact Automation contact model.
	 * @return TicketModel|null
	 */
	protected function resolve_ticket( $automation_contact ) {
		if ( ! self::storage_ready() ) {
			return null;
		}

		$ticket_id = isset( $automation_contact->data['ticket_id'] )
			? (int) $automation_contact->data['ticket_id']
			: 0;
		if ( $ticket_id <= 0 ) {
			return null;
		}
		return TicketModel::find( $ticket_id );
	}

	/**
	 * Plain-text opening message for rule comparisons.
	 *
	 * @param object $automation_contact Automation contact model.
	 * @return string
	 */
	protected function get_opening_plain_text( $automation_contact ): string {
		$ticket = $this->resolve_ticket( $automation_contact );
		if ( ! $ticket ) {
			return '';
		}
		return SupportConversationHelper::plain_text(
			SupportConversationHelper::get_opening_content( $ticket )
		);
	}

	/**
	 * Plain-text content from the activity that fired the trigger.
	 *
	 * @param object      $automation_contact Automation contact model.
	 * @param string|null $type               Activity type filter.
	 * @return string
	 */
	protected function get_trigger_activity_plain_text( $automation_contact, ?string $type = null ): string {
		return SupportConversationHelper::plain_text(
			SupportConversationHelper::get_trigger_activity_content( $automation_contact, $type )
		);
	}

	/**
	 * Register a support rule when the support module and tables are ready.
	 *
	 * @param Rule $rule Rule instance.
	 */
	public static function register( Rule $rule ): void {
		AutomationModuleStorage::register_rule( $rule, 'support', MailboxModel::class );
	}

	/**
	 * Whether support storage is safe to query.
	 */
	protected static function storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'support', MailboxModel::class );
	}
}
