<?php
/**
 * Support ticket merge tag: Ticket Subject.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketSubject extends BaseSupportMergeTag {

	public $name = 'Ticket Subject';

	public $slug = 'ticket_subject';

	public $description = 'Ticket Subject';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		return (string) $ticket->title;
	}
}

MergeTagsManager::instance()->register( new TicketSubject() );
