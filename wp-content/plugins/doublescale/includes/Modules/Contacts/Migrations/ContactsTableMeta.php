<?php
/**
 * Class ContactsTableMeta
 * This class is responsible for handling the contact meta table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * ContactsTableMeta class
 */
class ContactsTableMeta extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'contact_meta';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		$query = 'meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			meta_key VARCHAR(255),
			meta_value LONGTEXT,
			PRIMARY KEY (meta_id),
			KEY contact_id (contact_id),
			KEY meta_key (meta_key(191))';

		return $query;
	}
}
