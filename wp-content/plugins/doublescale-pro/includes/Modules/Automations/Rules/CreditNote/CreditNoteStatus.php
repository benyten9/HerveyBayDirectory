<?php
/**
 * Automation rule: credit note status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\CreditNote;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus as StatusConst;

defined( 'ABSPATH' ) || exit;

class CreditNoteStatus extends BaseCreditNoteRule {

	public $name = 'Credit Note Status';

	public $slug = 'credit_note_status';

	public $type = 'select';

	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	public function get_options() {
		$options = array();
		foreach ( StatusConst::all() as $status ) {
			if ( StatusConst::DRAFT === $status ) {
				continue;
			}
			$options[ $status ] = StatusConst::get_label( $status );
		}
		return $options;
	}

	public function get_value( $automation_contact ) {
		$credit_note = $this->resolve_credit_note( $automation_contact );
		return $credit_note ? (string) $credit_note->status : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value = $this->get_value( $automation_contact );
		switch ( $rule['operator'] ?? '' ) {
			case 'is':
				return $value == $rule['value']; // phpcs:ignore
			case 'is_not':
				return $value != $rule['value']; // phpcs:ignore
			default:
				return false;
		}
	}
}

BaseCreditNoteRule::register( new CreditNoteStatus() );
