<?php
/**
 * Automation rule: invoice status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Invoice;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus as StatusConst;

defined( 'ABSPATH' ) || exit;

class InvoiceStatus extends BaseInvoiceRule {

	public $name = 'Invoice Status';

	public $slug = 'invoice_status';

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
			$options[ $status ] = StatusConst::get_label( $status );
		}
		return $options;
	}

	public function get_value( $automation_contact ) {
		$invoice = $this->resolve_invoice( $automation_contact );
		return $invoice ? (string) $invoice->status : '';
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

BaseInvoiceRule::register( new InvoiceStatus() );
