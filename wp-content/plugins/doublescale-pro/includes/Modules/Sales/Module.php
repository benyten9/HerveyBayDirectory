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
	 * @param Container $container DI container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		if ( ! $this->free_sales_present() ) {
			return;
		}

		if ( function_exists( 'doublescale_sales_documents_ready' ) && ! doublescale_sales_documents_ready() ) {
			return;
		}

		parent::boot( $container );

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
