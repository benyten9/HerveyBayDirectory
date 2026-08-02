<?php
/**
 * Email send log table for the SMTP module.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\Migrations;

use DoubleScale\Core\Database\Migration;

defined( 'ABSPATH' ) || exit;

class SmtpEmailLogTable extends Migration {

	/**
	 * Logical table name (becomes {$wpdb->prefix}doublescale_smtp_email_log).
	 *
	 * @var string
	 */
	public $table_name = 'smtp_email_log';

	public function get_query() {
		return 'log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp datetime NOT NULL,
			subject varchar(255) NOT NULL,
			body longtext NOT NULL,
			headers longtext NOT NULL,
			attachments longtext NOT NULL,
			`from` varchar(255) NOT NULL,
			recipients longtext NOT NULL,
			status varchar(255) NOT NULL,
			provider varchar(225) NOT NULL,
			connection_id varchar(20) NOT NULL,
			account_id varchar(225) NOT NULL,
			initiator_name varchar(255) NOT NULL,
			initiator_slug varchar(255) NOT NULL,
			initiator_type varchar(20) NOT NULL,
			context longtext NOT NULL,
			response longtext NOT NULL,
			resend_count int(11) NOT NULL,
			PRIMARY KEY  (log_id),
			KEY status (status),
			KEY timestamp (timestamp)';
	}
}
