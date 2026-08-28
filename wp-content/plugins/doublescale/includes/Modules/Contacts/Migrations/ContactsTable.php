<?php
/**
 * Class ContactsTable
 * This class is responsible for handling the Contacts table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * Contacts Table class
 */
class ContactsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'contacts';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		$query = 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			hash_id VARCHAR(191) NOT NULL,
			email VARCHAR(191) NULL,
			first_name VARCHAR(255),
			last_name VARCHAR(255),
			phone VARCHAR(255),
			whatsapp_phone VARCHAR(255),
			address_1 VARCHAR(255),
			address_2 VARCHAR(255),
			city VARCHAR(100),
			state VARCHAR(100),
			country VARCHAR(100),
			zip VARCHAR(150),
			email_status VARCHAR(50) NOT NULL DEFAULT "subscribed",
			sms_status VARCHAR(50) NOT NULL DEFAULT "subscribed",
			whatsapp_status VARCHAR(50) NOT NULL DEFAULT "subscribed",
			source VARCHAR(255),
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY email (email),
			UNIQUE KEY phone (phone(191)),
			UNIQUE KEY whatsapp_phone (whatsapp_phone(191)),
			KEY email_status (email_status),
			KEY sms_status (sms_status),
			KEY whatsapp_status (whatsapp_status)';

		return $query;
	}
}
