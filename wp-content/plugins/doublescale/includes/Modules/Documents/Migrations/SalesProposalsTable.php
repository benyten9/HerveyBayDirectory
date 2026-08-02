<?php
/**
 * Proposals table migration.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SalesProposalsTable migration.
 */
class SalesProposalsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_proposals';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			proposal_number VARCHAR(50) NOT NULL,
			hash VARCHAR(32) NOT NULL,
			subject VARCHAR(255) NOT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'draft',
			template TINYINT UNSIGNED NOT NULL DEFAULT 1,
			template_color VARCHAR(7) NULL DEFAULT NULL,
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			assigned_user_id BIGINT(20) UNSIGNED NULL,
			date DATE NULL,
			open_till DATE NULL,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
			discount_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			tag_ids JSON NULL,
			line_items JSON NULL,
			subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			adjustment DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			to_name VARCHAR(255) NULL,
			address TEXT NULL,
			city VARCHAR(100) NULL,
			state VARCHAR(100) NULL,
			country VARCHAR(100) NULL,
			zip VARCHAR(20) NULL,
			email VARCHAR(191) NULL,
			phone VARCHAR(50) NULL,
			allow_comments TINYINT(1) NOT NULL DEFAULT 1,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY proposal_number (proposal_number),
			UNIQUE KEY hash (hash),
			KEY idx_contact_status (contact_id, status),
			KEY idx_assigned_status (assigned_user_id, status),
			KEY idx_created (created_at)";
	}
}
