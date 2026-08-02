<?php
/**
 * Task Type Constants
 * Defines string constants for CRM task types
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * TaskType class
 *
 * Provides constants and utility methods for task types (call, email, meeting, etc.).
 */
class TaskType {

	/**
	 * Call - Phone call task
	 */
	const CALL = 'call';

	/**
	 * Email - Email task
	 */
	const EMAIL = 'email';

	/**
	 * Meeting - Meeting/appointment task
	 */
	const MEETING = 'meeting';

	/**
	 * Todo - General to-do task
	 */
	const TODO = 'todo';

	/**
	 * Follow Up - Follow up task
	 */
	const FOLLOW_UP = 'follow_up';

	/**
	 * Get all task types
	 *
	 * @return array Associative array of type constants to labels
	 */
	public static function get_all() {
		$types = array(
			self::CALL      => __( 'Call', 'doublescale' ),
			self::EMAIL     => __( 'Email', 'doublescale' ),
			self::MEETING   => __( 'Meeting', 'doublescale' ),
			self::TODO      => __( 'To-Do', 'doublescale' ),
			self::FOLLOW_UP => __( 'Follow Up', 'doublescale' ),
		);

		/**
		 * Filter task types to allow extensions to add custom types
		 *
		 * @param array $types Task types array.
		 */
		return apply_filters( 'doublescale_task_types', $types );
	}

	/**
	 * Get type label
	 *
	 * @param string $type Type constant value.
	 * @return string Type label
	 */
	public static function get_label( $type ) {
		$types = self::get_all();
		return $types[ $type ] ?? __( 'Unknown', 'doublescale' );
	}

	/**
	 * Check if type is valid
	 *
	 * @param string $type Type to validate.
	 * @return bool True if valid type
	 */
	public static function is_valid( $type ) {
		$types = self::get_all();
		return isset( $types[ $type ] );
	}

	/**
	 * Get default type
	 *
	 * @return string Default type
	 */
	public static function get_default() {
		return self::TODO;
	}

	/**
	 * Get icon name for type (for frontend use)
	 *
	 * @param string $type Type constant.
	 * @return string Icon identifier
	 */
	public static function get_icon( $type ) {
		$icons = array(
			self::CALL      => 'phone',
			self::EMAIL     => 'mail',
			self::MEETING   => 'calendar',
			self::TODO      => 'check-square',
			self::FOLLOW_UP => 'refresh-cw',
		);

		/**
		 * Filter task type icons
		 *
		 * @param array $icons Task type icons array.
		 */
		$icons = apply_filters( 'doublescale_task_type_icons', $icons );

		return $icons[ $type ] ?? 'circle';
	}

	/**
	 * Get color for type (for frontend badges)
	 *
	 * @param string $type Type constant.
	 * @return string Color identifier
	 */
	public static function get_color( $type ) {
		$colors = array(
			self::CALL      => '#CB5301', // Orange
			self::EMAIL     => '#3B82F6', // Blue
			self::MEETING   => '#8B5CF6', // Purple
			self::TODO      => '#6B7280', // Gray
			self::FOLLOW_UP => '#10B981', // Green
		);

		/**
		 * Filter task type colors
		 *
		 * @param array $colors Task type colors array.
		 */
		$colors = apply_filters( 'doublescale_task_type_colors', $colors );

		return $colors[ $type ] ?? '#6B7280';
	}

	/**
	 * Get options for select dropdowns
	 *
	 * @return array Array of options with value and label
	 */
	public static function get_options() {
		$options = array();
		foreach ( self::get_all() as $value => $label ) {
			$options[] = array(
				'value' => $value,
				'label' => $label,
				'icon'  => self::get_icon( $value ),
				'color' => self::get_color( $value ),
			);
		}
		return $options;
	}
}
