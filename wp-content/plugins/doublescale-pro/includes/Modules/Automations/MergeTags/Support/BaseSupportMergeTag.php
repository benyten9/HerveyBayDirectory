<?php
/**
 * Shared base for support-ticket automation merge tags.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Pro\Modules\Automations\Support\SupportConversationHelper;

defined( 'ABSPATH' ) || exit;

abstract class BaseSupportMergeTag extends MergeTag {

	public $group = 'support';

	public $is_automation = true;

	/**
	 * Resolve the ticket from the automation contact's enrollment data.
	 *
	 * @param mixed $contact Automation contact model.
	 * @return TicketModel|null
	 */
	protected function resolve_ticket( $contact ) {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}
		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'support', MailboxModel::class ) ) {
			return null;
		}
		$ticket_id = isset( $contact->data['ticket_id'] ) ? (int) $contact->data['ticket_id'] : 0;
		if ( $ticket_id <= 0 ) {
			return null;
		}
		return TicketModel::find( $ticket_id );
	}

	/**
	 * @param AutomationContactModel|null $contact Automation contact.
	 * @return string
	 */
	protected function resolve_opening_content( $contact ): string {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		return SupportConversationHelper::get_opening_content( $ticket );
	}

	/**
	 * @param AutomationContactModel|null $contact Automation contact.
	 * @param string|null                 $type    Activity type filter.
	 * @return string
	 */
	protected function resolve_trigger_activity_content( $contact, ?string $type = null ): string {
		if ( ! $contact instanceof AutomationContactModel ) {
			return '';
		}
		return SupportConversationHelper::get_trigger_activity_content( $contact, $type );
	}
}
