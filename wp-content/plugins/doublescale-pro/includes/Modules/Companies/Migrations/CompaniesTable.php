<?php
/**
 * Companies: client organisations that contacts belong to.
 *
 * Projects, tickets, invoices and files can be set to a company so every
 * member with access sees them in the client portal.
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Pro\Modules\Companies\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * CompaniesTable class
 */
class CompaniesTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 */
	public $table_name = 'companies';

	/**
	 * Get query
	 *
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			domain VARCHAR(191) NULL,
			logo_id BIGINT(20) UNSIGNED NULL,
			email VARCHAR(191) NULL,
			phone VARCHAR(64) NULL,
			website VARCHAR(255) NULL,
			address LONGTEXT NULL,
			registration_number VARCHAR(191) NULL,
			tax_vat_number VARCHAR(191) NULL,
			owner_user_id BIGINT(20) UNSIGNED NULL,
			notes LONGTEXT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL,
			PRIMARY KEY (id),
			KEY name (name),
			KEY domain (domain)';
	}
}
