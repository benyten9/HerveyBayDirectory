<?php
/**
 * Message Source Types Constants
 * Defines the different sources from which messages can originate
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Message Source Types class
 */
class MessageSourceTypes {

	/**
	 * Campaign source - Regular campaigns sent to multiple contacts
	 */
	const CAMPAIGN = 1;

	/**
	 * Automation source - Messages sent as part of automation sequences
	 */
	const AUTOMATION = 2;

	/**
	 * Individual source - Individual/manual messages sent from contact page
	 */
	const INDIVIDUAL = 3;

	/**
	 * Booking source - Outgoing booking confirmation/cancellation/etc emails
	 */
	const BOOKING = 4;

	/**
	 * Get all source types
	 *
	 * @return array
	 */
	public static function get_all_types() {
		return array(
			self::CAMPAIGN   => __( 'Campaign', 'doublescale' ),
			self::AUTOMATION => __( 'Automation', 'doublescale' ),
			self::INDIVIDUAL => __( 'Individual', 'doublescale' ),
			self::BOOKING    => __( 'Booking', 'doublescale' ),
		);
	}

	/**
	 * Get source type label
	 *
	 * @param int $type Source type constant
	 * @return string
	 */
	public static function get_type_label( $type ) {
		$types = self::get_all_types();
		return isset( $types[ $type ] ) ? $types[ $type ] : __( 'Unknown', 'doublescale' );
	}

	/**
	 * Check if source type is valid
	 *
	 * @param int $type Source type to validate
	 * @return bool
	 */
	public static function is_valid_type( $type ) {
		return array_key_exists( $type, self::get_all_types() );
	}

	/**
	 * Check if source type supports analytics tracking
	 *
	 * @param int $type Source type
	 * @return bool
	 */
	public static function supports_analytics( $type ) {
		return in_array( $type, array( self::CAMPAIGN, self::AUTOMATION, self::INDIVIDUAL, self::BOOKING ) );
	}

	/**
	 * Get default source type
	 *
	 * @return int
	 */
	public static function get_default_type() {
		return self::CAMPAIGN;
	}
}
