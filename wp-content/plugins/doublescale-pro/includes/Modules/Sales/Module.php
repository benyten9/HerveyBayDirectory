<?php
/**
 * Sales Pro module bootstrap.
 *
 * Pro extensions for the free Sales module (online invoice payments).
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;
use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use DoubleScale\Pro\Modules\Sales\Approvals\Rest\Controllers\RestApprovalController;
use DoubleScale\Pro\Modules\Sales\Approvals\Services\ApprovalWorkflow;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\Loader;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\StripeInvoiceWebhookHandler;
use DoubleScale\Pro\Modules\Sales\Rest\Controllers\RestSureCartProductController;
use DoubleScale\Pro\Modules\Sales\Rest\Controllers\RestWooCommerceSettingsController;
use DoubleScale\Pro\Modules\Sales\Rest\Controllers\RestWooProductController;
use DoubleScale\Pro\Modules\Sales\Services\WhatsappDocumentSender;

/**
 * Sales Pro module.
 */
final class Module extends AbstractModule {

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'sales-pro';
	}

	/**
	 * Human-readable module label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Sales Pro', 'doublescale' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Pro extensions for Sales: online invoice payments (Stripe).', 'doublescale' );
	}

	/**
	 * Follows the free Sales parent toggle; not independently toggleable.
	 *
	 * @return bool
	 */
	public function is_toggleable(): bool {
		return false;
	}

	/**
	 * Module dependencies (booted before this module).
	 *
	 * @return array<int, string>
	 */
	public function dependencies(): array {
		return array( 'core', 'sales' );
	}

	/**
	 * @return array<int, string>
	 */
	public function migrations(): array {
		return array(
			$this->module_dir() . '/Approvals/Migrations/SalesApprovalsTable.php',
		);
	}

	/**
	 * @return array<int, string>
	 */
	public function restControllers(): array {
		return array(
			RestApprovalController::class,
			RestWooProductController::class,
			RestSureCartProductController::class,
			RestWooCommerceSettingsController::class,
		);
	}

	/**
	 * Whether the free Sales module class is loaded.
	 *
	 * @return bool
	 */
	private function free_sales_present(): bool {
		return class_exists( \DoubleScale\Modules\Sales\Module::class, false );
	}

	/**
	 * Wire the Stripe invoice gateway into free's Sales filter surface.
	 *
	 * @param Container $container DI container (unused).
	 * @return void
	 */
	public function register( Container $container ): void {
		unset( $container );
		if ( ! $this->free_sales_present() ) {
			return;
		}

		// WooCommerce picker config must ship even when the documents release
		// gate is closed — credit notes share the same line-items slot.
		add_filter( 'doublescale_admin_config', array( __CLASS__, 'inject_admin_config' ) );

		// Credit notes are not behind the documents gate either, so automatic
		// WhatsApp sending registers before the gate check below.
		WhatsappDocumentSender::register();

		// Invoice payments only exist once free's Sales documents feature is
		// released — see doublescale_sales_documents_ready() (free).
		if ( function_exists( 'doublescale_sales_documents_ready' ) && ! doublescale_sales_documents_ready() ) {
			return;
		}

		Loader::register();
		ApprovalWorkflow::register();

		// Eagerly instantiate so `doublescale_stripe_invoice_event` is bound
		// before the Stripe webhook REST handler fires (mirrors Booking Pro).
		StripeInvoiceWebhookHandler::instance();

		add_action(
			'rest_api_init',
			static function () {
				if ( ! class_exists( StripeInvoiceWebhookHandler::class ) ) {
					return;
				}
				try {
					StripeInvoiceWebhookHandler::instance();
				} catch ( \Throwable $e ) {
					doublescale_get_logger()->error(
						'Sales Stripe gateway REST primer failed',
						array(
							'source' => 'sales-pro-module',
							'error'  => $e->getMessage(),
						)
					);
				}
			},
			1
		);
	}

	/**
	 * Expose WooCommerce store currency for the line-item picker's mismatch warning.
	 * Availability itself is already on the payload as `isWoocommerceActive`.
	 *
	 * @param array<string, mixed> $config Admin config payload.
	 * @return array<string, mixed>
	 */
	public static function inject_admin_config( array $config ): array {
		$config['wooCurrency'] = function_exists( 'get_woocommerce_currency' )
			? (string) get_woocommerce_currency()
			: '';

		return $config;
	}

	/**
	 * @param Container $container DI container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		if ( ! $this->free_sales_present() ) {
			return;
		}

		// Always register REST controllers (approvals + WooCommerce products).
		// The WooCommerce picker is shared with credit notes, which are not
		// gated on the documents release flag — so parent::boot must run even
		// when invoices/proposals are still behind the gate.
		parent::boot( $container );

		if ( function_exists( 'doublescale_sales_documents_ready' ) && ! doublescale_sales_documents_ready() ) {
			return;
		}

		if ( ! (bool) SalesSettings::get( 'approval_workflow_enabled', false ) ) {
			return;
		}

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Approvals', 'doublescale' ),
				'menu_title'      => __( 'Approvals', 'doublescale' ),
				'capability'      => 'doublescale_approve_sales',
				'slug'            => 'doublescale&path=sales/approvals',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 43,
				'group'           => 'sales',
				'requires_module' => 'documents',
			)
		);
	}
}
