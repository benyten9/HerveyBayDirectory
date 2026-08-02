<?php
/**
 * Ticket priority constants.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket priority values and helpers.
 */
class TicketPriority {

	const LOW    = 'low';
	const NORMAL = 'normal';
	const HIGH   = 'high';
	const URGENT = 'urgent';

	/**
	 * Every valid priority value, ordered from least to most severe so list UIs
	 * that iterate get a consistent display order.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::LOW, self::NORMAL, self::HIGH, self::URGENT );
	}

	/**
	 * Human-readable label.
	 *
	 * @param string $priority Priority value.
	 * @return string
	 */
	public static function get_label( $priority ) {
		$labels = array(
			self::LOW    => __( 'Low', 'doublescale' ),
			self::NORMAL => __( 'Normal', 'doublescale' ),
			self::HIGH   => __( 'High', 'doublescale' ),
			self::URGENT => __( 'Urgent', 'doublescale' ),
		);

		return $labels[ $priority ] ?? ucfirst( (string) $priority );
	}

	/**
	 * Whether a priority value is valid.
	 *
	 * @param string $priority Priority value.
	 * @return bool
	 */
	public static function is_valid( $priority ) {
		return in_array( $priority, self::all(), true );
	}
}
