<?php
/**
 * Contracts table migration.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SalesContractsTable migration.
 */
class SalesContractsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_contracts';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_number VARCHAR(50) NOT NULL,
			hash VARCHAR(32) NOT NULL,
			subject VARCHAR(255) NOT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'draft',
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			assigned_user_id BIGINT(20) UNSIGNED NULL,
			contract_type_id BIGINT(20) UNSIGNED NULL,
			contract_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			start_date DATE NULL,
			end_date DATE NULL,
			description LONGTEXT NULL,
			tag_ids JSON NULL,
			hide_from_customer TINYINT(1) NOT NULL DEFAULT 0,
			is_trash TINYINT(1) NOT NULL DEFAULT 0,
			signed_name VARCHAR(255) NULL,
			signature TEXT NULL,
			signed_ip VARCHAR(45) NULL,
			signed_at DATETIME NULL,
			sent_at DATETIME NULL,
			viewed_at DATETIME NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY contract_number (contract_number),
			UNIQUE KEY hash (hash),
			KEY idx_contact_status (contact_id, status),
			KEY idx_assigned_status (assigned_user_id, status),
			KEY idx_contract_type (contract_type_id),
			KEY idx_created (created_at)";
	}
}
