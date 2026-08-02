<?php
/**
 * Automation rule: credit note number.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\CreditNote;

defined( 'ABSPATH' ) || exit;

class CreditNoteNumber extends BaseCreditNoteRule {

	public $name = 'Credit Note Number';

	public $slug = 'credit_note_number';

	public $type = 'text';

	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'contains'     => __( 'Contains', 'doublescale' ),
			'not_contains' => __( 'Does not contain', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$credit_note = $this->resolve_credit_note( $automation_contact );
		return $credit_note ? (string) $credit_note->credit_note_number : '';
	}
}

BaseCreditNoteRule::register( new CreditNoteNumber() );
