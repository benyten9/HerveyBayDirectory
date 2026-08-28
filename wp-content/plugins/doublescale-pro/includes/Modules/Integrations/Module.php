<?php
/**
 * Integrations module bootstrap.
 *
 * Owns: third-party CRM and messaging integrations (per-vendor folders). Each
 * vendor ships Integration.php plus Api/RemoteData/RestController as needed.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Core\Managers\IntegrationsManager;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'integrations';
	}

	public function label(): string {
		return __( 'Integrations', 'doublescale' );
	}

	public function description(): string {
		return __( 'Third-party integrations for Twilio, Slack, Meta WhatsApp, and more.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function restControllers(): array {
		return array(
			Rest\RestIntegrationController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		// Order → contact sync is contact data, not a Sales feature, so it lives on
		// this always-on module. Booting it from Sales meant disabling the Sales
		// module silently stopped WooCommerce contact syncing.
		WooCommerce\Settings::instance();

		if ( class_exists( Gohighlevel\GohighlevelOauth::class ) ) {
			Gohighlevel\GohighlevelOauth::init();
		}

		$this->load_pro_integration_files();

		$this->register_message_providers();
	}

	/**
	 * Require vendor Integration.php files from the Pro plugin.
	 *
	 * {@see AbstractModule::loadManifestOrGlobs()} resolves globs against
	 * {@see DOUBLESCALE_PLUGIN_DIR} (free), so CRM integrations that live only
	 * under Pro would never load. Each Integration.php self-registers on include.
	 *
	 * @return void
	 */
	private function load_pro_integration_files(): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}

		$pattern = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Integrations/*/Integration.php';
		$files   = glob( $pattern ) ?: array();
		sort( $files, SORT_STRING );

		foreach ( $files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				require_once $file;
			}
		}
	}

	private function register_message_providers(): void {
		$manager = IntegrationsManager::instance();

		$manager->register( new Twilio\Integration(), true );
		$manager->register( Stripe\Integration::instance(), true );
		$manager->register( PayPal\Integration::instance(), true );
		$manager->register( Square\Integration::instance(), true );
		$manager->register( Mollie\Integration::instance(), true );
		$manager->register( Razorpay\Integration::instance(), true );
		$manager->register( AuthorizeNet\Integration::instance(), true );
		$manager->register( new Slack\Integration(), true );
		$manager->register( new MetaWhatsapp\Integration() );

		$registry = \DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry::instance();
		$registry->register( new \DoubleScale\Pro\Modules\Inbox\MessageProviders\TwilioMessageProvider() );
		$registry->register( new \DoubleScale\Pro\Modules\Inbox\MessageProviders\MetaWhatsappProvider() );

		do_action( 'doublescale_register_message_providers' );
	}
}
