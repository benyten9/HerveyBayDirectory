<?php
/**
 * Website tracking module bootstrap.
 *
 * Owns page-visit storage, migrations, public tracking script hooks, and REST.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\WebsiteTracking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Tracking\Services\TrackingService;
use DoubleScale\Pro\Modules\WebsiteTracking\Abilities\PageVisitAbilities;

final class Module extends AbstractModule {

	/**
	 * Read-only page-visit abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return PageVisitAbilities::definitions();
	}

	public function slug(): string {
		return 'websitetracking';
	}

	public function label(): string {
		return __( 'Website Tracking', 'doublescale' );
	}

	public function description(): string {
		return __( 'See which pages your contacts visit on your website, on their timeline and in automations.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	/**
	 * The module switch decides; a site that never saved it keeps the choice
	 * it made in the old Settings → Website Tracking toggle (on by default).
	 * Nothing is recorded while it is off.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) apply_filters( 'doublescale_module_enabled_' . $this->slug(), self::switched_on() );
	}

	/**
	 * Saved module switch, else the old settings toggle. Free's
	 * Settings::is_website_tracking_enabled() uses the same rule; this copy
	 * keeps it for Free releases that predate it.
	 *
	 * @return bool
	 */
	public static function switched_on(): bool {
		$stored = get_option( 'doublescale_enabled_modules', array() );
		if ( is_array( $stored ) && array_key_exists( 'websitetracking', $stored ) ) {
			return (bool) $stored['websitetracking'];
		}

		$settings = get_option( 'doublescale_settings', array() );
		$raw      = is_array( $settings ) && isset( $settings['website_tracking']['enabled'] ) ? $settings['website_tracking']['enabled'] : true;
		$on       = filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return null === $on ? (bool) $raw : $on;
	}

	public function dependencies(): array {
		return array( 'core', 'contacts', 'campaigns' );
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function scheduledHooks(): array {
		return array(
			array( 'doublescale_daily', 'doublescale_cleanup_page_visits' ),
		);
	}

	public function register( Container $container ): void {
		if ( ! $container->has( TrackingService::class ) ) {
			$container->singleton( TrackingService::class );
		}

		$container->singleton(
			Website::class,
			static fn() => Website::instance()
		);
	}

	public function restControllers(): array {
		return array(
			\DoubleScale\Pro\Modules\WebsiteTracking\Rest\Controllers\RestPageVisitController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		Website::instance();

		// Visits stored with the site's folder twice (before 16fc55ea) get
		// their path back, once per site, in the background.
		Services\VisitPathRepair::register();
		if ( function_exists( 'doublescale_ensure_once' ) ) {
			doublescale_ensure_once( 'pro_page_visit_paths', '1', array( Services\VisitPathRepair::class, 'queue' ) );
		}
	}
}
