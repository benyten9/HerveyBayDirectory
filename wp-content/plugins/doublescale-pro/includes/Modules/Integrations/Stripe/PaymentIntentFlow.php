<?php
/**
 * Shared PaymentIntent create-or-reuse algorithm for Stripe gateways.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Stripe
 */

namespace DoubleScale\Pro\Modules\Integrations\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * PaymentIntentFlow class.
 */
final class PaymentIntentFlow {

	/**
	 * Reuse an in-flight PI when possible; otherwise cancel stale intents and create a new one.
	 *
	 * @param PaymentService $service        Stripe service.
	 * @param string|null    $existing_pi_id Stored payment intent id, if any.
	 * @param float          $amount         Major units.
	 * @param string         $currency       ISO currency.
	 * @param callable       $create         Factory returning a new PaymentIntent.
	 * @return array{0: object, 1: bool} PaymentIntent and whether it was newly created.
	 */
	public static function resolve_or_create( PaymentService $service, ?string $existing_pi_id, float $amount, string $currency, callable $create ): array {
		if ( $existing_pi_id ) {
			$existing = $service->retrieve_payment_intent( $existing_pi_id );
			if ( $existing && ! empty( $existing->status ) ) {
				if ( in_array( $existing->status, array( 'processing', 'succeeded' ), true ) ) {
					return array( $existing, false );
				}
				if ( in_array( $existing->status, array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true ) ) {
					if ( self::amount_matches( $existing, $amount, $currency ) ) {
						return array( $existing, false );
					}
					try {
						$service->client()->paymentIntents->cancel( $existing_pi_id );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}
		}

		return array( $create(), true );
	}

	/**
	 * Compare a PaymentIntent amount/currency to the expected charge.
	 *
	 * @param object $pi       Stripe PaymentIntent.
	 * @param float  $amount   Major units.
	 * @param string $currency ISO currency.
	 * @return bool
	 */
	public static function amount_matches( object $pi, float $amount, string $currency ): bool {
		$expected_minor = Utils::to_stripe_amount( $amount, $currency );
		$same_amount    = (int) ( $pi->amount ?? 0 ) === $expected_minor;
		$same_currency  = strtolower( (string) ( $pi->currency ?? '' ) ) === strtolower( $currency );

		return $same_amount && $same_currency;
	}
}
