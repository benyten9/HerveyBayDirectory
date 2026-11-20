<?php
/**
 * Client Portal payment-history endpoint.
 *
 *   GET /doublescale/v1/portal/payments
 *
 * Lists every payment recorded against the logged-in customer's own non-draft
 * invoices — a consolidated "what I've paid" history for the Documents →
 * Payments tab. Reuses {@see PortalIdentity} for the login + lowercased-email
 * contact resolve and scopes strictly to the contact's own invoices. Agent
 * identity (`recorded_by_user_id`) and internal notes are never exposed.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Services\CurrencyResolver;
use DoubleScale\Modules\Portal\Services\PortalAccess;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalPaymentsController.
 */
class RestPortalPaymentsController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Max payment rows returned.
	 */
	private const LIST_CAP = 200;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_payments' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * List the contact's payments across all their non-draft invoices.
	 *
	 * @param WP_REST_Request $request Request (unused; no query params).
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_payments( WP_REST_Request $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature; no query params to read.
		$gate = $this->documents_gate();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_REST_Response(
				array(
					'data'       => array(),
					'total_paid' => 0,
					'currency'   => '',
				),
				200
			);
		}

		// Load the contact's own non-draft invoices with their payments so each
		// payment row carries its invoice's currency/number/hash without a second
		// query, and the whole set is naturally contact-scoped.
		$invoices = \DoubleScale\Modules\Portal\Services\PortalScope::apply( InvoiceModel::query(), (int) $contact->id, 'invoices' )
			->where( 'status', '!=', InvoiceStatus::DRAFT )
			->with( 'payments' )
			->get();

		$rows        = array();
		$by_currency = array();
		foreach ( $invoices as $invoice ) {
			$currency = CurrencyResolver::resolve( $invoice );
			foreach ( $invoice->payments as $payment ) {
				$rows[] = $this->shape_payment( $payment, $invoice );
				// A credit note applied to the invoice is listed but is not
				// money the client paid (the statement counts it as a credit).
				if ( PaymentMode::CREDIT_NOTE === (string) $payment->payment_mode ) {
					continue;
				}
				$by_currency[ $currency ] = ( $by_currency[ $currency ] ?? 0.0 ) + (float) $payment->amount;
			}
		}

		// Newest payment first; break ties on id so re-ordering is stable.
		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = strcmp( (string) $b['payment_date'], (string) $a['payment_date'] );
				return 0 !== $cmp ? $cmp : ( (int) $b['id'] <=> (int) $a['id'] );
			}
		);
		$rows = array_slice( $rows, 0, self::LIST_CAP );

		// Each currency's total, biggest first: never summed across currencies
		// (documents freeze theirs). `total_paid` / `currency` keep the biggest
		// for older pages.
		arsort( $by_currency );
		$totals = array();
		foreach ( $by_currency as $code => $sum ) {
			$totals[] = array(
				'currency' => (string) $code,
				'amount'   => round( (float) $sum, 2 ),
			);
		}
		$currency = array() !== $totals ? $totals[0]['currency'] : '';
		$total    = array() !== $totals ? $totals[0]['amount'] : 0.0;

		// The total is a financial summary; the payments themselves stay listed.
		if ( ! PortalAccess::allows( 'financial_summaries', $contact ) ) {
			$total  = null;
			$totals = null;
		}

		return new WP_REST_Response(
			array(
				'data'       => array_values( $rows ),
				'total_paid' => $total,
				'currency'   => $currency,
				'totals'     => $totals,
			),
			200
		);
	}

	/**
	 * Documents-surface gate (404 when Sales/release-gated off), mirroring
	 * {@see RestPortalDocumentsController}.
	 *
	 * @return WP_Error|null
	 */
	private function documents_gate(): ?WP_Error {
		// Payments are invoice data: Documents must be on with its tables.
		if ( ! doublescale_sales_documents_ready() || ! doublescale_module_data_ready( 'documents' ) ) {
			return new WP_Error(
				'module_disabled',
				__( 'The Documents area is unavailable.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}
		return null;
	}

	/**
	 * Customer-safe payment row.
	 *
	 * @param PaymentModel $payment Payment.
	 * @param InvoiceModel $invoice Owning invoice (already loaded).
	 * @return array<string, mixed>
	 */
	private function shape_payment( PaymentModel $payment, InvoiceModel $invoice ): array {
		return array(
			'id'                 => (int) $payment->id,
			'amount'             => (float) $payment->amount,
			'currency'           => CurrencyResolver::resolve( $invoice ),
			'payment_mode'       => (string) $payment->payment_mode,
			'payment_date'       => $payment->payment_date,
			'transaction_id'     => (string) $payment->transaction_id,
			'invoice_id'         => (int) $invoice->id,
			'invoice_number'     => (string) $invoice->invoice_number,
			'invoice_hash'       => (string) $invoice->hash,
			'invoice_public_url' => InvoiceUrl::get_public_url( $invoice ),
		);
	}
}
