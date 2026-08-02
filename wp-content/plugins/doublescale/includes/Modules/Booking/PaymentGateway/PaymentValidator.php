<?php
/**
 * Validates payment-related settings on event/booking writes.
 *
 * Stripe is the only booking-side payment gateway. Credentials live in the
 * global Stripe integration; per-event toggles live in `payments_settings`
 * and are validated here.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\PaymentGateway;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;
use WP_Error;

class PaymentValidator {

	/**
	 * Validate that payment settings have at least one gateway enabled when
	 * payments are enabled.
	 *
	 * @param array $payments_settings The payment settings to validate.
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_payment_gateways( $payments_settings ) {
		if ( ! $payments_settings ) {
			return true;
		}

		$enable_payment                 = Arr::get( $payments_settings, 'enable_payment', false );
		$items                          = Arr::get( $payments_settings, 'items', array() );
		$payment_type                   = Arr::get( $payments_settings, 'type', 'native' );
		$enable_items_based_on_duration = Arr::get( $payments_settings, 'enable_items_based_on_duration', false );
		$multi_duration_items           = Arr::get( $payments_settings, 'multi_duration_items', array() );

		if ( ! $enable_payment ) {
			return true;
		}

		// Native payment requires at least one item.
		if ( 'native' === $payment_type ) {
			if ( $enable_items_based_on_duration && empty( $multi_duration_items ) ) {
				return new WP_Error(
					'payment_items_required',
					__( 'Payment is enabled with multiple duration options, but no payment items are defined. Please add at least one payment item for each duration.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
			if ( ! $enable_items_based_on_duration && empty( $items ) ) {
				return new WP_Error(
					'payment_items_required',
					__( 'Payment is enabled but no payment items are defined. Please add at least one payment item.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
		}

		// At least one registered gateway must be enabled. We collect known
		// slugs from the `doublescale_booking_payment_gateways` filter, then
		// check both the `payment_methods` array and the per-gateway
		// `enable_<slug>` flag against it. A `payment_methods` entry for an
		// unknown gateway (e.g. legacy `paypal` data) is rejected, not
		// silently accepted.
		$registered_slugs = array();
		foreach ( (array) apply_filters( 'doublescale_booking_payment_gateways', array() ) as $gateway ) {
			$slug = is_object( $gateway ) ? ( $gateway->slug ?? '' ) : ( $gateway['slug'] ?? '' );
			if ( '' !== $slug ) {
				$registered_slugs[] = $slug;
			}
		}

		$payment_methods = (array) Arr::get( $payments_settings, 'payment_methods', array() );
		foreach ( $payment_methods as $method ) {
			if ( in_array( $method, $registered_slugs, true ) ) {
				return true;
			}
		}

		foreach ( $registered_slugs as $slug ) {
			if ( Arr::get( $payments_settings, 'enable_' . $slug, false ) ) {
				return true;
			}
		}

		return new WP_Error(
			'payment_gateway_required',
			__( 'Payment is enabled but no payment gateway is selected. Please enable Stripe.', 'doublescale' ),
			array( 'status' => 400 )
		);
	}
}
