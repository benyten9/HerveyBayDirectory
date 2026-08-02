<?php
/**
 * Activities module bootstrap.
 *
 * Owns: activity models, associations, comments, migrations, timeline service,
 * and REST activity controller.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Activities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'activities';
	}

	public function label(): string {
		return __( 'Activities', 'doublescale' );
	}

	public function description(): string {
		return __( 'Activity timeline, notes, and interaction history across contacts and deals.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function register( Container $container ): void {
		$container->singleton(
			Services\ActivityManager::class,
			static fn() => Services\ActivityManager::instance()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestActivityController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		$container->get( Services\ActivityManager::class );

		// Weekly safety net for soft associations: removes rows that point at
		// entities deleted outside the model-event cleanup paths.
		Services\AssociationOrphanSweeper::boot();
	}
}
