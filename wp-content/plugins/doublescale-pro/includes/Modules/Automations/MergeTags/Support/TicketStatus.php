<?php
/**
 * Support ticket merge tag: Ticket Status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketStatus extends BaseSupportMergeTag {

	public $name = 'Ticket Status';

	public $slug = 'ticket_status';

	public $description = 'Ticket Status';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		return \DoubleScale\Modules\Support\Constants\TicketStatus::get_label( (string) $ticket->status );
	}
}

MergeTagsManager::instance()->register( new TicketStatus() );
