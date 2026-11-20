<?php
/**
 * Saved replies: the team's ready answers for tickets (SupportCandy's
 * "Canned Reply", Intercom's macros).
 *
 * `user_id` is who saved it; `shared` = the whole team sees it (only those
 * who may see every ticket set that). `use_count` orders the list, most used
 * first. `content` is HTML (wp_kses_post) with {placeholders}.
 *
 * NOTE: keep every inline COMMENT comma- and semicolon-free (dbDelta).
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SupportSavedRepliesTable migration.
 */
class SupportSavedRepliesTable extends Migration {

	/** Bumped when the column list changes. */
	public const VERSION = '1';

	private const READY_OPTION = 'doublescale_saved_replies_schema';

	/**
	 * Logical table name; prefixed in the base constructor.
	 *
	 * @var string
	 */
	public $table_name = 'support_saved_replies';

	/**
	 * Column list.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			content LONGTEXT NOT NULL,
			user_id BIGINT(20) UNSIGNED NULL COMMENT 'who saved it',
			shared TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the whole team sees it',
			use_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_user (user_id),
			KEY idx_shared (shared)";
	}

	/**
	 * The table, also where an update skipped the migrations (one autoloaded
	 * option read once it exists).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( self::VERSION === get_option( self::READY_OPTION ) ) {
			return;
		}
		global $wpdb;
		$migration = new self();
		$table     = (string) $migration->table_name;
		$migration->run();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			update_option( self::READY_OPTION, self::VERSION, true );
		}
	}
}
