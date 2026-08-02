<?php
/**
 * Support ticket merge tag: Ticket Mailbox.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketMailbox extends BaseSupportMergeTag {

	public $name = 'Ticket Mailbox';

	public $slug = 'ticket_mailbox';

	public $description = 'Ticket Mailbox';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		$mailbox = $ticket->mailbox;
		if ( ! $mailbox ) {
			return '';
		}
		return (string) ( $mailbox->name ?? $mailbox->email );
	}
}

MergeTagsManager::instance()->register( new TicketMailbox() );
