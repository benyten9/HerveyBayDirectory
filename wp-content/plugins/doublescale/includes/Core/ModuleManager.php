<?php
/**
 * Unified module facade (merged free + Pro registries when Pro is active).
 * Delegates active checks to {@see doublescale_is_module_active()}.
 *
 * Lives in the free plugin so the base CRM owns the API; Pro layers registries and tasks.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

use DoubleScale\Core\Database\MigrationRunner;

defined( 'ABSPATH' ) || exit;

final class ModuleManager {

	/**
	 * Built-in Action Scheduler pairs used when a module does not declare its own
	 * via {@see ModuleInterface::scheduledHooks()}.
	 *
	 * @var array<string, array<int, array{0: string, 1: string}>>
	 */
	private const TASK_HOOKS_BY_MODULE = array(
		'campaigns'       => array(
			array( 'doublescale_campaigns', 'doublescale_email_campaigns' ),
			array( 'doublescale_campaigns', 'doublescale_whatsapp_campaigns' ),
		),
		'websitetracking' => array(
			array( 'doublescale_daily', 'doublescale_cleanup_page_visits' ),
		),
	);

	/** @var bool */
	private static $hooks_registered = false;

	/**
	 * Register global hooks (free Bootstrap calls once; safe if called again).
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		$sync = static function ( array $old, array $new ): void {
			self::flushCache(); // Lifecycle hooks must evaluate module state against the NEW option value.
			try {
				self::sync_lifecycle_from_option_delta( $old, $new );
			} catch ( \Throwable $e ) {
				// The option is already persisted by update_option before this hook runs.
				// Never let activation/deactivation fatals turn a successful save into a
				// failed REST response (UI shows an error and the user "saves twice").
				if ( function_exists( 'doublescale_get_logger' ) ) {
					doublescale_get_logger()->error(
						'Module lifecycle sync failed after doublescale_enabled_modules update',
						array(
							'source' => 'module-manager',
							'error'  => $e->getMessage(),
							'file'   => $e->getFile(),
							'line'   => $e->getLine(),
						)
					);
				}
			}
			self::flushCache(); // Drop anything cached mid-sync.
		};

		add_action(
			'update_option_doublescale_enabled_modules',
			static function ( $old, $value ) use ( $sync ): void {
				$sync(
					is_array( $old ) ? $old : array(),
					is_array( $value ) ? $value : array()
				);
			},
			10,
			2
		);

		add_action(
			'add_option_doublescale_enabled_modules',
			static function ( $_option, $value ) use ( $sync ): void {
				$sync(
					array(),
					is_array( $value ) ? $value : array()
				);
			},
			10,
			2
		);
	}

	/**
	 * @param array<string, bool|string|int> $old_value Previous option value.
	 * @param array<string, bool|string|int> $new_value New option value.
	 */
	public static function sync_lifecycle_from_option_delta( array $old_value, array $new_value ): void {
		$slugs = array_unique( array_merge( array_keys( $old_value ), array_keys( $new_value ) ) );
		foreach ( $slugs as $slug ) {
			$slug          = (string) $slug;
			$missing_state = ! self::isToggleable( $slug ) || self::is_enabled_by_default( $slug );
			$was_on        = isset( $old_value[ $slug ] ) ? (bool) $old_value[ $slug ] : $missing_state;
			$now_on        = isset( $new_value[ $slug ] ) ? (bool) $new_value[ $slug ] : $missing_state;
			if ( $was_on && ! $now_on ) {
				try {
					self::deactivateModule( $slug );
				} catch ( \Throwable $e ) {
					if ( function_exists( 'doublescale_get_logger' ) ) {
						doublescale_get_logger()->error(
							'Module deactivation failed',
							array(
								'source' => 'module-manager',
								'module' => $slug,
								'error'  => $e->getMessage(),
							)
						);
					}
				}
			} elseif ( ! $was_on && $now_on ) {
				try {
					self::activateModule( $slug );
				} catch ( \Throwable $e ) {
					if ( function_exists( 'doublescale_get_logger' ) ) {
						doublescale_get_logger()->error(
							'Module activation failed',
							array(
								'source' => 'module-manager',
								'module' => $slug,
								'error'  => $e->getMessage(),
							)
						);
					}
				}
			}
		}
	}

	/**
	 * Whether a missing option key means "on" for a toggleable module. Child
	 * modules follow their parent by default, so their stored intent defaults
	 * to enabled until the user opts out.
	 */
	public static function is_enabled_by_default( string $slug ): bool {
		return function_exists( 'doublescale_child_module_parent_map' )
			&& array_key_exists( $slug, doublescale_child_module_parent_map() );
	}

	public static function activateModule( string $slug ): void {
		$module = self::getModule( $slug );
		if ( ! $module instanceof ModuleInterface ) {
			return;
		}
		// Force: explicit activation is an unambiguous signal. A child module's
		// derived is_enabled() can be false while its parent is off, but the
		// stored intent just flipped on — its schema must exist before the
		// parent activates it.
		MigrationRunner::run_for_module( $module, true );
		$module->onActivate();

		// Dependent modules can become effective when this module turns on
		// without their own option key changing (child toggles default to the
		// parent's state) — make sure their schema exists too. Idempotent via
		// the doublescale_migrations tracking table.
		foreach ( self::all() as $dep_slug => $dep_module ) {
			if ( $dep_slug === $slug ) {
				continue;
			}
			if ( ! in_array( $slug, $dep_module->dependencies(), true ) ) {
				continue;
			}
			if ( ! $dep_module->is_enabled() ) {
				continue;
			}
			MigrationRunner::run_for_module( $dep_module );
		}
	}

	public static function deactivateModule( string $slug ): void {
		$module = self::getModule( $slug );
		if ( ! $module instanceof ModuleInterface ) {
			return;
		}
		$module->onDeactivate();
		self::clearScheduledTasksForModule( $slug );
	}

	public static function isEnabled( string $slug ): bool {
		return doublescale_is_module_active( $slug );
	}

	/**
	 * @since 1.0.0
	 */
	public static function isActive( string $slug ): bool {
		return doublescale_is_module_active( $slug );
	}

	public static function isToggleable( string $slug ): bool {
		$m = self::getModule( $slug );
		if ( $m ) {
			return $m->is_toggleable();
		}
		return function_exists( 'doublescale_is_phantom_module_toggle_slug' )
			&& doublescale_is_phantom_module_toggle_slug( $slug );
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T|null
	 */
	public static function whenEnabled( string $slug, callable $callback ) {
		if ( ! self::isEnabled( $slug ) ) {
			return null;
		}
		return $callback();
	}

	/**
	 * @return ModuleInterface|null
	 */
	public static function getModule( string $slug ): ?ModuleInterface {
		if ( ! class_exists( \DoubleScale\Core\PluginKernel::class, false ) ) {
			return null;
		}
		$m = \DoubleScale\Core\PluginKernel::instance()->get_module_registry()->get( $slug );

		return $m instanceof ModuleInterface ? $m : null;
	}

	/**
	 * Merged module map; Pro modules share the free kernel registry after discovery.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public static function all(): array {
		if ( ! class_exists( \DoubleScale\Core\PluginKernel::class, false ) ) {
			return array();
		}

		return \DoubleScale\Core\PluginKernel::instance()->get_module_registry()->all();
	}

	public static function flushCache(): void {
		if ( function_exists( 'doublescale_flush_module_enabled_cache' ) ) {
			doublescale_flush_module_enabled_cache();
		}
	}

	/**
	 * Clears Action Scheduler hooks owned by a module when it is disabled.
	 *
	 * Uses the free {@see \DoubleScale\Core\Tasks} wrapper so free-owned
	 * modules (e.g. Sales' overdue-invoices schedule) are cleared even when
	 * the Pro add-on is not installed.
	 */
	public static function clearScheduledTasksForModule( string $slug ): void {
		if ( ! class_exists( \DoubleScale\Core\Tasks::class ) ) {
			return;
		}

		$pairs = array();
		$mod   = self::getModule( $slug );
		if ( $mod instanceof ModuleInterface ) {
			$pairs = $mod->scheduledHooks();
		}
		if ( array() === $pairs && isset( self::TASK_HOOKS_BY_MODULE[ $slug ] ) ) {
			$pairs = self::TASK_HOOKS_BY_MODULE[ $slug ];
		}
		if ( array() === $pairs ) {
			return;
		}
		foreach ( $pairs as $pair ) {
			if ( ! isset( $pair[0], $pair[1] ) ) {
				continue;
			}
			( new \DoubleScale\Core\Tasks( (string) $pair[0] ) )->unschedule_all( (string) $pair[1] );
		}
	}
}
