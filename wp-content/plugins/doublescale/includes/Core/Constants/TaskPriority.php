<?php
/**
 * Task Priority Constants
 * Defines string constants for CRM task priorities
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * TaskPriority class
 *
 * Provides constants and utility methods for task priorities.
 */
class TaskPriority {

	/**
	 * Low priority
	 */
	const LOW = 'low';

	/**
	 * Medium priority (default)
	 */
	const MEDIUM = 'medium';

	/**
	 * High priority
	 */
	const HIGH = 'high';

	/**
	 * Get all priorities
	 *
	 * @return array Associative array of priority constants to labels
	 */
	public static function get_all() {
		return array(
			self::LOW    => __( 'Low', 'doublescale' ),
			self::MEDIUM => __( 'Medium', 'doublescale' ),
			self::HIGH   => __( 'High', 'doublescale' ),
		);
	}

	/**
	 * Get priority label
	 *
	 * @param string $priority Priority constant value.
	 * @return string Priority label
	 */
	public static function get_label( $priority ) {
		$priorities = self::get_all();
		return $priorities[ $priority ] ?? __( 'Unknown', 'doublescale' );
	}

	/**
	 * Check if priority is valid
	 *
	 * @param string $priority Priority to validate.
	 * @return bool True if valid priority
	 */
	public static function is_valid( $priority ) {
		$priorities = self::get_all();
		return isset( $priorities[ $priority ] );
	}

	/**
	 * Get default priority
	 *
	 * @return string Default priority
	 */
	public static function get_default() {
		return self::MEDIUM;
	}

	/**
	 * Get sort order (for sorting tasks by priority)
	 *
	 * @param string $priority Priority constant.
	 * @return int Sort order (lower = higher priority)
	 */
	public static function get_sort_order( $priority ) {
		$order = array(
			self::HIGH   => 1,
			self::MEDIUM => 2,
			self::LOW    => 3,
		);
		return $order[ $priority ] ?? 99;
	}

	/**
	 * Get color for priority (for frontend badges)
	 *
	 * @param string $priority Priority constant.
	 * @return string Color identifier
	 */
	public static function get_color( $priority ) {
		$colors = array(
			self::LOW    => '#6B7280', // Gray
			self::MEDIUM => '#F59E0B', // Amber
			self::HIGH   => '#EF4444', // Red
		);
		return $colors[ $priority ] ?? '#6B7280';
	}

	/**
	 * Get badge color name for UI components
	 *
	 * @param string $priority Priority constant.
	 * @return string Badge color name (default, warning, error)
	 */
	public static function get_badge_color( $priority ) {
		$colors = array(
			self::LOW    => 'default',
			self::MEDIUM => 'warning',
			self::HIGH   => 'error',
		);
		return $colors[ $priority ] ?? 'default';
	}

	/**
	 * Get options for select dropdowns
	 *
	 * @return array Array of options with value, label, and color
	 */
	public static function get_options() {
		$options = array();
		foreach ( self::get_all() as $value => $label ) {
			$options[] = array(
				'value' => $value,
				'label' => $label,
				'color' => self::get_color( $value ),
			);
		}
		return $options;
	}
}
