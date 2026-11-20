<?php
/**
 * The workflow send queue table.
 *
 * @package DoubleScale\Pro\Modules\SendQueue\Migrations
 */

namespace DoubleScale\Pro\Modules\SendQueue\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SendQueueTable migration.
 */
class SendQueueTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'send_queue';

	/**
	 * One row per queued workflow message (email, SMS or WhatsApp). The
	 * message itself is the tracking row; this row only schedules it.
	 *
	 * status:       queued | sending | done | failed
	 * available_at: WordPress local time; not sent before this
	 * lease_until:  a dispatcher owns the row until then (crash-safe claim)
	 *
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR(20) NOT NULL,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 10,
            tracking_id BIGINT(20) UNSIGNED NOT NULL,
            automation_id BIGINT(20) UNSIGNED NOT NULL,
            step_id BIGINT(20) UNSIGNED NOT NULL,
            automation_contact_id BIGINT(20) UNSIGNED NOT NULL,
            contact_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "queued",
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            lease_token VARCHAR(40) NULL DEFAULT NULL,
            lease_until DATETIME NULL DEFAULT NULL,
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tracking_id (tracking_id),
            KEY due (channel, status, available_at, priority, id),
            KEY lease_token (lease_token),
            KEY automation_contact_id (automation_contact_id)';
	}
}
