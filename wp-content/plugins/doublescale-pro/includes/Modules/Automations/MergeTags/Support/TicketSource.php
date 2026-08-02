<?php
/**
 * Support ticket merge tag: Ticket Source.
 *
 * Opening channel (web / portal vs email) from the ticket's first reply.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Pro\Modules\Automations\Support\SupportConversationHelper;

defined( 'ABSPATH' ) || exit;

class TicketSource extends BaseSupportMergeTag {

	public $name = 'Ticket Source';

	public $slug = 'ticket_source';

	public $description = 'How the ticket was opened (web or email)';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}

		$source = SupportConversationHelper::get_opening_source( $ticket );
		$labels = array(
			'web'   => __( 'Web / Portal', 'doublescale' ),
			'email' => __( 'Email', 'doublescale' ),
		);

		return $labels[ $source ] ?? $source;
	}
}

MergeTagsManager::instance()->register( new TicketSource() );
