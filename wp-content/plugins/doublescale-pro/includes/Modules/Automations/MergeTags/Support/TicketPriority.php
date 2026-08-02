<?php
/**
 * Support ticket merge tag: Ticket Priority.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketPriority extends BaseSupportMergeTag {

	public $name = 'Ticket Priority';

	public $slug = 'ticket_priority';

	public $description = 'Ticket Priority';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		return \DoubleScale\Modules\Support\Constants\TicketPriority::get_label( (string) $ticket->priority );
	}
}

MergeTagsManager::instance()->register( new TicketPriority() );
