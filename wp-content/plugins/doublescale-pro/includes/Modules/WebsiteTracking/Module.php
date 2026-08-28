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
		return __( 'Website tracking', 'doublescale' );
	}

	public function description(): string {
		return __( 'Page visits, visitor cookies, and anonymous visit stitching.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
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
	}
}
