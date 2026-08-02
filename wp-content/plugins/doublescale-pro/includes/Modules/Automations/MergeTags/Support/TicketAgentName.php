<?php
/**
 * Support ticket merge tag: Ticket Agent Name.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketAgentName extends BaseSupportMergeTag {

	public $name = 'Ticket Agent Name';

	public $slug = 'ticket_agent_name';

	public $description = 'Ticket Agent Name';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		$agent_id = (int) $ticket->agent_user_id;
		if ( $agent_id <= 0 ) {
			return '';
		}
		$user = get_userdata( $agent_id );
		return $user ? (string) $user->display_name : '';
	}
}

MergeTagsManager::instance()->register( new TicketAgentName() );
