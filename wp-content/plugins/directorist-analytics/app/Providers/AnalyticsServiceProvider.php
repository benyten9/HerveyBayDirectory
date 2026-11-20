<?php
/**
 * Analytics Service Provider
 *
 * Registers analytics tracking hooks.
 *
 * @package DirectoristAnalytics
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Providers;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Hooks\Analytics;
use DirectoristAnalytics\App\Hooks\Cron;
use DirectoristAnalytics\WpMVC\Contracts\Provider;

/**
 * Analytics Service Provider
 *
 * @since 1.0.0
 */
class AnalyticsServiceProvider implements Provider {

	/**
	 * Bootstrap any application services.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function boot() {
		// Only initialize if Directorist core is active
		if ( class_exists( 'Directorist_Base' ) ) {
			new Analytics();
			( new Cron() )->register_hooks();
		}
	}
}