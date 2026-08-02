<?php
/**
 * Message Direction Constants
 * Defines the direction of message flow (outbound/inbound)
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * MessageDirection class
 */
class MessageDirection {

	/**
	 * Outbound - Sent from us to contact
	 */
	const OUTBOUND = 1;

	/**
	 * Inbound - Received from contact to us
	 */
	const INBOUND = 2;

	/**
	 * Get all directions
	 *
	 * @return array Associative array of direction constants to labels
	 */
	public static function get_all() {
		return array(
			self::OUTBOUND => __( 'Outbound', 'doublescale' ),
			self::INBOUND  => __( 'Inbound', 'doublescale' ),
		);
	}

	/**
	 * Get direction name/label
	 *
	 * @param int $direction Direction constant value.
	 * @return string Direction label
	 */
	public static function get_name( $direction ) {
		$directions = self::get_all();
		return $directions[ $direction ] ?? __( 'Unknown', 'doublescale' );
	}

	/**
	 * Get direction slug (for Api responses)
	 *
	 * @param int $direction Direction constant value.
	 * @return string Direction slug
	 */
	public static function get_slug( $direction ) {
		$map = array(
			self::OUTBOUND => 'outbound',
			self::INBOUND  => 'inbound',
		);
		return $map[ $direction ] ?? 'unknown';
	}

	/**
	 * Get direction constant from slug (for Api input)
	 *
	 * @param string $slug Direction slug.
	 * @return int|null Direction constant or null if not found
	 */
	public static function from_slug( $slug ) {
		$map = array(
			'outbound' => self::OUTBOUND,
			'inbound'  => self::INBOUND,
		);
		return $map[ strtolower( $slug ) ] ?? null;
	}

	/**
	 * Check if direction is valid
	 *
	 * @param int $direction Direction to validate.
	 * @return bool True if valid direction
	 */
	public static function is_valid( $direction ) {
		$directions = self::get_all();
		return isset( $directions[ $direction ] );
	}

	/**
	 * Check if direction is outbound
	 *
	 * @param int $direction Direction constant.
	 * @return bool True if outbound
	 */
	public static function is_outbound( $direction ) {
		return $direction === self::OUTBOUND;
	}

	/**
	 * Check if direction is inbound
	 *
	 * @param int $direction Direction constant.
	 * @return bool True if inbound
	 */
	public static function is_inbound( $direction ) {
		return $direction === self::INBOUND;
	}

	/**
	 * Get icon class for UI
	 *
	 * @param int $direction Direction constant.
	 * @return string Icon class name
	 */
	public static function get_icon_class( $direction ) {
		$icons = array(
			self::OUTBOUND => 'arrow-up-circle',
			self::INBOUND  => 'arrow-down-circle',
		);
		return $icons[ $direction ] ?? 'help-circle';
	}

	/**
	 * Get color class for UI
	 *
	 * @param int $direction Direction constant.
	 * @return string CSS color class
	 */
	public static function get_color_class( $direction ) {
		$colors = array(
			self::OUTBOUND => 'text-green-600 bg-green-50 border-green-600',
			self::INBOUND  => 'text-blue-600 bg-blue-50 border-blue-600',
		);
		return $colors[ $direction ] ?? 'text-gray-600 bg-gray-50 border-gray-600';
	}

	/**
	 * Get default direction
	 *
	 * @return int
	 */
	public static function get_default() {
		return self::OUTBOUND;
	}
}
