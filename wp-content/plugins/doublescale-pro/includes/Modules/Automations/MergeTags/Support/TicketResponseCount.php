<?php
/**
 * Support ticket merge tag: Ticket Response Count.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketResponseCount extends BaseSupportMergeTag {

	public $name = 'Ticket Response Count';

	public $slug = 'ticket_response_count';

	public $description = 'Ticket Response Count';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		return (string) ( (int) $ticket->response_count );
	}
}

MergeTagsManager::instance()->register( new TicketResponseCount() );
