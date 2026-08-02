<?php
/**
 * Credit note applications table migration.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SalesCreditNoteApplicationsTable migration.
 */
class SalesCreditNoteApplicationsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_credit_note_applications';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			credit_note_id BIGINT(20) UNSIGNED NOT NULL,
			invoice_id BIGINT(20) UNSIGNED NOT NULL,
			amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			applied_date DATE NULL,
			note TEXT NULL,
			applied_by_user_id BIGINT(20) UNSIGNED NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_credit_note_id (credit_note_id),
			KEY idx_invoice_id (invoice_id)";
	}
}
