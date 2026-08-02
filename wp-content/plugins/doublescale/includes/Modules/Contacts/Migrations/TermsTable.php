<?php
/**
 * Unified lists + tags storage (discriminator column `type`).
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * Terms Table class — replaces separate doublescale_lists / doublescale_tags rows logically.
 */
class TermsTable extends Migration {

	/**
	 * Table name (Migration prefixes with doublescale_).
	 *
	 * @var string
	 */
	public $table_name = 'terms';

	/**
	 * Schema
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL COMMENT \'list or tag\',
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			description TEXT,
			status VARCHAR(255) NOT NULL DEFAULT "active",
			is_public TINYINT(1) NOT NULL DEFAULT 1,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY type_slug (type, slug),
			KEY type (type),
			KEY slug (slug)';

		return $query;
	}
}
