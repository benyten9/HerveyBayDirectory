<?php
/**
 * Shared WooCommerce order trigger phone resolution.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Order;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Phone helpers for WooCommerce order and subscription triggers.
 *
 * The automation engine creates or updates the buyer's contact from the trigger
 * payload, so every trigger built from an order passes `phone` and `country`
 * next to the name and email. Without them the buyer is saved with no phone or
 * WhatsApp number.
 */
trait OrderTriggerPhone {

	/**
	 * Resolve the best available phone number from an order.
	 *
	 * Prefers billing phone, then shipping phone (HPOS/legacy safe).
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	protected function resolve_order_phone( $order ) {
		$billing = trim( (string) $order->get_billing_phone() );
		if ( '' !== $billing ) {
			return $billing;
		}

		if ( method_exists( $order, 'get_shipping_phone' ) ) {
			$shipping = trim( (string) $order->get_shipping_phone() );
			if ( '' !== $shipping ) {
				return $shipping;
			}
		}

		return '';
	}

	/**
	 * Resolve the billing/shipping country (ISO alpha-2) from an order.
	 *
	 * Used as the calling-code hint when converting a national phone number into
	 * E.164 for the contact's WhatsApp field.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	protected function resolve_order_country( $order ) {
		$billing = trim( (string) $order->get_billing_country() );
		if ( '' !== $billing ) {
			return $billing;
		}

		return trim( (string) $order->get_shipping_country() );
	}
}
