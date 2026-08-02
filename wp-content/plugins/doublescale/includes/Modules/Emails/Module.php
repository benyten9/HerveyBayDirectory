<?php
/**
 * Emails module bootstrap.
 *
 * Owns: email builder, renderer, block registry, bulk/curl-multi mailers,
 * bulkmailer adapters, layout handlers, email templates.
 *
 * Shared infrastructure used by Campaigns, Automations, Booking, Inbox,
 * Notifications, etc. — not campaign-specific.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'emails';
	}

	public function label(): string {
		return __( 'Emails', 'doublescale' );
	}

	public function description(): string {
		return __( 'Email builder, renderer, and delivery infrastructure shared across modules.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function dependencies(): array {
		return array( 'core' );
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		EmailBuilder::instance();
	}
}
