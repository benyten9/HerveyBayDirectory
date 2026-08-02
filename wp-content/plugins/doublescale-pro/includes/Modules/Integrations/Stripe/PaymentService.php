<?php
/**
 * Stripe payment service.
 *
 * Generic, module-agnostic API for creating and retrieving Stripe payment
 * intents using the global integration's credentials. Booking, future Deals
 * billing, and any other module use this directly — they own the per-charge
 * shape (amount, currency, metadata) and this service owns the SDK plumbing.
 *
 * Lifted from `Modules/Booking/PaymentGateways/Stripe/StripePaymentService.php`
 * with all booking-specific code removed (booking lookup, AJAX glue, order
 * row management — those live in the booking adapter now).
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Stripe;

use DoubleScale\Pro\Vendor\Stripe\PaymentIntent;
use DoubleScale\Pro\Vendor\Stripe\StripeClient;
use DoubleScale\Pro\Vendor\Stripe\Exception\ApiErrorException;

defined( 'ABSPATH' ) || exit;

class PaymentService {

	/**
	 * @var StripeClient
	 */
	private $client;

	/**
	 * @var array Resolved credentials for the active mode.
	 */
	private $mode_settings;

	/**
	 * @param array|null $mode_settings Pre-resolved credentials. If null, asks
	 *                                  the global integration for the active
	 *                                  mode.
	 *
	 * @throws \RuntimeException When credentials are missing entirely.
	 */
	public function __construct( ?array $mode_settings = null ) {
		if ( null === $mode_settings ) {
			$mode_settings = Integration::instance()->get_mode_settings();
		}
		if ( ! $mode_settings ) {
			throw new \RuntimeException( 'Stripe is not configured.' );
		}
		$this->mode_settings = $mode_settings;
		$this->client        = new StripeClient( $mode_settings['secret_key'] );
	}

	public function client(): StripeClient {
		return $this->client;
	}

	public function publishable_key(): string {
		return (string) ( $this->mode_settings['publishable_key'] ?? '' );
	}

	/**
	 * Create a payment intent. Caller supplies amount in major units, ISO
	 * currency, an existing Stripe customer id, and a metadata bag (must
	 * include `source` so the webhook knows which module owns the event).
	 *
	 * @throws \InvalidArgumentException For obviously-bad input.
	 * @throws ApiErrorException         When Stripe rejects the call.
	 */
	public function create_payment_intent( $amount, string $currency, string $customer_id, array $metadata = array() ): PaymentIntent {
		if ( ! is_numeric( $amount ) || $amount <= 0 ) {
			throw new \InvalidArgumentException( 'Invalid payment amount.' );
		}
		if ( '' === $currency ) {
			throw new \InvalidArgumentException( 'Currency is required.' );
		}
		if ( '' === $customer_id ) {
			throw new \InvalidArgumentException( 'Customer ID is required.' );
		}
		if ( empty( $metadata['source'] ) ) {
			throw new \InvalidArgumentException( 'Metadata must include a "source" key so webhooks can route the event.' );
		}

		return $this->client->paymentIntents->create(
			array(
				'amount'                    => Utils::to_stripe_amount( $amount, $currency ),
				'currency'                  => strtolower( $currency ),
				'description'               => get_bloginfo( 'name' ),
				'customer'                  => $customer_id,
				'automatic_payment_methods' => array( 'enabled' => true ),
				'metadata'                  => $metadata,
			)
		);
	}

	public function retrieve_payment_intent( string $payment_intent_id ): ?PaymentIntent {
		try {
			return $this->client->paymentIntents->retrieve( $payment_intent_id );
		} catch ( ApiErrorException $e ) {
			return null;
		}
	}

	public function retrieve_charge( string $charge_id ) {
		try {
			return $this->client->charges->retrieve( $charge_id );
		} catch ( ApiErrorException $e ) {
			return null;
		}
	}
}
