<?php
/**
 * Task Entity Type Constants
 * Defines entity types for polymorphic task associations
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * TaskEntityType class
 *
 * Provides constants for task entity types (Contact, Deal).
 * Used for polymorphic relationships where tasks can belong to either contacts or deals.
 */
class TaskEntityType {

	/**
	 * Contact - Task associated with a contact
	 */
	const CONTACT = 1;

	/**
	 * Deal - Task associated with a deal (Pro feature)
	 */
	const DEAL = 2;

	/**
	 * Project - Task associated with a project (Pro feature)
	 */
	const PROJECT = 3;

	/**
	 * Get all entity types
	 *
	 * @return array Associative array of type constants to labels
	 */
	public static function get_all() {
		return array(
			self::CONTACT => __( 'Contact', 'doublescale' ),
			self::DEAL    => __( 'Deal', 'doublescale' ),
			self::PROJECT => __( 'Project', 'doublescale' ),
		);
	}

	/**
	 * Get entity type label
	 *
	 * @param int $type Entity type constant value.
	 * @return string Entity type label
	 */
	public static function get_label( $type ) {
		$types = self::get_all();
		return $types[ $type ] ?? __( 'Unknown', 'doublescale' );
	}

	/**
	 * Check if entity type is valid
	 *
	 * @param int $type Entity type to validate.
	 * @return bool True if valid entity type
	 */
	public static function is_valid( $type ) {
		$types = self::get_all();
		return isset( $types[ $type ] );
	}

	/**
	 * Get default entity type
	 *
	 * @return int Default entity type (Contact)
	 */
	public static function get_default() {
		return self::CONTACT;
	}

	/**
	 * Get available entity types based on active plugins
	 *
	 * Filters out Deal type if Pro plugin is not active.
	 *
	 * @return array Available entity types
	 */
	public static function get_available() {
		$types = self::get_all();

		if ( ! class_exists( 'DoubleScale\\Pro\\Modules\\Deals\\Models\\DealModel', false ) ) {
			unset( $types[ self::DEAL ] );
		}

		if ( ! class_exists( 'DoubleScale\\Pro\\Modules\\Projects\\Models\\ProjectModel', false ) ) {
			unset( $types[ self::PROJECT ] );
		}

		return $types;
	}

	/**
	 * Get options for select dropdowns
	 *
	 * @return array Array of options with value and label
	 */
	public static function get_options() {
		$options = array();
		foreach ( self::get_available() as $value => $label ) {
			$options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		return $options;
	}

	/**
	 * Check if entity type requires Pro
	 *
	 * @param int $type Entity type to check.
	 * @return bool True if type requires Pro plugin
	 */
	public static function requires_pro( $type ) {
		return self::DEAL === $type || self::PROJECT === $type;
	}
}
