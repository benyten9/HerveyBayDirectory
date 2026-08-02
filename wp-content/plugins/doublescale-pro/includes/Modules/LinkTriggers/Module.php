<?php
/**
 * Link Triggers module — definitions, REST API, redirect handler (Pro).
 *
 * Owns the front-end tracked-link redirect, the CRUD REST controller, and the
 * `doublescale_link_triggers` DB table. Free no longer ships any of this; when
 * Pro is absent the Settings tab renders a `ProFeatureNotice` and the REST
 * routes simply do not register.
 *
 * @package DoubleScale\Pro\Modules\LinkTriggers
 */

namespace DoubleScale\Pro\Modules\LinkTriggers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'link-triggers';
	}

	public function label(): string {
		return __( 'Link Triggers', 'doublescale' );
	}

	public function description(): string {
		return __( 'Trackable links with click counting, automation triggers, and tag/list sync on click.', 'doublescale' );
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function dependencies(): array {
		// Tracking provides CommunicationTrackingModel which LinkTriggers reads
		// to mark emails opened/clicked; Contacts provides the contact returned
		// by that model. Boot order matters: both must be ready before our
		// front-end handler is wired up via `doublescale_ready`.
		return array( 'core', 'tracking', 'contacts' );
	}

	public function migrations(): array {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return array();
		}
		return array(
			DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/LinkTriggers/Migrations/LinkTriggersTable.php',
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestLinkTriggerController::class,
		);
	}

	public function register( Container $container ): void {
		$container->singleton(
			LinkTriggers::class,
			static fn() => LinkTriggers::instance()
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		$container->get( LinkTriggers::class );
	}
}
