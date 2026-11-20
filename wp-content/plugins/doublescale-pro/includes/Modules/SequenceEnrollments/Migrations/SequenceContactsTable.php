<?php
/**
 * The sequence enrolments table.
 *
 * @package DoubleScale\Pro\Modules\SequenceEnrollments\Migrations
 */

namespace DoubleScale\Pro\Modules\SequenceEnrollments\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SequenceContactsTable migration.
 */
class SequenceContactsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sequence_contacts';

	/**
	 * One row per contact per sequence (the parent sequence, type 5).
	 *
	 * status:      active | exited
	 * enrolled_at: WordPress local time, like the rest of the sequence pipeline
	 *
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sequence_id BIGINT(20) UNSIGNED NOT NULL,
            contact_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "active",
            enrolled_at DATETIME NOT NULL,
            exited_at DATETIME NULL DEFAULT NULL,
            exit_reason VARCHAR(50) NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sequence_contact (sequence_id, contact_id),
            KEY sequence_status_enrolled (sequence_id, status, enrolled_at),
            KEY contact_id (contact_id)';
	}
}
