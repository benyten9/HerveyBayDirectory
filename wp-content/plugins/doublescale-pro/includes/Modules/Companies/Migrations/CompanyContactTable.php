<?php
/**
 * Company members: which contacts belong to which company.
 *
 * `role` is primary, billing or member. What a member sees in the portal
 * follows their own portal access, not this table. A contact can be in
 * several companies; `is_main` marks their main one (none marked: the
 * oldest membership).
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Pro\Modules\Companies\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * CompanyContactTable class
 */
class CompanyContactTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 */
	public $table_name = 'company_contact';

	/**
	 * Get query
	 *
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT(20) UNSIGNED NOT NULL,
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT "member",
			is_main TINYINT(1) NOT NULL DEFAULT 0,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY company_contact (company_id, contact_id),
			KEY contact_id (contact_id)';
	}
}
