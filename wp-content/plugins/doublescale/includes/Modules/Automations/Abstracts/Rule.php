<?php

/**
 * Class Rule
 *
 * This class is responsible for handling the conditions rules
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Rule class
 */
abstract class Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name;

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug;

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group;

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type;



	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array();

	/**
	 * Is automation rule
	 *
	 * @var bool
	 */
	public $is_automation = true;

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'               => __( 'Is', 'doublescale' ),
			'is_not'           => __( 'Is not', 'doublescale' ),
			'contains'         => __( 'Contains', 'doublescale' ),
			'does_not_contain' => __( 'Does not contain', 'doublescale' ),
			'starts_with'      => __( 'Starts with', 'doublescale' ),
			'ends_with'        => __( 'Ends with', 'doublescale' ),
			'is_empty'         => __( 'Is empty', 'doublescale' ),
			'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
		);
	}

	/**
	 * Has options
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_options() {
		return false;
	}

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		return array();
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return mixed
	 */
	abstract public function get_value( $automation_contact );

	/**
	 * Is met
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @param array                  $rule Rule.
	 *
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = $this->get_value( $automation_contact );
		$operator   = $rule['operator'];
		$rule_value = $rule['value'];
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated by WP_DEBUG; aids rule-engine debugging without polluting production logs.
			error_log( 'operator: ' . $operator );
		}
		switch ( $operator ) {
			case 'is':
				if ( is_array( $value ) ) {
					$contact_values = array_map( 'intval', $value );
					$rule_values    = array_map( 'intval', (array) $rule_value );
					// All selected values must be present on the contact.
					return empty( array_diff( $rule_values, $contact_values ) );
				}

				return ($value == $rule_value); // phpcs:ignore

			case 'is_not':
				if ( is_array( $value ) ) {
					$contact_values = array_map( 'intval', $value );
					$rule_values    = array_map( 'intval', (array) $rule_value );
					// Contact must not have any of the specified values.
					return empty( array_intersect( $contact_values, $rule_values ) );
				}
				return ($value != $rule_value); // phpcs:ignore

			case 'greater_than':
				if ( ! is_numeric( $rule_value ) || ! is_numeric( $value ) ) {
					return false;
				}
				return (float) $value > (float) $rule_value;

			case 'lower_than':
				if ( ! is_numeric( $rule_value ) || ! is_numeric( $value ) ) {
					return false;
				}
				return (float) $value < (float) $rule_value;

			case 'contains':
				if ( is_array( $value ) ) {
					$contact_values = array_map( 'intval', $value );
					$rule_values    = array_map( 'intval', (array) $rule_value );
					// At least one selected value must be present on the contact.
					return ! empty( array_intersect( $contact_values, $rule_values ) );
				}
				return strpos( $value, $rule_value ) !== false;

			case 'not_contains':
			case 'does_not_contain':
				if ( is_array( $value ) ) {
					$contact_values = array_map( 'intval', $value );
					$rule_values    = array_map( 'intval', (array) $rule_value );
					return empty( array_intersect( $contact_values, $rule_values ) );
				}
				return strpos( $value, $rule_value ) === false;

			case 'starts_with':
				if ( strlen( $rule_value ) > strlen( $value ) ) {
					return false;
				}
				return substr_compare( $value, $rule_value, 0, strlen( $rule_value ) ) === 0;

			case 'ends_with':
				if ( strlen( $rule_value ) > strlen( $value ) ) {
					return false;
				}
				return substr_compare( $value, $rule_value, -strlen( $rule_value ) ) === 0;
			case 'is_empty':
				return empty( $value );
			case 'is_not_empty':
				return ! empty( $value );
			default:
				return false;
		}

		return true;
	}
}
