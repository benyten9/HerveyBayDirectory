<?php
/**
 * Support ticket merge tag: Note Content.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\MergeTags\Support;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

class TicketNoteContent extends BaseSupportMergeTag {

	public $name = 'Note Content';

	public $slug = 'ticket_note_content';

	public $description = 'The internal note that triggered the automation';

	public $required_triggers = array( 'ticket_note_added' );

	public function get_value( $contact, $merge_tag = '' ) {
		return $this->resolve_trigger_activity_content( $contact, ActivityTypes::SUPPORT_NOTE );
	}
}

MergeTagsManager::instance()->register( new TicketNoteContent() );
