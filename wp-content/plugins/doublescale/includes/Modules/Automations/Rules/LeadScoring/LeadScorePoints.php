<?php

/**
 * Class LeadScorePoints
 *
 * This class is responsible for handling the contact lead score points rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\LeadScoring;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Lead Score Points class
 */
class LeadScorePoints extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Points';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'lead_score_points';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'lead_scoring';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'number';

	/**
	 * Is automation rule
	 *
	 * @var boolean
	 *
	 * @since 1.0.0
	 */
	public $is_automation = false;

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'greater_than' => __( 'Greater than', 'doublescale' ),
			'lower_than'   => __( 'Lower than', 'doublescale' ),
		);
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return int
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact ) {
			return 0;
		}

		$points = \doublescale_get_contact_meta( $contact->id, 'lead_score_points', true );

		if ( $points === '' || $points === null || false === $points ) {
			return 0;
		}

		return (int) $points;
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = (int) $this->get_value( $automation_contact );
		$operator   = $rule['operator'] ?? 'is';
		$rule_value = $rule['value'] ?? '';

		if ( is_array( $rule_value ) && isset( $rule_value['value'] ) ) {
			$rule_value = $rule_value['value'];
		}
		if ( is_object( $rule_value ) && isset( $rule_value->value ) ) {
			$rule_value = $rule_value->value;
		}

		$rule_value = (int) $rule_value;

		switch ( $operator ) {
			case 'is':
			case 'equals':
			case '=':
				return $value === $rule_value;
			case 'is_not':
			case 'not_equals':
			case '!=':
				return $value !== $rule_value;
			case 'greater_than':
			case 'gt':
				return $value > $rule_value;
			case 'lower_than':
			case 'less_than':
			case 'lt':
				return $value < $rule_value;
			case 'greater_than_or_equal':
			case 'gte':
			case 'ge':
			case '>=':
				return $value >= $rule_value;
			case 'lower_than_or_equal':
			case 'less_than_or_equal':
			case 'lte':
			case 'le':
			case '<=':
				return $value <= $rule_value;
			default:
				return false;
		}
	}
}
