<?php
/**
 * Automation rule: invoice balance due.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Invoice;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

defined( 'ABSPATH' ) || exit;

class InvoiceBalance extends BaseInvoiceRule {

	public $name = 'Invoice Balance';

	public $slug = 'invoice_balance';

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
		$invoice = $this->resolve_invoice( $automation_contact );
		if ( ! $invoice ) {
			return 0.0;
		}
		return max( 0.0, (float) $invoice->total - (float) $invoice->amount_paid );
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

BaseInvoiceRule::register( new InvoiceBalance() );
