<?php
/**
 * Base class for schema migrations (dbDelta).
 *
 * @package DoubleScale\Core\Database
 */

namespace DoubleScale\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Migration class
 */
abstract class Migration {

	/**
	 * Logical table name (without wp_ prefix or doublescale_ prefix); prefixed in constructor.
	 *
	 * @var string
	 */
	public $table_name;

	/**
	 * Constructor — sets full table name including $wpdb->prefix and doublescale_.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'doublescale_' . strtolower( $this->table_name );
	}

	/**
	 * Column definitions for dbDelta (inside parentheses).
	 */
	abstract public function get_query();

	/**
	 * Run the migration.
	 */
	public function run() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// If the physical table already exists, skip dbDelta. Re-applying the same
		// CREATE-shaped statement can make MySQL try to ADD PRIMARY KEY again and
		// error with "Multiple primary key defined" when the migrations ledger was
		// cleared or never recorded but the table survived (common in test DB resets).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-existence check before dbDelta; caching would mask the real DDL state we need to read.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) );
		if ( $exists === $this->table_name ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$query = $this->get_query();
		$sql   = "CREATE TABLE $this->table_name ( $query ) $charset_collate;";

		dbDelta( $sql );

		// dbDelta can fail silently (e.g. "Specified key was too long" on utf8mb4
		// hosts with the 1000-byte index limit). Surface that so installs aren't
		// left missing tables while the migrations ledger still advances.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Post-dbDelta existence check; must read live DDL state.
		$created = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) );
		if ( $created !== $this->table_name ) {
			$error = $wpdb->last_error ? $wpdb->last_error : 'unknown database error';
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Failed to create DoubleScale table',
					array(
						'table' => $this->table_name,
						'error' => $error,
					)
				);
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when logger is unavailable during early install.
				error_log( sprintf( 'DoubleScale: failed to create table %s (%s)', $this->table_name, $error ) );
			}
		}
	}
}
