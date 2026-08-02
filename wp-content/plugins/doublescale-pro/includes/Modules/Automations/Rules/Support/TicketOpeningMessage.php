<?php
/**
 * Automation rule: support ticket opening message.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Support;

use DoubleScale\Modules\Automations\Services\RulesManager;

defined( 'ABSPATH' ) || exit;

class TicketOpeningMessage extends BaseSupportRule {

	public $name = 'Opening Message';

	public $slug = 'ticket_opening_message';

	public $type = 'text';

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
		return $this->get_opening_plain_text( $automation_contact );
	}
}

BaseSupportRule::register( new TicketOpeningMessage() );
