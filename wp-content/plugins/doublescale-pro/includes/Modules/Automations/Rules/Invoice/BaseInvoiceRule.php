<?php
/**
 * Shared base for invoice automation rules (conditions).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Invoice;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;

defined( 'ABSPATH' ) || exit;

abstract class BaseInvoiceRule extends Rule {

	/**
	 * @var string
	 */
	public $group = 'invoice';

	/**
	 * @var array
	 */
	public $required_triggers = array(
		'invoice_sent',
		'invoice_paid',
		'proposal_converted_to_invoice',
	);

	/**
	 * @param object $automation_contact Automation contact model.
	 * @return InvoiceModel|null
	 */
	protected function resolve_invoice( $automation_contact ): ?InvoiceModel {
		if ( ! self::storage_ready() ) {
			return null;
		}

		$invoice_id = isset( $automation_contact->data['invoice_id'] )
			? (int) $automation_contact->data['invoice_id']
			: 0;
		if ( $invoice_id <= 0 ) {
			return null;
		}

		$invoice = InvoiceModel::find( $invoice_id );
		return $invoice instanceof InvoiceModel ? $invoice : null;
	}

	/**
	 * @param Rule $rule Rule instance.
	 */
	public static function register( Rule $rule ): void {
		AutomationModuleStorage::register_rule( $rule, 'documents', InvoiceModel::class );
	}

	/**
	 * Whether sales invoice storage is safe to query.
	 */
	protected static function storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'documents', InvoiceModel::class );
	}
}
