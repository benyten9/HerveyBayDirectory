<?php
/**
 * Authorize.Net Accept Hosted payment gateway for invoice context.
 *
 * Accept Hosted is used rather than Accept.js so card data never touches this
 * site (PCI SAQ A) and the flow rides the shared redirect path. The token must
 * be POSTed rather than followed as a GET, so the redirect goes via
 * {@see HostedFormRedirect}, which performs the POST.
 *
 * @package DoubleScale\Pro\Modules\Pro\Payment
 */

namespace DoubleScale\Pro\Modules\Pro\Payment;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\Gateway;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Core\Payment\PayableSubject;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Pro\Modules\Integrations\AuthorizeNet\Api;
use DoubleScale\Pro\Modules\Integrations\AuthorizeNet\HostedFormRedirect;
use DoubleScale\Pro\Modules\Integrations\AuthorizeNet\Integration as AuthorizeNetIntegration;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;
use WP_Error;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * AuthorizeNetGateway class.
 */
class AuthorizeNetGateway extends Gateway {

	public $name = 'Authorize.Net';

	public $slug = 'authorize_net';

	public $description = 'Authorize.Net Accept Hosted card checkout — credentials in Integrations → Authorize.Net.';

	/**
	 * Query arg appended to the invoice return URL after checkout.
	 */
	public const RETURN_QUERY_ARG = 'ds_authorize_net_return';

	/**
	 * Transaction statuses that mean the money is captured or will settle.
	 */
	private const PAID_STATUSES = array(
		'capturedpendingsettlement',
		'settledsuccessfully',
		'authorizedpendingcapture',
	);

	/**
	 * @return void
	 */
	protected function register(): void {
		GatewayManager::instance()->register( GatewayManager::CONTEXT_INVOICE, $this );
	}

	public function is_available(): bool {
		return true;
	}

	public function is_configured(): bool {
		return AuthorizeNetIntegration::instance()->is_configured();
	}

	/**
	 * Authorize.Net reports amounts as decimal strings in major units.
	 *
	 * @return bool
	 */
	public function uses_major_units(): bool {
		return true;
	}

	/**
	 * @return string
	 */
	public function return_query_arg(): string {
		return self::RETURN_QUERY_ARG;
	}

	/**
	 * @param string $invoice_number Invoice number.
	 * @return string
	 */
	public function payment_note( string $invoice_number ): string {
		return sprintf(
			/* translators: %s: invoice number */
			__( 'Authorize.Net payment for invoice %s', 'doublescale' ),
			$invoice_number
		);
	}

	/**
	 * @param string $status Transaction status.
	 * @return bool
	 */
	public static function is_paid_status( string $status ): bool {
		return in_array( strtolower( $status ), self::PAID_STATUSES, true );
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'Authorize.Net is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $subject instanceof InvoicePayableSubject ) {
			return new WP_Error(
				'invalid_subject',
				__( 'Authorize.Net checkout is only available for invoices.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$amount   = $subject->amount_due();
		$currency = strtoupper( $subject->currency() );

		if ( $amount <= 0 ) {
			return new WP_Error(
				'nothing_due',
				__( 'This invoice has no balance due.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$account_currency = strtoupper(
			(string) ( AuthorizeNetIntegration::instance()->get_settings()['account_currency'] ?? \DoubleScale\Core\Settings\Settings::get_currency() )
		);
		$mismatch         = PaymentCurrency::guard( $currency, $account_currency, 'Authorize.Net' );
		if ( is_wp_error( $mismatch ) ) {
			return $mismatch;
		}

		try {
			$api = AuthorizeNetIntegration::instance()->connect();
			if ( ! $api instanceof Api ) {
				return new WP_Error(
					'gateway_not_configured',
					__( 'Authorize.Net is not configured.', 'doublescale' ),
					array( 'status' => 503 )
				);
			}

			// The invoice number is the only link back to the charge, so a
			// payment may already have completed on a previous attempt.
			$settled = $this->find_settled_transaction( $api, $subject );
			if ( $settled ) {
				$this->record_paid( $subject, $settled );
				return $this->shape_already_paid_response( $subject, $amount, $currency );
			}

			$return_url = $this->invoice_return_url( $subject );
			if ( '' === $return_url ) {
				return new WP_Error(
					'authorize_net_error',
					__( 'This invoice cannot start checkout because it has no public link.', 'doublescale' ),
					array( 'status' => 500 )
				);
			}

			$result = $api->get_hosted_payment_page(
				$amount,
				$this->payment_metadata( $subject ),
				$return_url,
				$return_url
			);

			if ( ! $result['success'] ) {
				return new WP_Error(
					'authorize_net_error',
					$result['message'] ?? __( 'Could not start the Authorize.Net checkout.', 'doublescale' ),
					array( 'status' => 500 )
				);
			}

			// Track the invoice number we searched on, so confirm() can find
			// the resulting transaction.
			$subject->get_invoice()->set_in_progress_payment_ref(
				$this->invoice_reference( $subject ),
				false
			);

			$handoff = HostedFormRedirect::store(
				(string) $result['token'],
				$api->hosted_form_url(),
				$subject->entity_id()
			);

			return array(
				'gateway'      => $this->slug,
				'redirect_url' => HostedFormRedirect::url( $handoff ),
				'amount'       => $amount,
				'currency'     => strtolower( $currency ),
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Authorize.Net payment init failed',
				array(
					'code'    => 'authorize_net_payment_init_failed',
					'context' => $subject->context(),
					'message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'authorize_net_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function confirm( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'Authorize.Net is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $subject instanceof InvoicePayableSubject ) {
			return new WP_Error(
				'invalid_subject',
				__( 'Authorize.Net checkout is only available for invoices.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		try {
			$api = AuthorizeNetIntegration::instance()->connect();
			if ( ! $api instanceof Api ) {
				return new WP_Error(
					'gateway_not_configured',
					__( 'Authorize.Net is not configured.', 'doublescale' ),
					array( 'status' => 503 )
				);
			}

			$transaction = $this->find_settled_transaction( $api, $subject );
			if ( $transaction ) {
				$this->record_paid( $subject, $transaction );
			}

			$response = array(
				'gateway' => $this->slug,
				'status'  => $transaction ? 'paid' : 'pending',
			);

			$invoice = $subject->get_invoice();
			$invoice->refresh();
			$response['invoice'] = InvoiceShaper::shape( $invoice, true );

			return $response;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'authorize_net_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @param object         $charge  Authorize.Net transaction object.
	 * @return void
	 */
	public function record_paid( PayableSubject $subject, object $charge ): void {
		$normalized = $this->normalize_transaction( $charge );
		if ( ! $normalized ) {
			return;
		}
		$subject->record_payment( $normalized );
	}

	/**
	 * Find a captured transaction for this invoice.
	 *
	 * Accept Hosted hands nothing back on return, so the invoice number is the
	 * only link between the invoice and the charge.
	 *
	 * @param Api                   $api     API client.
	 * @param InvoicePayableSubject $subject Subject.
	 * @return object|null
	 */
	private function find_settled_transaction( Api $api, InvoicePayableSubject $subject ) {
		$reference = $this->invoice_reference( $subject );
		if ( '' === $reference ) {
			return null;
		}

		$list = $api->get_transaction_list_for_invoice( $reference );
		if ( ! $list['success'] ) {
			return null;
		}

		$transactions = $list['data']['transactions'] ?? array();
		if ( ! is_array( $transactions ) ) {
			return null;
		}

		foreach ( $transactions as $summary ) {
			if ( ! is_array( $summary ) ) {
				continue;
			}

			$transaction_id = (string) ( $summary['transId'] ?? '' );
			if ( '' === $transaction_id ) {
				continue;
			}

			$details = $api->get_transaction_details( $transaction_id );
			if ( ! $details['success'] ) {
				continue;
			}

			$transaction = $details['data']['transaction'] ?? array();
			$status      = (string) ( $transaction['transactionStatus'] ?? '' );

			if ( self::is_paid_status( $status ) ) {
				return (object) $transaction;
			}
		}

		return null;
	}

	/**
	 * The invoice number as sent to Authorize.Net (capped at 20 chars).
	 *
	 * @param InvoicePayableSubject $subject Subject.
	 * @return string
	 */
	private function invoice_reference( InvoicePayableSubject $subject ): string {
		$number = (string) $subject->get_invoice()->invoice_number;
		return '' === $number ? '' : substr( $number, 0, 20 );
	}

	/**
	 * @param InvoicePayableSubject $subject Subject.
	 * @return array<string, mixed>
	 */
	private function payment_metadata( InvoicePayableSubject $subject ): array {
		$metadata = $subject->metadata();

		$email = $subject->customer_email();
		if ( $email ) {
			$metadata['customer_email'] = $email;
		}

		return $metadata;
	}

	/**
	 * @param InvoicePayableSubject $subject Subject.
	 * @return string
	 */
	private function invoice_return_url( InvoicePayableSubject $subject ): string {
		$hash = trim( (string) $subject->get_invoice()->hash );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			return '';
		}

		return HostedFormRedirect::return_url( $hash );
	}

	/**
	 * @param object $transaction Authorize.Net transaction or normalized object.
	 * @return object|null
	 */
	private function normalize_transaction( object $transaction ) {
		if ( isset( $transaction->payment_mode )
			&& PaymentModeSlugs::authorize_net() === (string) $transaction->payment_mode ) {
			$txn = (string) ( $transaction->transaction_id ?? '' );
			return '' !== $txn ? $transaction : null;
		}

		$transaction_id = (string) ( $transaction->transId ?? $transaction->id ?? '' );
		if ( '' === $transaction_id ) {
			return null;
		}

		$amount = $transaction->settleAmount ?? $transaction->authAmount ?? $transaction->amount ?? 0;

		return (object) array(
			'payment_mode'   => PaymentModeSlugs::authorize_net(),
			'transaction_id' => $transaction_id,
			'id'             => $transaction_id,
			// Major units — uses_major_units() is true for this gateway.
			'amount'         => (float) $amount,
			'currency'       => strtolower( (string) ( $transaction->currency ?? '' ) ),
		);
	}

	/**
	 * @param PayableSubject $subject  Subject.
	 * @param float          $amount   Major units.
	 * @param string         $currency ISO currency.
	 * @return array<string, mixed>
	 */
	private function shape_already_paid_response( PayableSubject $subject, float $amount, string $currency ): array {
		$response = array(
			'gateway'      => $this->slug,
			'already_paid' => true,
			'amount'       => $amount,
			'currency'     => strtolower( $currency ),
		);

		if ( $subject instanceof InvoicePayableSubject ) {
			$invoice = $subject->get_invoice();
			$invoice->refresh();
			$response['invoice'] = InvoiceShaper::shape( $invoice, true );
		}

		return $response;
	}
}
