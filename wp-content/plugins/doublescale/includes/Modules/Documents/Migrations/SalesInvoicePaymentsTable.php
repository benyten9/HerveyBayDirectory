<?php
/**
 * Invoice payments table migration.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SalesInvoicePaymentsTable migration.
 */
class SalesInvoicePaymentsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_invoice_payments';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT(20) UNSIGNED NOT NULL,
			amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			payment_mode VARCHAR(50) NULL,
			payment_date DATE NULL,
			transaction_id VARCHAR(191) NULL,
			note TEXT NULL,
			recorded_by_user_id BIGINT(20) UNSIGNED NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_invoice_id (invoice_id),
			KEY idx_payment_date (payment_date)";
	}
}
