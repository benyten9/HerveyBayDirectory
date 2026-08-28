<?php
/**
 * Square hosted-checkout payment gateway for invoice context.
 *
 * Creates a Square Payment Link for the invoice balance and hands the customer
 * off to Square's hosted checkout page.
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
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Pro\Modules\Integrations\Square\Api;
use DoubleScale\Pro\Modules\Integrations\Square\Integration as SquareIntegration;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;
use WP_Error;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * SquareGateway class.
 */
class SquareGateway extends Gateway {

	public $name = 'Square';

	public $slug = 'square';

	public $description = 'Square hosted checkout — credentials in Integrations → Square.';

	/**
	 * Query arg appended to the invoice return URL after checkout.
	 */
	public const RETURN_QUERY_ARG = 'ds_square_return';

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
		return SquareIntegration::instance()->is_configured();
	}

	/**
	 * Square reports money in minor units.
	 *
	 * @return bool
	 */
	public function uses_major_units(): bool {
		return false;
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
			__( 'Square payment for invoice %s', 'doublescale' ),
			$invoice_number
		);
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'Square is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $subject instanceof InvoicePayableSubject ) {
			return new WP_Error(
				'invalid_subject',
				__( 'Square checkout is only available for invoices.', 'doublescale' ),
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

		try {
			$api = SquareIntegration::instance()->connect();
			if ( ! $api ) {
				return new WP_Error(
					'gateway_not_configured',
					__( 'Square is not configured.', 'doublescale' ),
					array( 'status' => 503 )
				);
			}

			$mismatch = $this->guard_location_currency( $api, $currency );
			if ( is_wp_error( $mismatch ) ) {
				return $mismatch;
			}

			$link = $this->resolve_or_create_link( $api, $subject, $amount, $currency );
			if ( is_wp_error( $link ) ) {
				return $link;
			}

			if ( ! empty( $link['already_paid'] ) ) {
				return $this->shape_already_paid_response( $subject, $amount, $currency );
			}

			return array(
				'gateway'      => $this->slug,
				'redirect_url' => (string) $link['url'],
				'amount'       => $amount,
				'currency'     => strtolower( $currency ),
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Square payment init failed',
				array(
					'code'    => 'square_payment_init_failed',
					'context' => $subject->context(),
					'message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'square_error', $e->getMessage(), array( 'status' => 500 ) );
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
				__( 'Square is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		$link_id = $subject->external_payment_ref();
		if ( null === $link_id || '' === $link_id ) {
			return new WP_Error(
				'invalid_data',
				__( 'No Square checkout is in progress.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		try {
			$api = SquareIntegration::instance()->connect();
			if ( ! $api ) {
				return new WP_Error(
					'gateway_not_configured',
					__( 'Square is not configured.', 'doublescale' ),
					array( 'status' => 503 )
				);
			}

			$payment = $this->find_completed_payment( $api, $link_id );
			$status  = null === $payment ? 'pending' : 'paid';

			if ( null !== $payment ) {
				$this->record_paid( $subject, $payment );
			}

			$response = array(
				'gateway' => $this->slug,
				'status'  => $status,
			);

			if ( $subject instanceof InvoicePayableSubject ) {
				$invoice = $subject->get_invoice();
				$invoice->refresh();
				$response['invoice'] = InvoiceShaper::shape( $invoice, true );
			}

			return $response;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'square_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @param object         $charge  Square payment object.
	 * @return void
	 */
	public function record_paid( PayableSubject $subject, object $charge ): void {
		$normalized = $this->normalize_payment( $charge );
		if ( ! $normalized ) {
			return;
		}
		$subject->record_payment( $normalized );
	}

	/**
	 * Square settles into a location's own currency; a mismatch would silently
	 * charge the wrong amount, so refuse up front.
	 *
	 * @param Api    $api      API client.
	 * @param string $currency Invoice currency (uppercase).
	 * @return true|WP_Error
	 */
	private function guard_location_currency( Api $api, string $currency ) {
		$locations = $api->list_locations();
		if ( ! $locations['success'] ) {
			// Non-fatal: let the payment attempt surface the real error.
			return true;
		}

		$location_id = $api->location_id();
		foreach ( (array) ( $locations['data']['locations'] ?? array() ) as $location ) {
			if ( ! is_array( $location ) || (string) ( $location['id'] ?? '' ) !== $location_id ) {
				continue;
			}

			$location_currency = strtoupper( (string) ( $location['currency'] ?? '' ) );
			if ( '' !== $location_currency && $location_currency !== $currency ) {
				return new WP_Error(
					'currency_mismatch',
					sprintf(
						/* translators: 1: invoice currency, 2: Square location currency */
						__( 'Invoice currency (%1$s) does not match the Square location currency (%2$s). Change the invoice currency to %2$s, or select a %1$s location in Integrations → Square.', 'doublescale' ),
						$currency,
						$location_currency
					),
					array( 'status' => 400 )
				);
			}
			break;
		}

		return true;
	}

	/**
	 * Reuse an unpaid link for this invoice when the amount still matches,
	 * otherwise create a fresh one.
	 *
	 * @param Api                   $api      API client.
	 * @param InvoicePayableSubject $subject  Subject.
	 * @param float                 $amount   Major units.
	 * @param string                $currency ISO currency (uppercase).
	 * @return array{url?:string,already_paid?:bool}|WP_Error
	 */
	private function resolve_or_create_link( Api $api, InvoicePayableSubject $subject, float $amount, string $currency ) {
		$ref = $subject->external_payment_ref();
		if ( null !== $ref && '' !== $ref ) {
			$existing = $api->get_payment_link( $ref );
			if ( $existing['success'] ) {
				$link = $existing['data']['payment_link'] ?? array();

				if ( null !== $this->find_completed_payment( $api, $ref ) ) {
					return array( 'already_paid' => true );
				}

				$expected = Api::to_minor_units( $amount, $currency );
				if ( $this->link_amount( $api, $link ) === $expected ) {
					$url = (string) ( $link['url'] ?? '' );
					if ( '' !== $url ) {
						return array( 'url' => $url );
					}
				}
			}
		}

		$created = $api->create_payment_link(
			$amount,
			$currency,
			$this->payment_metadata( $subject ),
			$this->idempotency_key( $subject, $amount, $currency )
		);

		if ( ! $created['success'] ) {
			return new WP_Error(
				'square_error',
				$created['message'] ?? __( 'Could not create the Square checkout link.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$link    = $created['data']['payment_link'] ?? array();
		$link_id = (string) ( $link['id'] ?? '' );
		$url     = (string) ( $link['url'] ?? '' );

		if ( '' === $link_id || '' === $url ) {
			return new WP_Error(
				'square_error',
				__( 'Square did not return a checkout link.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		// Non-Stripe refs must not populate stripe_payment_intent_id. The link id
		// is enough — the order is resolved from it whenever we need it.
		$subject->get_invoice()->set_in_progress_payment_ref( $link_id, false );

		return array( 'url' => $url );
	}

	/**
	 * Minor-unit total of an existing payment link, or null when unknown.
	 *
	 * @param Api   $api  API client.
	 * @param array $link Payment link payload.
	 * @return int|null
	 */
	private function link_amount( Api $api, array $link ): ?int {
		$order_id = (string) ( $link['order_id'] ?? '' );
		if ( '' === $order_id ) {
			return null;
		}

		$order = $api->get_order( $order_id );
		if ( ! $order['success'] ) {
			return null;
		}

		$total = $order['data']['order']['total_money']['amount'] ?? null;
		return null === $total ? null : (int) $total;
	}

	/**
	 * The completed payment behind a link, or null when still unpaid.
	 *
	 * @param Api    $api     API client.
	 * @param string $link_id Payment link id.
	 * @return object|null
	 */
	private function find_completed_payment( Api $api, string $link_id ) {
		$link = $api->get_payment_link( $link_id );
		if ( ! $link['success'] ) {
			return null;
		}

		$order_id = (string) ( $link['data']['payment_link']['order_id'] ?? '' );
		if ( '' === $order_id ) {
			return null;
		}

		$order = $api->get_order( $order_id );
		if ( ! $order['success'] ) {
			return null;
		}

		$order_data = $order['data']['order'] ?? array();
		foreach ( (array) ( $order_data['tenders'] ?? array() ) as $tender ) {
			if ( ! is_array( $tender ) ) {
				continue;
			}

			$payment_id = (string) ( $tender['payment_id'] ?? $tender['id'] ?? '' );
			if ( '' === $payment_id ) {
				continue;
			}

			$payment = $api->get_payment( $payment_id );
			if ( ! $payment['success'] ) {
				continue;
			}

			$data   = $payment['data']['payment'] ?? array();
			$status = strtoupper( (string) ( $data['status'] ?? '' ) );
			if ( 'COMPLETED' === $status ) {
				return (object) $data;
			}
		}

		return null;
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

		$return_url = $this->invoice_return_url( $subject );
		if ( '' !== $return_url ) {
			$metadata['return_url'] = $return_url;
		}

		return $metadata;
	}

	/**
	 * @param InvoicePayableSubject $subject Subject.
	 * @return string
	 */
	private function invoice_return_url( InvoicePayableSubject $subject ): string {
		$base = InvoiceUrl::get_public_url( $subject->get_invoice() );
		if ( '' === $base ) {
			return '';
		}
		return add_query_arg( self::RETURN_QUERY_ARG, '1', $base );
	}

	/**
	 * Stable per invoice+amount so a retried init cannot double-create a link.
	 *
	 * @param InvoicePayableSubject $subject  Subject.
	 * @param float                 $amount   Major units.
	 * @param string                $currency ISO currency.
	 * @return string
	 */
	private function idempotency_key( InvoicePayableSubject $subject, float $amount, string $currency ): string {
		return substr(
			md5(
				sprintf(
					'ds-square-%d-%d-%s',
					$subject->entity_id(),
					Api::to_minor_units( $amount, $currency ),
					$currency
				)
			),
			0,
			45
		);
	}

	/**
	 * @param object $payment Square payment or normalized object.
	 * @return object|null
	 */
	private function normalize_payment( object $payment ) {
		if ( isset( $payment->payment_mode ) && PaymentModeSlugs::square() === (string) $payment->payment_mode ) {
			$txn = (string) ( $payment->transaction_id ?? '' );
			return '' !== $txn ? $payment : null;
		}

		$payment_id = (string) ( $payment->id ?? '' );
		if ( '' === $payment_id ) {
			return null;
		}

		$amount_money = $payment->amount_money ?? null;
		if ( is_object( $amount_money ) ) {
			$amount   = (int) ( $amount_money->amount ?? 0 );
			$currency = strtolower( (string) ( $amount_money->currency ?? '' ) );
		} elseif ( is_array( $amount_money ) ) {
			$amount   = (int) ( $amount_money['amount'] ?? 0 );
			$currency = strtolower( (string) ( $amount_money['currency'] ?? '' ) );
		} else {
			return null;
		}

		return (object) array(
			'payment_mode'   => PaymentModeSlugs::square(),
			'transaction_id' => $payment_id,
			'id'             => $payment_id,
			// Minor units — uses_major_units() is false for this gateway.
			'amount'         => $amount,
			'currency'       => $currency,
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
