<?php
/**
 * Credit notes table migration.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SalesCreditNotesTable migration.
 */
class SalesCreditNotesTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_credit_notes';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			credit_note_number VARCHAR(50) NOT NULL,
			hash VARCHAR(32) NOT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'open',
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			invoice_id BIGINT(20) UNSIGNED NULL,
			sale_agent_user_id BIGINT(20) UNSIGNED NULL,
			credit_note_date DATE NULL,
			reason VARCHAR(191) NULL,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
			discount_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			tag_ids JSON NULL,
			line_items JSON NULL,
			subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			total_tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			adjustment DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			amount_applied DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			billing_address TEXT NULL,
			client_note TEXT NULL,
			terms TEXT NULL,
			sent_at DATETIME NULL,
			viewed_at DATETIME NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY credit_note_number (credit_note_number),
			UNIQUE KEY hash (hash),
			KEY idx_contact_status (contact_id, status),
			KEY idx_agent_status (sale_agent_user_id, status),
			KEY idx_invoice_id (invoice_id),
			KEY idx_created (created_at)";
	}
}
