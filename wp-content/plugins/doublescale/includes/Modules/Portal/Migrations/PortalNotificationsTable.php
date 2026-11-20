<?php
/**
 * The client portal's notifications: what a client is told in the portal's
 * bell, one row per thing, until they read it.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * PortalNotificationsTable.
 */
class PortalNotificationsTable extends Migration {

	/** Bumped when the columns change. */
	private const VERSION = '1';

	/** Option holding the version created on this site. */
	private const OPTION = 'doublescale_portal_notifications_table';

	/**
	 * @var string
	 */
	public $table_name = 'portal_notifications';

	/**
	 * Create the table once per version (cheap on every boot).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( self::VERSION === get_option( self::OPTION ) ) {
			return;
		}
		( new self() )->run();
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			category VARCHAR(30) NOT NULL DEFAULT "activity",
			type VARCHAR(40) NOT NULL,
			area VARCHAR(30) NOT NULL DEFAULT "",
			title VARCHAR(255) NOT NULL,
			body TEXT NULL,
			route VARCHAR(255) NOT NULL DEFAULT "",
			is_read TINYINT(1) NOT NULL DEFAULT 0,
			emailed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_contact_unread (contact_id, is_read, created_at),
			KEY idx_category (category, emailed_at)';
	}
}
