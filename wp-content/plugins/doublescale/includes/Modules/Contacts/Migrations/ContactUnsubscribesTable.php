<?php
/**
 * Class ContactUnsubscribesTable
 * Table for tracking contact unsubscribe events with source tracking
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * ContactUnsubscribesTable class
 */
class ContactUnsubscribesTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'contact_unsubscribes';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Contact Unsubscribes Table Fields:
		 *
		 * id: BIGINT(20) NOT NULL AUTO_INCREMENT
		 * contact_id: BIGINT(20) NOT NULL - FK to contacts
		 * mode: TINYINT(1) NOT NULL - Mode type (1=Email, 2=Sms, 3=Whatsapp)
		 * reason: TEXT NULL - Reason for unsubscribe
		 * source_type: TINYINT(2) NULL - Source type (1=Campaign, 2=Automation, 3=Individual)
		 * source_id: BIGINT(20) NULL - Campaign ID or Automation ID depending on source_type
		 * created_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) NOT NULL COMMENT "FK to contacts",
			mode TINYINT(1) NOT NULL COMMENT "1=Email, 2=Sms, 3=Whatsapp",
			reason TEXT NULL COMMENT "Reason for unsubscribe",
			source_type TINYINT(2) NULL COMMENT "1=Campaign, 2=Automation",
			source_id BIGINT(20) NULL COMMENT "Campaign ID or Automation ID depending on source_type",
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contact_id (contact_id),
			KEY mode (mode),
			KEY source_type (source_type),
			KEY source_id (source_id),
			KEY composite_source (source_type, source_id),
			KEY composite_contact_mode (contact_id, mode)';

		return $query;
	}
}
