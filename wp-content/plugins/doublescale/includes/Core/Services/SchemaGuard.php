<?php
/**
 * Boot-time repair for missing table columns.
 *
 * {@see \DoubleScale\Core\Database\Migration::ensure_columns()} already knows
 * how to add every column a CREATE definition declares, and is idempotent. But
 * it only runs inside `Migration::run()`, which the migration ledger invokes
 * exactly once per site. If that single run happened while an ALTER silently
 * failed — an `AFTER <later column>` clause, a dbDelta that half-applied, a
 * multisite subsite created out of order — the ledger still advances and the
 * column is missing *permanently*. Every write touching it then fails, and
 * because the Eloquent wrapper does not always throw on a `wpdb` error the
 * failure surfaces as a fatal further downstream rather than as a clear error.
 *
 * That is not hypothetical: an audit of this network found seven such cases,
 * including nine columns missing from one site's proposals table (signature,
 * response and viewed_at fields), which silently breaks signing and accepting
 * a proposal.
 *
 * This guard re-runs that reconciliation once per request, so a site always
 * recovers on the next page load instead of needing a manual ALTER.
 *
 * Cost: one `SHOW COLUMNS` per table, once per request, and only for modules
 * that are enabled. It never drops or rewrites an existing column — it only
 * adds what the schema declares and the table lacks.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
use DoubleScale\Core\PluginKernel;

/**
 * SchemaGuard.
 */
final class SchemaGuard {

	/**
	 * Per-site option recording the plugin version this site's schema was last
	 * reconciled for. Per-site (not network) so each subsite heals on its own
	 * first load after an upgrade.
	 */
	public const STAMP_OPTION = 'doublescale_schema_reconciled_version';

	/**
	 * Whether reconciliation was already attempted this request.
	 *
	 * @var bool
	 */
	private static $has_run = false;

	/**
	 * Migration files already reconciled, keyed by path, so modules sharing a
	 * migration directory do not re-read the same table.
	 *
	 * @var array<string, bool>
	 */
	private static $seen = array();

	/**
	 * Reconcile every enabled module's tables against their declared schema.
	 *
	 * Safe to call from any module boot: the first call does the work and every
	 * later call in the same request is a no-op.
	 *
	 * @param bool $force Reconcile even when this version is already stamped.
	 *                    Used by the repair tooling; boot passes false.
	 * @return void
	 */
	public static function reconcile( bool $force = false ): void {
		if ( self::$has_run ) {
			return;
		}

		// Set the flag first. A failure midway must not leave the guard armed to
		// retry on every subsequent module boot in the same request.
		self::$has_run = true;

		/**
		 * Filter whether the boot-time schema reconciliation runs.
		 *
		 * Lets a site with very large tables opt out and repair manually.
		 *
		 * @param bool $enabled Whether to reconcile. Default true.
		 */
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'doublescale_reconcile_schema_on_boot', true ) ) {
			return;
		}

		// Reconciling every table costs roughly half a second (one SHOW COLUMNS
		// per table plus parsing each CREATE definition), which is far too much
		// to spend on every page load. Do it only when the schema could have
		// changed — a new plugin version — or when a caller forces it. The
		// stamp is per-site, so each subsite in a network heals on its own
		// first load after an upgrade.
		if ( ! $force && ! self::needs_reconcile() ) {
			return;
		}

		foreach ( self::migration_files() as $file ) {
			self::reconcile_file( $file );
		}

		self::stamp_reconciled();
	}

	/**
	 * Whether this site's schema has been reconciled for the running version.
	 *
	 * @return bool
	 */
	private static function needs_reconcile(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$version = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '0';

		return (string) get_option( self::STAMP_OPTION, '' ) !== (string) $version;
	}

	/**
	 * Record that this site is reconciled for the running version.
	 *
	 * @return void
	 */
	private static function stamp_reconciled(): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$version = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '0';

		// Not autoloaded: it is read once per request at most, and only until
		// the stamp matches.
		update_option( self::STAMP_OPTION, (string) $version, false );
	}

	/**
	 * Whether reconciliation has been attempted this request.
	 *
	 * @return bool
	 */
	public static function has_run(): bool {
		return self::$has_run;
	}

	/**
	 * Reset the once-per-request state. Test-only seam.
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$has_run = false;
		self::$seen    = array();
	}

	/**
	 * Every migration file belonging to an enabled, registered module.
	 *
	 * @return array<int, string>
	 */
	private static function migration_files(): array {
		$files = array();

		if ( ! class_exists( PluginKernel::class ) ) {
			return $files;
		}

		try {
			$kernel = PluginKernel::instance();
			if ( ! is_object( $kernel ) || ! method_exists( $kernel, 'get_module_registry' ) ) {
				return $files;
			}

			$registry = $kernel->get_module_registry();
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'all' ) ) {
				return $files;
			}

			foreach ( $registry->all() as $module ) {
				if ( ! is_object( $module ) || ! method_exists( $module, 'migrations' ) ) {
					continue;
				}

				// Only reconcile modules that are actually on: a disabled
				// module's tables are not being written to.
				if ( method_exists( $module, 'is_enabled' ) && ! $module->is_enabled() ) {
					continue;
				}

				foreach ( (array) $module->migrations() as $file ) {
					if ( is_string( $file ) && '' !== $file ) {
						$files[] = $file;
					}
				}
			}
		} catch ( \Throwable $e ) {
			// A registry that cannot be read is not worth a fatal on every page.
			self::log_failure( 'module registry', $e );
		}

		return $files;
	}

	/**
	 * Re-run one migration's column reconciliation.
	 *
	 * @param string $file Absolute path to the migration file.
	 * @return void
	 */
	private static function reconcile_file( string $file ): void {
		if ( isset( self::$seen[ $file ] ) ) {
			return;
		}
		self::$seen[ $file ] = true;

		try {
			$class = self::class_for_file( $file );
			if ( '' === $class || ! class_exists( $class ) ) {
				return;
			}

			$reflection = new \ReflectionClass( $class );
			if ( $reflection->isAbstract() || ! $reflection->isSubclassOf( Migration::class ) ) {
				return;
			}

			$constructor = $reflection->getConstructor();
			if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
				return;
			}

			$migration = $reflection->newInstance();
			if ( method_exists( $migration, 'ensure_columns' ) ) {
				// Idempotent: adds only what the CREATE definition declares and
				// the physical table lacks. Never drops or rewrites a column.
				$migration->ensure_columns();
			}
		} catch ( \Throwable $e ) {
			// One broken migration must not take down the whole admin.
			self::log_failure( $file, $e );
		}
	}

	/**
	 * Resolve a migration file to its fully qualified class name by reading its
	 * namespace and class declarations, so the guard never hardcodes a
	 * per-module map.
	 *
	 * @param string $file Absolute path to the migration file.
	 * @return string Empty when the file declares no class.
	 */
	private static function class_for_file( string $file ): string {
		if ( ! is_readable( $file ) ) {
			return '';
		}

		$source = file_get_contents( $file );
		if ( false === $source ) {
			return '';
		}

		if ( ! preg_match( '/^\s*namespace\s+([^;]+);/m', $source, $ns ) ) {
			return '';
		}
		if ( ! preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/m', $source, $cls ) ) {
			return '';
		}

		return trim( $ns[1] ) . '\\' . trim( $cls[1] );
	}

	/**
	 * @param string     $context What was being reconciled.
	 * @param \Throwable $e       Failure.
	 * @return void
	 */
	private static function log_failure( string $context, \Throwable $e ): void {
		if ( function_exists( 'doublescale_get_logger' ) ) {
			doublescale_get_logger()->error(
				'Schema reconciliation failed',
				array(
					'source'  => 'schema-guard',
					'context' => $context,
					'error'   => $e->getMessage(),
				)
			);
		}
	}
}
