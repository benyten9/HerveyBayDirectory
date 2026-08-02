<?php
/**
 * Class TemplatesTable
 * This class is responsible for handling the template table migration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * TemplatesTable class
 */
class TemplatesTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'templates';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Fields:
		 *
		 * id BIGINT(20) NOT NULL AUTO_INCREMENT,
		 * name VARCHAR(255) NOT NULL,
		 * type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=Email, 2=Sms, 3=Whatsapp',
		 * subject VARCHAR(255) NOT NULL,
		 * body TEXT,
		 * settings TEXT,
		 * hidden TINYINT(1) NOT NULL DEFAULT 1,
		 *thumbnail VARCHAR(255),
		 * category VARCHAR(100) DEFAULT 'general',
		 * is_pro TINYINT(1) NOT NULL DEFAULT 0,
		 * created_by BIGINT(20),
		 * created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		 * updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT "1=Email, 2=Sms, 3=Whatsapp",
            subject VARCHAR(255) NOT NULL,
            body LONGTEXT,
            settings LONGTEXT,
            hidden TINYINT(1) NOT NULL DEFAULT 1,
            thumbnail VARCHAR(255),
            category VARCHAR(100) DEFAULT "general",
            is_pro TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_type (type)';

		return $query;
	}
}
