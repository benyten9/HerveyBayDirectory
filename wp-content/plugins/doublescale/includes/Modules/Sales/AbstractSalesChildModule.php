<?php
/**
 * Shared behavior for Sales child sub-features (documents, contracts, pipelines).
 *
 * Effective state = Sales parent on AND (optional documents-ready gate) AND own
 * stored intent (missing key defaults to on, like the pipeline child).
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

/**
 * AbstractSalesChildModule class.
 */
abstract class AbstractSalesChildModule extends AbstractModule {

	/**
	 * @return array<int, string>
	 */
	public function dependencies(): array {
		return array( 'core', 'contacts', 'sales' );
	}

	public function is_toggleable(): bool {
		return true;
	}

	/**
	 * Child effective state = parent Sales on AND optional release gate AND own intent.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$stored = get_option( 'doublescale_enabled_modules', array() );
		$stored = is_array( $stored ) ? $stored : array();

		$intent = $this->child_stored_intent( $stored );
		$intent = (bool) apply_filters( 'doublescale_module_enabled_' . $this->slug(), $intent );
		if ( ! $intent ) {
			return false;
		}

		if ( $this->requires_documents_ready()
			&& function_exists( 'doublescale_sales_documents_ready' )
			&& ! doublescale_sales_documents_ready() ) {
			return false;
		}

		if ( ! function_exists( 'doublescale_is_module_active' ) ) {
			return true;
		}

		return doublescale_is_module_active( $this->sales_parent_slug() );
	}

	public function migrations(): array {
		if ( $this->requires_documents_ready()
			&& function_exists( 'doublescale_sales_documents_ready' )
			&& ! doublescale_sales_documents_ready() ) {
			return array();
		}

		return $this->schema_migration_files();
	}

	/**
	 * Migration files for this child, ignoring runtime enable gates.
	 * Used by schema repair when the ledger says "ran" but the table is missing.
	 *
	 * @return array<int, string>
	 */
	public function schema_migration_files(): array {
		$files = $this->child_migration_files();
		if ( array() !== $files ) {
			sort( $files );

			return $files;
		}

		return parent::migrations();
	}

	public function boot( Container $container ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		parent::boot( $container );
		$this->boot_child( $container );
	}

	/**
	 * Parent module slug that gates this child (defaults to Sales).
	 *
	 * @return string
	 */
	protected function sales_parent_slug(): string {
		return 'sales';
	}

	/**
	 * When true, {@see doublescale_sales_documents_ready()} must pass before boot/migrations.
	 *
	 * @return bool
	 */
	protected function requires_documents_ready(): bool {
		return true;
	}

	/**
	 * Stored toggle intent before the parent / release gates are applied.
	 *
	 * @param array<string, mixed> $stored Normalized `doublescale_enabled_modules` array.
	 * @return bool
	 */
	protected function child_stored_intent( array $stored ): bool {
		return ! array_key_exists( $this->slug(), $stored ) || (bool) $stored[ $this->slug() ];
	}

	/**
	 * Absolute paths to migration files owned by this child.
	 *
	 * Return an empty array to fall back to {@see AbstractModule::migrations()} glob.
	 *
	 * @return array<int, string>
	 */
	protected function child_migration_files(): array {
		return array();
	}

	/**
	 * Child-specific boot (frontend handlers, cron, menus, etc.).
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	protected function boot_child( Container $container ): void {
		unset( $container );
	}

	/**
	 * @param string $basename Migration filename without directory.
	 * @return string
	 */
	protected function child_migration_path( string $basename ): string {
		return $this->module_dir() . '/Migrations/' . $basename;
	}

	/**
	 * @deprecated Use child_migration_path().
	 * @param string $basename Migration filename without directory.
	 * @return string
	 */
	protected function sales_migration_path( string $basename ): string {
		return $this->child_migration_path( $basename );
	}

	/**
	 * Register a recurring Action Scheduler job under the sales group.
	 *
	 * The callback is attached on every request: `add_action()` only lives for the
	 * current request, and the Action Scheduler queue runner needs to find it on
	 * whichever request happens to run the queue. Only the scheduling half — a DB
	 * write that must not race between concurrent `init` fires — is behind the
	 * transient lock. Registering inside the lock would leave the hook callback-less
	 * on every request but the first in each lock window, and Action Scheduler would
	 * fail the action with "no callbacks are registered".
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $interval Interval in seconds.
	 * @return void
	 */
	protected function register_recurring_sales_task( string $hook, callable $callback, int $interval ): void {
		$tasks = new \DoubleScale\Core\Tasks( 'doublescale_sales' );
		$tasks->register_callback( $hook, $callback );

		$lock_key = 'doublescale_register_tasks_lock_' . $hook;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		if ( false === $tasks->get_next_timestamp( $hook ) ) {
			$tasks->schedule_recurring( time(), $interval, $hook );
		}
	}
}
