<?php
/**
 * Invoice recurrences table migration.
 *
 * One row per recurrence rule. The rule lives in its own table rather than as
 * columns on `sales_invoices`: if the schedule lived on the invoice row, every
 * generated copy would inherit it and start recurring on its own, fanning out
 * into a runaway tree of schedules.
 *
 * `template_invoice_id` is UNIQUE — an invoice is the template for at most one
 * rule. Generated children point back via the Free `sales_invoices.recurrence_id`
 * column (deliberately separate from `subscription_id`, which the Stripe-driven
 * Subscriptions add-on owns).
 *
 * `next_run_at` is indexed because the hourly sweep filters on it; without the
 * index every tick would scan the whole table.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SalesInvoiceRecurrencesTable migration.
 */
class SalesInvoiceRecurrencesTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_invoice_recurrences';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			template_invoice_id BIGINT(20) UNSIGNED NOT NULL,
			interval_value INT UNSIGNED NOT NULL DEFAULT 1,
			interval_unit VARCHAR(10) NOT NULL DEFAULT 'month',
			total_cycles INT UNSIGNED NOT NULL DEFAULT 0,
			is_infinite TINYINT(1) NOT NULL DEFAULT 1,
			cycles_done INT UNSIGNED NOT NULL DEFAULT 0,
			end_date DATE NULL,
			auto_send TINYINT(1) NOT NULL DEFAULT 0,
			require_paid TINYINT(1) NOT NULL DEFAULT 0,
			next_run_at DATETIME NULL,
			last_run_at DATETIME NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY template_invoice_id (template_invoice_id),
			KEY idx_due (is_active, next_run_at)";
	}
}
