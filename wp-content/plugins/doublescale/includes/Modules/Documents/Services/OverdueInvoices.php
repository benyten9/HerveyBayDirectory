<?php
/**
 * Mark past-due invoices as overdue.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;

/**
 * OverdueInvoices cron runner.
 */
class OverdueInvoices {

	/**
	 * Bulk-update unpaid/partially paid invoices past due date.
	 *
	 * @return void
	 */
	public function run(): void {
		$today = current_time( 'Y-m-d' );

		// Nothing to pay is paid, never overdue: settles those sent before
		// that rule too. A status fix only, so no "paid" event fires.
		InvoiceModel::query()
			->whereIn( 'status', array( InvoiceStatus::UNPAID, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::OVERDUE ) )
			->where( 'total', '<=', 0 )
			->update( array( 'status' => InvoiceStatus::PAID ) );

		InvoiceModel::query()
			->whereIn( 'status', array( InvoiceStatus::UNPAID, InvoiceStatus::PARTIALLY_PAID ) )
			->where( 'due_date', '<', $today )
			->where( 'total', '>', 0 )
			->update( array( 'status' => InvoiceStatus::OVERDUE ) );
	}
}
