<?php
/**
 * Helpers for condition step settings shape.
 *
 * Condition rules are stored either as a plain array of rule groups (legacy)
 * or as { groups: [...], custom_label?: string } when a custom name is set.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Support;

defined( 'ABSPATH' ) || exit;

/**
 * ConditionSettings utility class.
 */
final class ConditionSettings {

	/**
	 * Extract rule groups from condition settings.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $settings Step settings value.
	 *
	 * @return array
	 */
	public static function get_rule_groups( $settings ) {
		if ( empty( $settings ) ) {
			return array();
		}

		if ( is_array( $settings ) && isset( $settings['groups'] ) && is_array( $settings['groups'] ) ) {
			return $settings['groups'];
		}

		if ( is_array( $settings ) ) {
			return $settings;
		}

		return array();
	}

	/**
	 * Read a custom display label from wrapped condition settings.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $settings Step settings value.
	 *
	 * @return string
	 */
	public static function get_custom_label( $settings ) {
		if ( is_array( $settings ) && isset( $settings['custom_label'] ) && is_string( $settings['custom_label'] ) ) {
			return trim( $settings['custom_label'] );
		}

		return '';
	}

	/**
	 * Whether the condition has at least one rule group.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $settings Step settings value.
	 *
	 * @return bool
	 */
	public static function has_rules( $settings ) {
		$groups = self::get_rule_groups( $settings );

		return ! empty( $groups );
	}
}
