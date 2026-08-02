<?php
/**
 * Sales document approvals table migration.
 *
 * @package DoubleScale\Pro\Modules\Sales\Approvals
 */

namespace DoubleScale\Pro\Modules\Sales\Approvals\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SalesApprovalsTable migration.
 */
class SalesApprovalsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_approvals';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			document_type VARCHAR(20) NOT NULL,
			document_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			requested_by_user_id BIGINT(20) UNSIGNED NOT NULL,
			requested_at DATETIME NOT NULL,
			reviewed_by_user_id BIGINT(20) UNSIGNED NULL,
			reviewed_at DATETIME NULL,
			rejection_reason TEXT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY document_approval (document_type, document_id),
			KEY idx_status_requested (status, requested_at)";
	}
}
