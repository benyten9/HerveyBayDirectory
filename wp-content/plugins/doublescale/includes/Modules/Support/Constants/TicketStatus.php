<?php
/**
 * Ticket status constants.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket status values and helpers.
 */
class TicketStatus {

	const OPEN     = 'open';
	const PENDING  = 'pending';
	const RESOLVED = 'resolved';
	const CLOSED   = 'closed';

	/**
	 * Every valid status value.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::OPEN, self::PENDING, self::RESOLVED, self::CLOSED );
	}

	/**
	 * Statuses considered active (not closed). Used by inbox filters that show
	 * "everything still on someone's plate".
	 *
	 * @return string[]
	 */
	public static function get_active_statuses() {
		return array( self::OPEN, self::PENDING, self::RESOLVED );
	}

	/**
	 * Human-readable label for a status.
	 *
	 * @param string $status Status value.
	 * @return string
	 */
	public static function get_label( $status ) {
		$labels = array(
			self::OPEN     => __( 'Open', 'doublescale' ),
			self::PENDING  => __( 'Pending', 'doublescale' ),
			self::RESOLVED => __( 'Resolved', 'doublescale' ),
			self::CLOSED   => __( 'Closed', 'doublescale' ),
		);

		return $labels[ $status ] ?? ucfirst( (string) $status );
	}

	/**
	 * Whether a status value is valid.
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_valid( $status ) {
		return in_array( $status, self::all(), true );
	}
}
