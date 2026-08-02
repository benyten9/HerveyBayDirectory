<?php
/**
 * Automation rule: invoice number.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Invoice;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

defined( 'ABSPATH' ) || exit;

class InvoiceNumber extends BaseInvoiceRule {

	public $name = 'Invoice Number';

	public $slug = 'invoice_number';

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
		$invoice = $this->resolve_invoice( $automation_contact );
		return $invoice ? (string) $invoice->invoice_number : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		return parent::is_met( $automation_contact, $rule );
	}
}

BaseInvoiceRule::register( new InvoiceNumber() );
