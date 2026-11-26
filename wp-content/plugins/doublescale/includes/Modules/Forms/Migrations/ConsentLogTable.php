<?php
/**
 * Consent log: what a contact agreed to, where, when, and the words they saw.
 *
 * Proof for GDPR, TCPA and A2P registration. Rows are never edited.
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * Consent log table.
 */
class ConsentLogTable extends Migration {

	/**
	 * Table name (prefixed with doublescale_ by the base class).
	 *
	 * @var string
	 */
	public $table_name = 'consent_log';

	/**
	 * Columns.
	 *
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) NOT NULL,
			channel VARCHAR(20) NOT NULL,
			granted TINYINT(1) NOT NULL DEFAULT 1,
			consent_text TEXT NULL,
			source_type VARCHAR(40) NOT NULL DEFAULT "form",
			source_id BIGINT(20) NULL,
			submission_id BIGINT(20) NULL,
			ip_hash VARCHAR(64) NULL,
			page_url VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY contact_id (contact_id)';
	}
}
