<?php
/**
 * Invoices table migration.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SalesInvoicesTable migration.
 */
class SalesInvoicesTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_invoices';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_number VARCHAR(50) NOT NULL,
			hash VARCHAR(32) NOT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'draft',
			template TINYINT UNSIGNED NOT NULL DEFAULT 1,
			template_color VARCHAR(7) NULL DEFAULT NULL,
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			proposal_id BIGINT(20) UNSIGNED NULL,
			subscription_id BIGINT(20) UNSIGNED NULL,
			recurrence_id BIGINT(20) UNSIGNED NULL,
			sale_agent_user_id BIGINT(20) UNSIGNED NULL,
			invoice_date DATE NULL,
			due_date DATE NULL,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			allowed_payment_modes JSON NULL,
			discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
			discount_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			tag_ids JSON NULL,
			line_items JSON NULL,
			subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			total_tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			adjustment DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			external_payment_ref VARCHAR(191) NULL,
			stripe_payment_intent_id VARCHAR(191) NULL,
			billing_address TEXT NULL,
			shipping_address TEXT NULL,
			client_note TEXT NULL,
			terms TEXT NULL,
			sent_at DATETIME NULL,
			viewed_at DATETIME NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_number (invoice_number),
			UNIQUE KEY hash (hash),
			UNIQUE KEY proposal_id (proposal_id),
			KEY idx_proposal_id (proposal_id),
			KEY idx_subscription_id (subscription_id),
			KEY idx_contact_status (contact_id, status),
			KEY idx_agent_status (sale_agent_user_id, status),
			KEY idx_due_date (due_date),
			KEY idx_created (created_at)";
	}
}
