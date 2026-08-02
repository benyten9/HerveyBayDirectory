<?php
/**
 * Class TasksTable
 * This class is responsible for handling the tasks table migration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;


use DoubleScale\Core\Database\Migration;
/**
 * TasksTable class
 */
class TasksTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'tasks';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Task Table Fields (Following Plugin Polymorphic Pattern):
		 *
		 * CORE FIELDS:
		 * id PRIMARY KEY
		 * title VARCHAR(255) NOT NULL - Task title
		 * description TEXT - Message/notes field from form
		 *
		 * POLYMORPHIC ASSOCIATION (following Activity_Associations pattern):
		 * entity_type TINYINT UNSIGNED NOT NULL - 1=Contact, 2=Deal, 3=Project (Pro)
		 * entity_id BIGINT(20) UNSIGNED NOT NULL - FK to contacts or deals table
		 *
		 * USER ASSIGNMENT:
		 * assigned_to BIGINT(20) UNSIGNED NOT NULL - User who should complete task
		 *
		 * CLASSIFICATION:
		 * task_type VARCHAR(50) NOT NULL DEFAULT "todo" - call, email, meeting, todo, follow_up
		 * status VARCHAR(20) NOT NULL DEFAULT "pending" - Database status: pending, completed
		 *   NOTE: Display statuses (overdue, upcoming, due_today) are calculated dynamically
		 * priority VARCHAR(20) NOT NULL DEFAULT "medium" - low, medium, high
		 *
		 * SCHEDULING:
		 * due_date DATE NOT NULL - When task is due (form has date picker)
		 * due_time TIME - Optional specific time (form has time input)
		 *
		 * REMINDERS:
		 * reminder_at DATETIME - When to send reminder email notification (date + time)
		 * reminder_sent_at DATETIME - When reminder was actually sent
		 *
		 * COMPLETION:
		 * completed_at DATETIME - When marked complete
		 *
		 * AUDIT:
		 * created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		 * updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		 *
		 * Entity Types:
		 * 1 = Contact (default, always available)
		 * 2 = Deal (Pro feature, requires Plugin Pro)
		 */
		$query = 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			description TEXT DEFAULT NULL,
			entity_type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT \'1=Contact, 2=Deal, 3=Project\',
			entity_id BIGINT(20) UNSIGNED NOT NULL,
			assigned_to BIGINT(20) UNSIGNED NOT NULL,
			task_type VARCHAR(50) NOT NULL DEFAULT \'todo\',
			status VARCHAR(20) NOT NULL DEFAULT \'pending\' COMMENT \'DB status: pending/completed - display status calculated from due_date\',
			priority VARCHAR(20) NOT NULL DEFAULT \'medium\',
			due_date DATE NOT NULL,
			due_time TIME DEFAULT NULL,
			reminder_at DATETIME DEFAULT NULL COMMENT \'When to send reminder (date + time)\',
			reminder_sent_at DATETIME DEFAULT NULL COMMENT \'When reminder was sent\',
			completed_at DATETIME DEFAULT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY entity_type (entity_type),
			KEY entity_id (entity_id),
			KEY assigned_to (assigned_to),
			KEY status (status),
			KEY due_date (due_date),
			INDEX idx_polymorphic_entity (entity_type, entity_id),
			INDEX idx_status_due (status, due_date),
			INDEX idx_assigned_status (assigned_to, status),
			INDEX idx_entity_status (entity_type, entity_id, status),
			INDEX idx_reminder_pending (reminder_at, reminder_sent_at, status)';

		return $query;
	}
}
