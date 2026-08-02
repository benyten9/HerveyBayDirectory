<?php
/**
 * Support ticket merge tag: Ticket URL.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Support\Services\PortalUrl;

defined( 'ABSPATH' ) || exit;

class TicketUrl extends BaseSupportMergeTag {

	public $name = 'Ticket URL';

	public $slug = 'ticket_url';

	public $description = 'Ticket URL';

	public function get_value( $contact, $merge_tag = '' ) {
		$ticket = $this->resolve_ticket( $contact );
		if ( ! $ticket ) {
			return '';
		}
		$url = PortalUrl::get_ticket_url( $ticket );
		return '' !== $url ? esc_url_raw( $url ) : '';
	}
}

MergeTagsManager::instance()->register( new TicketUrl() );
