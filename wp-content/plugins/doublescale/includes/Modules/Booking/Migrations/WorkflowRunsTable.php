<?php
/**
 * Workflow runs table.
 *
 * Execution log for booking lifecycle events dispatched through
 * {@see \DoubleScale\Modules\Booking\Services\EventBus}. Each row is a
 * single side-effect (notification, webhook, integration call) keyed by an
 * idempotency string so the same action cannot execute twice for the same
 * booking + event, even on retry. The 5-minute cron sweeper in EventBus
 * uses `status` and `next_retry_at` to find runs that need to be retried.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Migrations;

use DoubleScale\Core\Database\Migration;

defined( 'ABSPATH' ) || exit;

class WorkflowRunsTable extends Migration {

	public $table_name = 'booking_workflow_runs';

	public function get_query() {
		return 'id bigint(20) NOT NULL AUTO_INCREMENT,
		booking_id int(11) NOT NULL,
		event_name varchar(100) NOT NULL,
		action_name varchar(100) NOT NULL,
		idempotency_key varchar(255) NOT NULL,
		status varchar(30) NOT NULL DEFAULT \'pending\',
		attempts int(11) NOT NULL DEFAULT 0,
		max_attempts int(11) NOT NULL DEFAULT 3,
		payload longtext,
		result longtext,
		error_message text,
		started_at datetime NULL,
		completed_at datetime NULL,
		next_retry_at datetime NULL,
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY uq_idempotency (idempotency_key(191)),
		KEY idx_booking_event (booking_id, event_name),
		KEY idx_status (status),
		KEY idx_retry (status, next_retry_at)';
	}
}
