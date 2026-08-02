<?php

/**
 * Class Order Status
 *
 * This class is responsible for handling the order status constants
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Order Status class
 */
class OrderStatus {


	/**
	 * Pending Payment
	 */
	const PENDING_PAYMENT = 'wc-pending';

	/**
	 * Processing
	 */
	const PROCESSING = 'wc-processing';

	/**
	 * On Hold
	 */
	const ON_HOLD = 'wc-on-hold';

	/**
	 * Completed
	 */
	const COMPLETED = 'wc-completed';

	/**
	 * Cancelled
	 */
	const CANCELLED = 'wc-cancelled';

	/**
	 * Refunded
	 */
	const REFUNDED = 'wc-refunded';

	/**
	 * Failed
	 */
	const FAILED = 'wc-failed';

	/**
	 * Checkout Draft
	 */
	const CHECKOUT_DRAFT = 'wc-checkout-draft';

	/**
	 * Statuses that count toward contact revenue and purchase history totals.
	 *
	 * Excludes drafts, pending, on-hold, cancelled, refunded, and failed orders.
	 *
	 * @return array<int, string>
	 */
	public static function get_revenue_statuses() {
		return array(
			self::COMPLETED,
			self::PROCESSING,
		);
	}

	/**
	 * Whether an order status counts toward revenue.
	 *
	 * @param string $status Order status slug (e.g. wc-completed).
	 * @return bool
	 */
	public static function is_revenue_status( $status ) {
		return in_array( $status, self::get_revenue_statuses(), true );
	}

	/**
	 * Get all order statuses for automation UI (triggers, actions, rules).
	 *
	 * Uses WooCommerce's registered statuses when available so custom statuses
	 * (e.g. Abandoned Cart) appear alongside the core ones.
	 *
	 * @return array<string, string> Status slug (wc-*) => label.
	 */
	public static function get_all() {
		$fallback = array(
			self::PENDING_PAYMENT => __( 'Pending Payment', 'doublescale' ),
			self::PROCESSING      => __( 'Processing', 'doublescale' ),
			self::ON_HOLD         => __( 'On Hold', 'doublescale' ),
			self::COMPLETED       => __( 'Completed', 'doublescale' ),
			self::CANCELLED       => __( 'Cancelled', 'doublescale' ),
			self::REFUNDED        => __( 'Refunded', 'doublescale' ),
			self::FAILED          => __( 'Failed', 'doublescale' ),
			self::CHECKOUT_DRAFT  => __( 'Checkout Draft', 'doublescale' ),
		);

		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return $fallback;
		}

		$statuses = wc_get_order_statuses();
		if ( ! is_array( $statuses ) || empty( $statuses ) ) {
			return $fallback;
		}

		// Ensure keys keep the wc- prefix WooCommerce / our triggers expect.
		$normalized = array();
		foreach ( $statuses as $slug => $label ) {
			$slug = (string) $slug;
			if ( 0 !== strpos( $slug, 'wc-' ) ) {
				$slug = 'wc-' . ltrim( $slug, '-' );
			}
			$normalized[ $slug ] = (string) $label;
		}

		return $normalized;
	}
}
