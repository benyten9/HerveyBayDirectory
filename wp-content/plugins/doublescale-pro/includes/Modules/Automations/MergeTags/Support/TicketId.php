<?php
/**
 * Support ticket merge tag: Ticket ID.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketId extends BaseSupportMergeTag {

	public $name = 'Ticket ID';

	public $slug = 'ticket_id';

	public $description = 'Ticket ID';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		return (string) $ticket->id;
	}
}

MergeTagsManager::instance()->register( new TicketId() );
