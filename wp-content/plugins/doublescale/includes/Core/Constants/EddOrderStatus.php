<?php

/**
 * Easy Digital Downloads order status helpers.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * EDD order status constants aligned with EDD 3.x reporting.
 */
class EddOrderStatus {

	/**
	 * Subscription renewal status (Recurring Payments extension).
	 */
	const SUBSCRIPTION = 'edd_subscription';

	/**
	 * Statuses that count toward contact revenue for sale orders.
	 *
	 * Uses EDD net order statuses plus subscription renewals when available.
	 *
	 * @return array<int, string>
	 */
	public static function get_revenue_statuses() {
		if ( function_exists( 'edd_get_net_order_statuses' ) ) {
			$statuses = edd_get_net_order_statuses();
		} else {
			$statuses = array( 'complete' );
		}

		if ( ! in_array( self::SUBSCRIPTION, $statuses, true ) ) {
			$statuses[] = self::SUBSCRIPTION;
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Whether a sale order status counts toward revenue.
	 *
	 * @param string $status Order status slug.
	 * @return bool
	 */
	public static function is_revenue_status( $status ) {
		return in_array( $status, self::get_revenue_statuses(), true );
	}
}
