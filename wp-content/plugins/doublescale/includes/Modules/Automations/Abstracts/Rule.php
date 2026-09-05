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

use DoubleScale\Core\Utils\DateWithin;
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

	/**
	 * Compare an actual datetime against a date operator (before/after/on/between/within).
	 *
	 * `within` is a rolling day count. Legacy calendar ranges are still accepted.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $actual     Contact datetime string or timestamp.
	 * @param string $operator   Date operator.
	 * @param mixed  $rule_value Condition value.
	 * @return bool
	 */
	protected function is_date_condition_met( $actual, $operator, $rule_value ) {
		if ( empty( $actual ) ) {
			return false;
		}

		$actual_ts = is_numeric( $actual ) && (int) $actual > 100000
			? (int) $actual
			: strtotime( (string) $actual );
		if ( false === $actual_ts ) {
			return false;
		}

		switch ( $operator ) {
			case 'before':
				$compare_ts = strtotime( (string) $rule_value );
				return false !== $compare_ts && $actual_ts < $compare_ts;

			case 'after':
				$compare_ts = strtotime( (string) $rule_value );
				return false !== $compare_ts && $actual_ts > $compare_ts;

			case 'on':
				$compare_ts = strtotime( (string) $rule_value );
				return false !== $compare_ts && gmdate( 'Y-m-d', $actual_ts ) === gmdate( 'Y-m-d', $compare_ts );

			case 'between':
				if ( ! is_array( $rule_value ) || count( $rule_value ) < 2 ) {
					return false;
				}
				$start_ts = strtotime( (string) $rule_value[0] );
				$end_ts   = strtotime( (string) $rule_value[1] );
				return false !== $start_ts && false !== $end_ts && $actual_ts >= $start_ts && $actual_ts <= $end_ts;

			case 'within':
				$days = DateWithin::parse_days( $rule_value );
				if ( null !== $days ) {
					return DateWithin::is_within_days( $actual_ts, $days );
				}
				if ( is_array( $rule_value ) && count( $rule_value ) >= 2 ) {
					$start_ts = strtotime( (string) $rule_value[0] );
					$end_ts   = strtotime( (string) $rule_value[1] );
					return false !== $start_ts && false !== $end_ts && $actual_ts >= $start_ts && $actual_ts <= $end_ts;
				}
				return false;

			default:
				return false;
		}
	}
}
