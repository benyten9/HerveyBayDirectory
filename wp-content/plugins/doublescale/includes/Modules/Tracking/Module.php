<?php
/**
 * Tracking module bootstrap.
 *
 * Owns: email/SMS/WhatsApp tracking, IMAP client helper, and related
 * communication-tracking models/migrations. Link triggers were moved to the
 * Pro add-on (DoubleScale\Pro\Modules\LinkTriggers); website/page-visit
 * tracking is in the Website Tracking module.
 *
 * @package DoubleScale\Modules\Tracking
 */

namespace DoubleScale\Modules\Tracking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Abilities\ProvidesAbilities;
use DoubleScale\Modules\Tracking\Abilities\TrackingAbilities;
use DoubleScale\Core\Container;

final class Module extends AbstractModule implements ProvidesAbilities {

	/**
	 * Read-only abilities for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return TrackingAbilities::definitions();
	}

	public function slug(): string {
		return 'tracking';
	}

	public function label(): string {
		return __( 'Tracking', 'doublescale' );
	}

	public function description(): string {
		return __( 'Email, SMS, and channel tracking.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function register( Container $container ): void {
		$container->singleton(
			Email::class,
			static fn() => Email::instance()
		);

		$container->singleton(
			Whatsapp::class,
			static fn() => Whatsapp::instance()
		);

		$container->singleton( Services\TrackingService::class );
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		$container->get( Email::class );
		$container->get( Whatsapp::class );
	}
}
