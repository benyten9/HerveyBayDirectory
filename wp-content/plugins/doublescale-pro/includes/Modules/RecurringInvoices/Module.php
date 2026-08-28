<?php
/**
 * Recurring Invoices module bootstrap.
 *
 * Pro child of the Free Sales module. Owns the local billing clock: a schedule
 * attached to a template invoice regenerates it on an interval, with an
 * optional cycle cap and end date.
 *
 * Distinct from the Subscriptions add-on, which mirrors Stripe-driven card
 * billing and records charges after the fact. This module drives customers who
 * pay manually (bank transfer, cash, wallet) — the "self-clock" path Stripe
 * cannot cover.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Container;
use DoubleScale\Modules\Sales\AbstractSalesChildModule;
use DoubleScale\Pro\Modules\RecurringInvoices\Abilities\RecurrenceAbilities;

final class Module extends AbstractSalesChildModule {

	/**
	 * Read-only recurring invoice abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return RecurrenceAbilities::definitions();
	}

	public function slug(): string {
		return 'recurring_invoices';
	}

	public function label(): string {
		return __( 'Recurring Invoices', 'doublescale' );
	}

	public function description(): string {
		return __( 'Automatically regenerate invoices on a schedule — for retainers, maintenance contracts, and any customer who pays manually each cycle.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	/**
	 * Ships in Pro and is not gated on the documents release flag (mirrors the
	 * Credit Notes child). Parent-Sales gating still applies via
	 * {@see AbstractSalesChildModule::is_enabled()}.
	 *
	 * @return bool
	 */
	protected function requires_documents_ready(): bool {
		return false;
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestInvoiceRecurrenceController::class,
		);
	}

	protected function boot_child( Container $container ): void {
		unset( $container );

		add_action( 'init', array( $this, 'register_schedules' ) );
		add_filter( 'doublescale_sales_invoice_admin_shape', array( $this, 'attach_recurrence' ), 10, 2 );

		// Deleting the template retires its rule; the invoices it already
		// produced are financial records and are deliberately left alone.
		add_action(
			'doublescale_sales_invoice_deleted',
			static function ( $invoice ) {
				if ( ! $invoice || ! isset( $invoice->id ) ) {
					return;
				}
				try {
					Models\InvoiceRecurrenceModel::where( 'template_invoice_id', (int) $invoice->id )->delete();
				} catch ( \Throwable $e ) {
					// Table missing or storage mid-migration — nothing to clean.
				}
			}
		);
	}

	/**
	 * Expose the rule (and the link back to the template) on invoice reads, so
	 * the form can rehydrate its recurrence controls.
	 *
	 * @param array<string, mixed> $data    Shaped invoice.
	 * @param mixed                $invoice Invoice model.
	 * @return array<string, mixed>
	 */
	public function attach_recurrence( array $data, $invoice ): array {
		if ( ! $invoice || ! isset( $invoice->id ) ) {
			return $data;
		}

		$data['recurrence_id'] = isset( $invoice->recurrence_id ) && $invoice->recurrence_id
			? (int) $invoice->recurrence_id
			: null;
		$data['recurrence']    = null;

		try {
			$recurrence = Models\InvoiceRecurrenceModel::where( 'template_invoice_id', (int) $invoice->id )->first();
			if ( $recurrence ) {
				$data['recurrence'] = Rest\InvoiceRecurrenceShaper::shape( $recurrence );
			}
		} catch ( \Throwable $e ) {
			// Table missing or storage mid-migration — leave the field null.
		}

		return $data;
	}

	/**
	 * Register the hourly sweep.
	 *
	 * Hourly rather than daily so a rule saved mid-day fires the same day, and
	 * so a `require_paid` rule picks up shortly after payment lands.
	 *
	 * @return void
	 */
	public function register_schedules(): void {
		$this->register_recurring_sales_task(
			'doublescale_sales_recurring_invoices',
			static function () {
				( new Services\RecurringInvoicesRunner() )->run();
			},
			HOUR_IN_SECONDS
		);
	}
}
