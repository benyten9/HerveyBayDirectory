<?php

/**
 * Class Subscription Status
 *
 * This class is responsible for handling the subscription status constants
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription Status class
 */
class SubscriptionStatus {



	/**
	 * Pending
	 */
	const PENDING = 'wc-pending';

	/**
	 * Active
	 */
	const ACTIVE = 'wc-active';

	/**
	 * On Hold
	 */
	const ON_HOLD = 'wc-on-hold';

	/**
	 * Cancelled
	 */
	const CANCELLED = 'wc-cancelled';

	/**
	 * Switched
	 */
	const SWITCHED = 'wc-switched';

	/**
	 * Expired
	 */
	const EXPIRED = 'wc-expired';

	/**
	 * Pending Cancellation
	 */
	const PENDING_CANCEL = 'wc-pending-cancel';

	/**
	 * Get all statuses
	 *
	 * @return array
	 */
	public static function get_all() {
		return array(
			self::PENDING        => __( 'Pending', 'doublescale' ),
			self::ACTIVE         => __( 'Active', 'doublescale' ),
			self::ON_HOLD        => __( 'On Hold', 'doublescale' ),
			self::CANCELLED      => __( 'Cancelled', 'doublescale' ),
			self::SWITCHED       => __( 'Switched', 'doublescale' ),
			self::EXPIRED        => __( 'Expired', 'doublescale' ),
			self::PENDING_CANCEL => __( 'Pending Cancellation', 'doublescale' ),
		);
	}

	/**
	 * Get active statuses
	 *
	 * @return array
	 */
	public static function get_active_statuses() {
		return array(
			self::ACTIVE,
			self::ON_HOLD,
		);
	}

	/**
	 * Get inactive statuses
	 *
	 * @return array
	 */
	public static function get_inactive_statuses() {
		return array(
			self::CANCELLED,
			self::EXPIRED,
			self::SWITCHED,
		);
	}

	/**
	 * Check if status is active
	 *
	 * @param string $status Status to check.
	 * @return bool
	 */
	public static function is_active( $status ) {
		return in_array( $status, self::get_active_statuses(), true );
	}

	/**
	 * Check if status is inactive
	 *
	 * @param string $status Status to check.
	 * @return bool
	 */
	public static function is_inactive( $status ) {
		return in_array( $status, self::get_inactive_statuses(), true );
	}
}
