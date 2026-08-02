<?php
/**
 * Support ticket merge tag: Ticket Created Date.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketCreatedDate extends BaseSupportMergeTag {

	public $name = 'Ticket Created Date';

	public $slug = 'ticket_created_date';

	public $description = 'Ticket Created Date';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		$created = $ticket->created_at;
		if ( empty( $created ) ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ), strtotime( (string) $created ) );
	}
}

MergeTagsManager::instance()->register( new TicketCreatedDate() );
