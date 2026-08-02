<?php
/**
 * `doublescale_link_triggers` table migration.
 *
 * Idempotent: re-running on an install that previously created this table via
 * the free plugin is a no-op (the Migration base class skips CREATE-IF-EXISTS
 * collisions and the migrations tracking table will simply record a fresh row).
 *
 * @package DoubleScale\Pro\Modules\LinkTriggers\Migrations
 */

namespace DoubleScale\Pro\Modules\LinkTriggers\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class LinkTriggersTable extends Migration {

	public $table_name = 'link_triggers';

	public function get_query() {
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
            hash VARCHAR(191) NOT NULL,
            status VARCHAR(255) NOT NULL DEFAULT "inactive",
            settings TEXT,
			click_count BIGINT(20) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY hash (hash)';

		return $query;
	}
}
