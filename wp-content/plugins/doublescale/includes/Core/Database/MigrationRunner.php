<?php
/**
 * Runs migration files registered by modules, tracking what has already run
 * in a dedicated `{prefix}doublescale_migrations` table.
 *
 * `Install::install()` delegates here: each enabled module’s `Migrations/*.php`
 * runs in dependency order; tracked in `{prefix}doublescale_migrations`.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\ModuleInterface;
use DoubleScale\Core\ModuleRegistry;

class MigrationRunner {

	private const TABLE = 'doublescale_migrations';

	public static function ensure_tracking_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			module VARCHAR(64) NOT NULL,
			migration VARCHAR(191) NOT NULL,
			ran_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY module_migration (module, migration(150))
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Post-dbDelta existence check.
		$created = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $created !== $table ) {
			$error = $wpdb->last_error ? $wpdb->last_error : 'unknown database error';
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Failed to create DoubleScale migrations tracking table',
					array(
						'table' => $table,
						'error' => $error,
					)
				);
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'DoubleScale: failed to create table %s (%s)', $table, $error ) );
			}
		}
	}

	/**
	 * Execute every module's pending migrations in dependency order.
	 */
	public static function run_all( ModuleRegistry $registry ): void {
		self::ensure_tracking_table();

		foreach ( $registry->all_sorted_by_dependencies() as $module ) {
			if ( ! $module->is_enabled() ) {
				continue;
			}

			foreach ( $module->migrations() as $file ) {
				self::run_one( $module->slug(), $file );
			}
		}
	}

	/**
	 * Run pending migrations for a single module (e.g. user toggled the module on).
	 *
	 * @param ModuleInterface $module Module whose migrations should run.
	 * @param bool            $force  Run even when {@see ModuleInterface::is_enabled()} is false.
	 *                                Used on explicit activation: a child module's derived state
	 *                                can be off (parent disabled) while the user just stored the
	 *                                intent to enable it — its schema must exist before the
	 *                                parent turns it on.
	 */
	public static function run_for_module( ModuleInterface $module, bool $force = false ): void {
		self::ensure_tracking_table();

		if ( ! $force && ! $module->is_enabled() ) {
			return;
		}

		foreach ( $module->migrations() as $file ) {
			self::run_one( $module->slug(), $file );
		}
	}

	private static function run_one( string $module_slug, string $file ): void {
		global $wpdb;

		$migration_name = basename( $file, '.php' );
		$table          = $wpdb->prefix . self::TABLE;

		$already = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE module = %s AND migration = %s",
				$module_slug,
				$migration_name
			)
		);
		if ( $already > 0 ) {
			return;
		}

		require_once $file;

		$class = self::class_from_file( $file );
		if ( ! $class || ! class_exists( $class ) ) {
			return;
		}

		$instance = new $class();
		if ( ! method_exists( $instance, 'run' ) ) {
			return;
		}

		// Detect a silent SQL failure inside the migration. Migration `run()`
		// methods return void and call `$wpdb->query()` directly (swallowing the
		// boolean result), so a failed ALTER/CREATE would otherwise still be
		// recorded as "ran" — making the partial failure permanent and invisible
		// (the migration never re-runs to finish the job). We snapshot the wpdb
		// error state, run, then refuse to record the migration if it errored so
		// the next install attempt retries it (every migration is idempotent).
		$migration_error = '';
		if ( property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}

		try {
			$instance->run();
			$migration_error = property_exists( $wpdb, 'last_error' ) ? (string) $wpdb->last_error : '';
		} catch ( \Throwable $e ) {
			$migration_error = $e->getMessage();
		}

		if ( '' !== $migration_error ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Migration failed; not recorded as ran (will retry next install).',
					array(
						'source'    => 'migration-runner',
						'module'    => $module_slug,
						'migration' => $migration_name,
						'error'     => $migration_error,
					)
				);
			}
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'module'    => $module_slug,
				'migration' => $migration_name,
				'ran_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Re-run migration files without consulting the ledger (idempotent migrations only).
	 *
	 * @param array<int, string> $files Absolute paths to migration PHP files.
	 * @return void
	 */
	public static function replay_files( array $files ): void {
		foreach ( $files as $file ) {
			self::execute_migration_file( $file );
		}
	}

	/**
	 * Re-create tables recorded by dbDelta migrations when the physical table is missing.
	 * Also replays companion ALTER migrations in module order.
	 *
	 * @param ModuleRegistry $registry Module registry.
	 * @return void
	 */
	public static function repair_missing_tables( ModuleRegistry $registry ): void {
		self::ensure_tracking_table();

		foreach ( $registry->all_sorted_by_dependencies() as $module ) {
			if ( $module->is_toggleable() && ! $module->is_enabled() ) {
				continue;
			}

			$files = self::schema_migration_files_for( $module );
			if ( empty( $files ) ) {
				continue;
			}

			$needs_replay = false;
			foreach ( $files as $file ) {
				$table = self::migration_table_name( $file );
				if ( null !== $table && ! self::table_exists( $table ) ) {
					$needs_replay = true;
					break;
				}
			}

			if ( $needs_replay ) {
				self::replay_files( $files );
			}
		}
	}

	/**
	 * @param ModuleInterface $module Module.
	 * @return array<int, string>
	 */
	private static function schema_migration_files_for( ModuleInterface $module ): array {
		if ( method_exists( $module, 'schema_migration_files' ) ) {
			$files = $module->schema_migration_files();
			return is_array( $files ) ? $files : array();
		}

		return $module->migrations();
	}

	/**
	 * @param string $table_name Fully qualified table name.
	 * @return bool
	 */
	private static function table_exists( string $table_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	/**
	 * @param string $file Migration file path.
	 * @return string|null Physical table name for Migration subclasses, else null.
	 */
	private static function migration_table_name( string $file ): ?string {
		if ( ! is_readable( $file ) ) {
			return null;
		}

		require_once $file;

		$class = self::class_from_file( $file );
		if ( ! $class || ! class_exists( $class ) || ! is_subclass_of( $class, Migration::class ) ) {
			return null;
		}

		$instance = new $class();
		return isset( $instance->table_name ) ? (string) $instance->table_name : null;
	}

	/**
	 * @param string $file Migration file path.
	 * @return void
	 */
	private static function execute_migration_file( string $file ): void {
		if ( ! is_readable( $file ) ) {
			return;
		}

		require_once $file;

		$class = self::class_from_file( $file );
		if ( ! $class || ! class_exists( $class ) ) {
			return;
		}

		$instance = new $class();
		if ( ! method_exists( $instance, 'run' ) ) {
			return;
		}

		global $wpdb;
		if ( property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}

		try {
			$instance->run();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Migration replay failed',
					array(
						'source' => 'migration-runner',
						'file'   => $file,
						'error'  => $e->getMessage(),
					)
				);
			}
		}
	}

	private static function class_from_file( string $file ): ?string {
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			return null;
		}
		if ( ! preg_match( '/^namespace\s+([^;]+);/m', $contents, $ns ) ) {
			return null;
		}
		if ( ! preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $cls ) ) {
			return null;
		}
		return trim( $ns[1] ) . '\\' . $cls[1];
	}
}
