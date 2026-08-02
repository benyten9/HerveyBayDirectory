<?php
/**
 * Automation rule: credit note total.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\CreditNote;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

defined( 'ABSPATH' ) || exit;

class CreditNoteTotal extends BaseCreditNoteRule {

	public $name = 'Credit Note Total';

	public $slug = 'credit_note_total';

	public $type = 'number';

	public function get_operators() {
		return array(
			'equal_to'                 => __( 'Equal to', 'doublescale' ),
			'not_equal_to'             => __( 'Not equal to', 'doublescale' ),
			'greater_than'             => __( 'Greater than', 'doublescale' ),
			'less_than'                => __( 'Less than', 'doublescale' ),
			'greater_than_or_equal_to' => __( 'Greater than or equal to', 'doublescale' ),
			'less_than_or_equal_to'    => __( 'Less than or equal to', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$credit_note = $this->resolve_credit_note( $automation_contact );
		return $credit_note ? (float) $credit_note->total : 0.0;
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = $this->get_value( $automation_contact );
		$operator   = $rule['operator'] ?? '';
		$rule_value = (float) ( $rule['value'] ?? 0 );

		switch ( $operator ) {
			case 'equal_to':
				return $value == $rule_value; // phpcs:ignore
			case 'not_equal_to':
				return $value != $rule_value; // phpcs:ignore
			case 'greater_than':
				return $value > $rule_value;
			case 'less_than':
				return $value < $rule_value;
			case 'greater_than_or_equal_to':
				return $value >= $rule_value;
			case 'less_than_or_equal_to':
				return $value <= $rule_value;
			default:
				return false;
		}
	}
}

BaseCreditNoteRule::register( new CreditNoteTotal() );
