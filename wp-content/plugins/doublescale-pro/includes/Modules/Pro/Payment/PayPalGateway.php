<?php
/**
 * Unified PayPal payment gateway for invoice context.
 *
 * @package DoubleScale\Pro\Modules\Pro\Payment
 */

namespace DoubleScale\Pro\Modules\Pro\Payment;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\Gateway;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Core\Payment\PayableSubject;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Pro\Modules\Integrations\PayPal\Api;
use DoubleScale\Pro\Modules\Integrations\PayPal\Integration as PayPalIntegration;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;
use WP_Error;

/**
 * PayPalGateway class.
 */
class PayPalGateway extends Gateway {

	public $name = 'PayPal';

	public $slug = 'paypal';

	public $description = 'PayPal checkout — credentials in Integrations → PayPal.';

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
		return PayPalIntegration::instance()->is_configured();
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'gateway_not_configured', __( 'PayPal is not configured.', 'doublescale' ), array( 'status' => 503 ) );
		}

		try {
			$api      = PayPalIntegration::instance()->connect();
			$amount   = $subject->amount_due();
			$currency = $subject->currency();
			$mode     = PayPalIntegration::instance()->get_mode_settings();

			$metadata     = $this->payment_metadata( $subject );
			$order_result = $this->resolve_or_create_order( $api, $subject, $amount, $currency, $metadata );
			if ( ! $order_result['success'] ) {
				return new WP_Error( 'paypal_error', $order_result['message'] ?? __( 'Could not create PayPal order.', 'doublescale' ), array( 'status' => 500 ) );
			}

			$order_data = $order_result['data'];
			$status     = strtoupper( (string) ( $order_data['status'] ?? '' ) );

			if ( 'COMPLETED' === $status ) {
				$capture = $this->extract_capture( $order_data );
				if ( $capture ) {
					$this->record_paid( $subject, $capture );
				}
				return $this->shape_already_paid_response( $subject, $api, $amount, $currency, $status );
			}

			return array(
				'gateway'   => $this->slug,
				'order_id'  => (string) ( $order_data['id'] ?? '' ),
				'mode'      => (string) ( $mode['mode'] ?? 'sandbox' ),
				'client_id' => (string) ( $mode['client_id'] ?? '' ),
				'amount'    => $amount,
				'currency'  => $currency,
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'PayPal payment init failed',
				array(
					'code'    => 'paypal_payment_init_failed',
					'context' => $subject->context(),
					'message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'paypal_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function confirm( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'gateway_not_configured', __( 'PayPal is not configured.', 'doublescale' ), array( 'status' => 503 ) );
		}

		$order_id = $subject->external_payment_ref();
		if ( null === $order_id || '' === $order_id ) {
			return new WP_Error( 'invalid_data', __( 'No PayPal order is in progress.', 'doublescale' ), array( 'status' => 400 ) );
		}

		try {
			$api = PayPalIntegration::instance()->connect();

			$order = $api->get_order( $order_id );
			if ( ! $order['success'] ) {
				return new WP_Error( 'paypal_error', $order['message'] ?? __( 'Could not retrieve PayPal order.', 'doublescale' ), array( 'status' => 500 ) );
			}

			$order_data = $order['data'];
			$status     = strtoupper( (string) ( $order_data['status'] ?? '' ) );

			if ( 'COMPLETED' !== $status ) {
				$capture_result = $api->capture_order( $order_id );
				if ( ! $capture_result['success'] ) {
					return new WP_Error( 'paypal_error', $capture_result['message'] ?? __( 'PayPal capture failed.', 'doublescale' ), array( 'status' => 500 ) );
				}
				$order_data = $capture_result['data'];
				$status     = strtoupper( (string) ( $order_data['status'] ?? '' ) );
			}

			if ( 'COMPLETED' === $status ) {
				$capture = $this->extract_capture( $order_data );
				if ( $capture ) {
					$this->record_paid( $subject, $capture );
				}
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
			return new WP_Error( 'paypal_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @param object         $charge  PayPal capture object.
	 * @return void
	 */
	public function record_paid( PayableSubject $subject, object $charge ): void {
		$normalized = $this->normalize_capture( $charge );
		if ( ! $normalized ) {
			return;
		}
		$subject->record_payment( $normalized );
	}

	/**
	 * @param Api            $api      API client.
	 * @param PayableSubject $subject  Subject.
	 * @param float          $amount   Major units.
	 * @param string         $currency ISO currency.
	 * @param array          $metadata Payment metadata.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	private function resolve_or_create_order( Api $api, PayableSubject $subject, float $amount, string $currency, array $metadata ): array {
		$ref = $subject->external_payment_ref();
		if ( null !== $ref && '' !== $ref ) {
			$existing = $api->get_order( $ref );
			if ( $existing['success'] ) {
				$order_data = $existing['data'];
				$status     = strtoupper( (string) ( $order_data['status'] ?? '' ) );

				if ( 'APPROVED' === $status ) {
					$capture = $api->capture_order( $ref );
					if ( $capture['success'] ) {
						return array(
							'success' => true,
							'data'    => $capture['data'],
						);
					}
				} elseif ( 'COMPLETED' === $status ) {
					return array(
						'success' => true,
						'data'    => $order_data,
					);
				}
			}
		}

		$created = $api->create_order( $amount, $currency, $metadata );
		if ( ! $created['success'] ) {
			return $created;
		}

		$order_id = (string) ( $created['data']['id'] ?? '' );
		if ( '' !== $order_id ) {
			if ( $subject instanceof InvoicePayableSubject ) {
				$subject->get_invoice()->set_in_progress_payment_ref( $order_id, false );
			} else {
				$subject->set_external_payment_ref( $order_id );
			}
		}

		return array(
			'success' => true,
			'data'    => $created['data'],
		);
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array<string, mixed>
	 */
	private function payment_metadata( PayableSubject $subject ): array {
		$metadata = $subject->metadata();
		if ( ! $subject instanceof InvoicePayableSubject ) {
			return $metadata;
		}

		$public_url = InvoiceUrl::get_public_url( $subject->get_invoice() );
		if ( '' !== $public_url ) {
			$metadata['return_url'] = $public_url;
			$metadata['cancel_url'] = $public_url;
		}

		return $metadata;
	}

	/**
	 * @param array $order_data PayPal order payload.
	 * @return object|null
	 */
	private function extract_capture( array $order_data ) {
		$captures = $order_data['purchase_units'][0]['payments']['captures'] ?? array();
		if ( empty( $captures[0] ) || ! is_array( $captures[0] ) ) {
			return null;
		}
		return (object) $captures[0];
	}

	/**
	 * @param object $capture PayPal capture or normalized object.
	 * @return object|null
	 */
	private function normalize_capture( object $capture ) {
		$capture_id = (string) ( $capture->transaction_id ?? $capture->id ?? '' );
		if ( '' === $capture_id ) {
			return null;
		}

		if ( isset( $capture->payment_mode ) && 'paypal' === $capture->payment_mode ) {
			return $capture;
		}

		$amount_data = $capture->amount ?? null;
		if ( is_object( $amount_data ) ) {
			$amount   = (float) ( $amount_data->value ?? 0 );
			$currency = strtolower( (string) ( $amount_data->currency_code ?? '' ) );
		} elseif ( is_array( $amount_data ) ) {
			$amount   = (float) ( $amount_data['value'] ?? 0 );
			$currency = strtolower( (string) ( $amount_data['currency_code'] ?? '' ) );
		} else {
			$amount   = (float) ( $capture->amount ?? 0 );
			$currency = strtolower( (string) ( $capture->currency ?? '' ) );
		}

		return (object) array(
			'payment_mode'   => 'paypal',
			'transaction_id' => $capture_id,
			'id'             => $capture_id,
			'amount'         => $amount,
			'currency'       => $currency,
		);
	}

	/**
	 * @param PayableSubject $subject  Subject.
	 * @param Api            $api      API client.
	 * @param float          $amount   Major units.
	 * @param string         $currency ISO currency.
	 * @param string         $status   Order status.
	 * @return array<string, mixed>
	 */
	private function shape_already_paid_response( PayableSubject $subject, Api $api, float $amount, string $currency, string $status ): array {
		$mode_settings = PayPalIntegration::instance()->get_mode_settings();
		$response      = array(
			'gateway'   => $this->slug,
			'already_paid' => true,
			'status'    => $status,
			'client_id' => (string) ( $mode_settings['client_id'] ?? $api->client_id() ),
			'amount'    => $amount,
			'currency'  => $currency,
		);

		if ( $subject instanceof InvoicePayableSubject ) {
			$invoice = $subject->get_invoice();
			$invoice->refresh();
			$response['invoice'] = InvoiceShaper::shape( $invoice, true );
		}

		return $response;
	}
}
