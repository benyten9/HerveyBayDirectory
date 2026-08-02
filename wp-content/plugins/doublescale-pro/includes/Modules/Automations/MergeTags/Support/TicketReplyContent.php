<?php
/**
 * Support ticket merge tag: Reply Content.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketReplyContent extends BaseSupportMergeTag {

	public $name = 'Reply Content';

	public $slug = 'ticket_reply_content';

	public $description = 'The reply that triggered the automation';

	public $required_triggers = array( 'ticket_reply_added' );

	public function get_value( $contact, $merge_tag = '' ) {
		return $this->resolve_trigger_activity_content( $contact, ActivityTypes::SUPPORT_REPLY );
	}
}

MergeTagsManager::instance()->register( new TicketReplyContent() );
