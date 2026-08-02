<?php
/**
 * When the Analytics module is disabled, {@see ModuleRegistry} skips booting it, so REST routes
 * are not registered. The admin SPA still calls reporting endpoints; register those routes here.
 *
 * @package DoubleScale\Pro\Core
 */

namespace DoubleScale\Pro\Core;

defined( 'ABSPATH' ) || exit;

final class ProAnalyticsRestFallback {

	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				if ( ! class_exists( PluginKernel::class ) ) {
					return;
				}
				$kernel    = PluginKernel::instance();
				$registry  = $kernel->get_module_registry();
				$analytics = $registry->get( 'analytics' );
				if ( ! $analytics || $analytics->is_enabled() ) {
					return;
				}
				foreach ( $analytics->restControllers() as $class ) {
					( new $class() )->register_routes();
				}
			},
			5
		);
	}
}
