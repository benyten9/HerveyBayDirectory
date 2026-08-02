<?php
/**
 * Guards automation catalog code from querying module tables before migrations run.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Support;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Services\RulesManager;

defined( 'ABSPATH' ) || exit;

final class AutomationModuleStorage {

	/**
	 * Whether a module is active and its representative table exists.
	 *
	 * @param string $module_slug Module slug (e.g. deals, support, forms).
	 * @param string $model_class Eloquent model class used to resolve the table name.
	 */
	public static function is_ready( string $module_slug, string $model_class ): bool {
		if ( function_exists( 'doublescale_is_module_storage_ready' ) ) {
			return doublescale_is_module_storage_ready( $module_slug, $model_class );
		}

		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( $module_slug ) ) {
			return false;
		}

		return self::table_exists( $model_class );
	}

	/**
	 * @param string $model_class Eloquent model class.
	 */
	public static function table_exists( string $model_class ): bool {
		if ( ! class_exists( $model_class ) ) {
			return false;
		}

		global $wpdb;
		$table = ( new $model_class() )->getTable();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Register an automation rule when its owning module is active.
	 *
	 * Catalog registration is separate from {@see is_ready()}: conditions should
	 * appear in the builder when the module is on even if migrations have not
	 * created tables yet. Runtime evaluation still gates on storage readiness.
	 *
	 * @param Rule   $rule        Rule instance.
	 * @param string $module_slug Module slug.
	 * @param string $model_class Representative model for the module tables.
	 */
	public static function register_rule( Rule $rule, string $module_slug, string $model_class ): void {
		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( $module_slug ) ) {
			return;
		}

		if ( ! class_exists( $model_class ) ) {
			return;
		}

		RulesManager::instance()->register( $rule );
	}
}
