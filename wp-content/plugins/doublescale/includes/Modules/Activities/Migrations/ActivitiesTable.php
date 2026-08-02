<?php

/**
 * Class ActivitiesTable
 * Unified table for all activity types (messages, notes, calls, meetings, system events)
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Activities\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * Activities Table class
 */
class ActivitiesTable extends Migration {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'activities';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Unified Activities Table Fields:
		 *
		 * id: BIGINT(20) NOT NULL AUTO_INCREMENT
		 * activity_type: VARCHAR(50) NOT NULL - Type of activity (email_sent, sms_sent, note_added, etc.)
		 * data: JSON NULL - All activity-specific data including subject/body
		 * user_id: BIGINT(20) UNSIGNED NULL - User who performed action
		 * activity_date: DATETIME NULL - When activity occurred (indexed for sorting/filtering)
		 * created_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		 * updated_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		 *
		 * Contact (and other parent) linking lives in doublescale_activity_associations.
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			activity_type VARCHAR(50) NOT NULL COMMENT "email_sent, sms_sent, note_added, call_logged, meeting_scheduled, created, deal_created, stage_changed, etc.",
			data JSON NULL COMMENT "Activity-specific data including subject, body, and other metadata",
			user_id BIGINT(20) UNSIGNED NULL COMMENT "User who performed action",
			activity_date DATETIME NULL COMMENT "When the activity occurred (called_at, sent_at, scheduled_at, or created_at)",
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY activity_type (activity_type),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY activity_date (activity_date)';

		return $query;
	}
}
