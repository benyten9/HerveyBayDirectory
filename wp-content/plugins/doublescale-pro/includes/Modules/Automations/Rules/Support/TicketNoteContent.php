<?php
/**
 * Automation rule: support ticket internal note content.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Automations\Services\RulesManager;

defined( 'ABSPATH' ) || exit;

class TicketNoteContent extends BaseSupportRule {

	public $name = 'Note Content';

	public $slug = 'ticket_note_content';

	public $type = 'text';

	public $required_triggers = array( 'ticket_note_added' );

	public function get_operators() {
		return array(
			'contains'     => __( 'Contains', 'doublescale' ),
			'not_contains' => __( 'Does not contain', 'doublescale' ),
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'starts_with'  => __( 'Starts with', 'doublescale' ),
			'ends_with'    => __( 'Ends with', 'doublescale' ),
			'is_empty'     => __( 'Is empty', 'doublescale' ),
			'is_not_empty' => __( 'Is not empty', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		return $this->get_trigger_activity_plain_text( $automation_contact, ActivityTypes::SUPPORT_NOTE );
	}
}

BaseSupportRule::register( new TicketNoteContent() );
