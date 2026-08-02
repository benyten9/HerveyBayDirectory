<?php
/**
 * Support ticket merge tag: Opening Message.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketOpeningMessage extends BaseSupportMergeTag {

	public $name = 'Opening Message';

	public $slug = 'ticket_opening_message';

	public $description = 'The opening message on the support ticket';

	public function get_value( $contact, $merge_tag = '' ) {
		return $this->resolve_opening_content( $contact );
	}
}

MergeTagsManager::instance()->register( new TicketOpeningMessage() );
