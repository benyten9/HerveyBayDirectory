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

	/**
	 * Email templates are shared: campaigns, automation emails and WhatsApp
	 * steps and the email renderer all read them, so their table belongs to
	 * this always-on module (the migration file stays with Campaigns, which
	 * created it first; running it again on an existing table only adds
	 * missing columns).
	 *
	 * @return string[]
	 */
	public function migrations(): array {
		$file = dirname( __DIR__ ) . '/Campaigns/Migrations/TemplatesTable.php';
		return is_file( $file ) ? array( $file ) : array();
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		EmailBuilder::instance();
	}
}
