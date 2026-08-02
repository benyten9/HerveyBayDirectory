<?php

/**
 * Class LeadScoreLevel
 *
 * This class is responsible for handling the contact lead score level rule
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
 * Lead Score Level class
 */
class LeadScoreLevel extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Level';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'lead_score_level';

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
	public $type = 'select';

	/**
	 * Is automation rule
	 *
	 * @var boolean
	 *
	 * @since 1.0.0
	 */
	public $is_automation = false;

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		$options = array();

		// Model lives in doublescale-pro only.
		if ( ! class_exists( \DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel::class ) ) {
			return $options;
		}

		// Class-exists guard isn't enough: Pro registers this rule via RulesManager->register()
		// during Module::boot(), which eagerly calls get_options(). On a fresh install (or any
		// env where Pro's migrations haven't run yet) the table doesn't exist and the SELECT
		// fatals the whole site. Catch the QueryException so the rule stays registered with
		// empty options instead of taking down WordPress.
		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready(
				'leadscoring',
				\DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel::class
			) ) {
			return $options;
		}

		try {
			$levels = \DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel::orderBy( 'points', 'asc' )->get();
		} catch ( \Throwable $e ) {
			return $options;
		}

		foreach ( $levels as $level ) {
			$options[ $level->id ] = $level->name . ' (' . $level->points . '+ pts)';
		}

		return $options;
	}


	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return int|null
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact ) {
			return null;
		}

		// Get lead score data from contact meta
		$lead_score_level_id = \doublescale_get_contact_meta( $contact->id, 'lead_score_level_id', true );

		if ( ! $lead_score_level_id ) {
			return null;
		}

		return $lead_score_level_id;
	}

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
		$operator   = $rule['operator'] ?? 'is';
		$rule_value = $rule['value'] ?? '';

		if ( is_array( $rule_value ) && isset( $rule_value['value'] ) ) {
			$rule_value = $rule_value['value'];
		}
		if ( is_object( $rule_value ) && isset( $rule_value->value ) ) {
			$rule_value = $rule_value->value;
		}

		$operator = (string) $operator;
		$map      = array(
			'equals'     => 'is',
			'='          => 'is',
			'not_equals' => 'is_not',
			'!='         => 'is_not',
		);
		if ( isset( $map[ $operator ] ) ) {
			$operator = $map[ $operator ];
		}

		switch ( $operator ) {
			case 'is':
				return (int) $value === (int) $rule_value;
			case 'is_not':
				return (int) $value !== (int) $rule_value;
			default:
				return false;
		}
	}
}
