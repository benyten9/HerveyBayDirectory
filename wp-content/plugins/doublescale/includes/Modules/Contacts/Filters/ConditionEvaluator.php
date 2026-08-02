<?php
/**
 * Condition Evaluator
 *
 * Evaluates contact filter conditions in-memory without database queries.
 * This is much faster than using Contact_Filters\Process for single contacts.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Contact_Filters
 */

namespace DoubleScale\Modules\Contacts\Filters;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * ConditionEvaluator class
 *
 * Provides in-memory evaluation of filter conditions for a contact.
 * Used by EmailRenderer and EmailProcessing for conditional sections.
 *
 * @since 1.0.0
 */
class ConditionEvaluator {

	/**
	 * Singleton instance
	 *
	 * @var ConditionEvaluator|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return ConditionEvaluator
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Evaluate conditions for a contact in-memory (no database queries for common filters)
	 *
	 * Supports the nested filter format: outer array = OR groups, inner arrays = AND conditions.
	 * Contact matches if ANY OR group is fully satisfied (all AND conditions in that group match).
	 *
	 * @since 1.0.0
	 *
	 * @param array        $conditions Nested conditions array
	 * @param ContactModel $contact    Contact model
	 *
	 * @return bool True if contact matches conditions
	 */
	public function evaluate( array $conditions, ContactModel $contact ) {
		if ( empty( $conditions ) ) {
			return true;
		}

		// Conditions are nested: outer = OR groups, inner = AND conditions
		foreach ( $conditions as $and_group ) {
			if ( ! is_array( $and_group ) || empty( $and_group ) ) {
				continue;
			}

			// Check if all conditions in this AND group match
			$group_matches = true;
			foreach ( $and_group as $condition ) {
				if ( ! is_array( $condition ) ) {
					continue;
				}

				if ( ! $this->evaluate_single_condition( $condition, $contact ) ) {
					$group_matches = false;
					break; // One condition failed, whole AND group fails
				}
			}

			// If this AND group fully matches, contact passes (OR logic)
			if ( $group_matches ) {
				return true;
			}
		}

		// No OR group fully matched
		return false;
	}

	/**
	 * Evaluate a single condition against a contact in-memory
	 *
	 * @since 1.0.0
	 *
	 * @param array        $condition Single condition array
	 * @param ContactModel $contact   Contact model
	 *
	 * @return bool True if condition matches
	 */
	public function evaluate_single_condition( array $condition, ContactModel $contact ) {
		// Handle RuleItem format (from RulesBuilder / REST): rule + selectedGroup or "group" alias.
		if ( isset( $condition['rule'] ) && ( isset( $condition['selectedGroup'] ) || isset( $condition['group'] ) ) ) {
			$group     = isset( $condition['selectedGroup'] ) ? $condition['selectedGroup'] : $condition['group'];
			$condition = array(
				'filter'   => $condition['rule'],
				'group'    => $group,
				'operator' => $condition['operator'] ?? 'is',
				'value'    => $condition['value'] ?? '',
			);
		}

		$filter_type = $condition['filter'] ?? '';
		$operator    = $condition['operator'] ?? 'is';
		$value       = $condition['value'] ?? '';

		// Map common filter types to contact fields for in-memory evaluation
		switch ( $filter_type ) {
			// Contact field filters
			case 'first_name':
			case 'first_name_contact':
			case 'contact_first_name':
				return $this->compare_value( $contact->first_name ?? '', $operator, $value );

			case 'last_name':
			case 'last_name_contact':
			case 'contact_last_name':
				return $this->compare_value( $contact->last_name ?? '', $operator, $value );

			case 'email':
			case 'email_contact':
			case 'contact_email':
				return $this->compare_value( $contact->email ?? '', $operator, $value );

			case 'whatsapp':
			case 'whatsapp_contact':
			case 'contact_whatsapp':
				return $this->compare_value( $contact->whatsapp ?? '', $operator, $value );

			case 'city':
			case 'city_contact':
			case 'contact_city':
				return $this->compare_value( $contact->city ?? '', $operator, $value );

			case 'state':
			case 'state_contact':
			case 'contact_state':
				return $this->compare_value( $contact->state ?? '', $operator, $value );

			case 'country':
			case 'country_contact':
			case 'contact_country':
				return $this->compare_value( $contact->country ?? '', $operator, $value );

			case 'zip':
			case 'zip_contact':
			case 'contact_zip':
				return $this->compare_value( $contact->zip ?? '', $operator, $value );

			case 'address_1':
			case 'address_1_contact':
			case 'contact_address_1':
				return $this->compare_value( $contact->address_1 ?? '', $operator, $value );

			case 'address_2':
			case 'address_2_contact':
			case 'contact_address_2':
				return $this->compare_value( $contact->address_2 ?? '', $operator, $value );

			case 'status':
			case 'status_contact':
			case 'contact_status':
				return $this->compare_value( $contact->status ?? '', $operator, $value );

			// Segment filters (lists/tags) - need to check relationships
			case 'lists_segment':
				return $this->evaluate_list_condition( $contact, $operator, $value );

			case 'tags_segment':
				return $this->evaluate_tag_condition( $contact, $operator, $value );

			// User filters
			case 'is_user':
				return $this->evaluate_is_user_condition( $contact, $operator );

			case 'user_role':
				return $this->evaluate_user_role_condition( $contact, $operator, $value );

			// Lead scoring filters
			case 'lead_score_points':
				if ( ! class_exists( \DoubleScale\Modules\LeadScoring\LeadScoringManager::class, true ) ) {
					return false;
				}
				if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'leadscoring' ) ) {
					return false;
				}
				return $this->evaluate_lead_score_points_condition( $contact, $operator, $value );

			case 'lead_score_level':
				if ( ! class_exists( \DoubleScale\Modules\LeadScoring\LeadScoringManager::class, true ) ) {
					return false;
				}
				if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'leadscoring' ) ) {
					return false;
				}
				return $this->evaluate_lead_score_level_condition( $contact, $operator, $value );

			// For complex filters that can't be evaluated in-memory, fall back to database query
			default:
				return $this->evaluate_via_database( $condition, $contact );
		}
	}

	/**
	 * Compare a value using the specified operator
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $contact_value    Value from contact
	 * @param string $operator         Comparison operator
	 * @param mixed  $condition_value  Value to compare against
	 *
	 * @return bool
	 */
	public function compare_value( $contact_value, $operator, $condition_value ) {
		$contact_value = strtolower( trim( (string) $contact_value ) );

		// Handle array values (for 'is'/'is_not' with multiple options)
		if ( is_array( $condition_value ) ) {
			$condition_values = array_map(
				function ( $v ) {
					return strtolower( trim( (string) $v ) );
				},
				$condition_value
			);
		} else {
			$condition_value = strtolower( trim( (string) $condition_value ) );
		}

		switch ( $operator ) {
			case 'is':
			case 'equals':
			case '=':
				if ( is_array( $condition_value ) ) {
					return in_array( $contact_value, $condition_values, true );
				}
				return $contact_value === $condition_value;

			case 'is_not':
			case 'not_equals':
			case '!=':
				if ( is_array( $condition_value ) ) {
					return ! in_array( $contact_value, $condition_values, true );
				}
				return $contact_value !== $condition_value;

			case 'contains':
				return strpos( $contact_value, $condition_value ) !== false;

			case 'does_not_contain':
			case 'not_contains':
				return strpos( $contact_value, $condition_value ) === false;

			case 'starts_with':
				return strpos( $contact_value, $condition_value ) === 0;

			case 'ends_with':
				return substr( $contact_value, -strlen( $condition_value ) ) === $condition_value;

			case 'is_empty':
			case 'empty':
				return empty( $contact_value );

			case 'is_not_empty':
			case 'not_empty':
				return ! empty( $contact_value );

			case 'greater_than':
			case '>':
				return (float) $contact_value > (float) $condition_value;

			case 'less_than':
			case 'lower_than':
			case '<':
				return (float) $contact_value < (float) $condition_value;

			case 'greater_than_or_equal':
			case '>=':
				return (float) $contact_value >= (float) $condition_value;

			case 'less_than_or_equal':
			case '<=':
				return (float) $contact_value <= (float) $condition_value;

			default:
				// Unknown operator - default to equality check
				return $contact_value === $condition_value;
		}
	}

	/**
	 * Evaluate list membership condition
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact  Contact model
	 * @param string       $operator Operator (is/is_not/contains/does_not_contain/is_empty/is_not_empty)
	 * @param mixed        $value    List ID(s)
	 *
	 * @return bool
	 */
	public function evaluate_list_condition( ContactModel $contact, $operator, $value ) {
		// Get contact's list IDs (eager load if not loaded)
		if ( ! $contact->relationLoaded( 'lists' ) ) {
			$contact->load( 'lists' );
		}

		$contact_list_ids = $contact->lists->pluck( 'id' )->toArray();

		// Handle is_empty/is_not_empty operators first (don't need value)
		switch ( $operator ) {
			case 'is_empty':
				return empty( $contact_list_ids );

			case 'is_not_empty':
				return ! empty( $contact_list_ids );
		}

		// For other operators, we need a value
		if ( empty( $value ) ) {
			return false;
		}

		$check_list_ids = is_array( $value ) ? array_map( 'intval', $value ) : array( intval( $value ) );

		switch ( $operator ) {
			case 'is':
				// Contact must be in ALL of the specified lists.
				foreach ( $check_list_ids as $list_id ) {
					if ( ! in_array( $list_id, $contact_list_ids, true ) ) {
						return false;
					}
				}
				return true;

			case 'contains':
				// Contact must be in ANY of the specified lists.
				foreach ( $check_list_ids as $list_id ) {
					if ( in_array( $list_id, $contact_list_ids, true ) ) {
						return true;
					}
				}
				return false;

			case 'is_not':
			case 'does_not_contain':
				// Contact must NOT be in ANY of the specified lists.
				foreach ( $check_list_ids as $list_id ) {
					if ( in_array( $list_id, $contact_list_ids, true ) ) {
						return false;
					}
				}
				return true;

			default:
				return false;
		}
	}

	/**
	 * Evaluate tag membership condition
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact  Contact model
	 * @param string       $operator Operator (is/is_not/contains/does_not_contain/is_empty/is_not_empty)
	 * @param mixed        $value    Tag ID(s)
	 *
	 * @return bool
	 */
	public function evaluate_tag_condition( ContactModel $contact, $operator, $value ) {
		// Get contact's tag IDs (eager load if not loaded)
		if ( ! $contact->relationLoaded( 'tags' ) ) {
			$contact->load( 'tags' );
		}

		$contact_tag_ids = $contact->tags->pluck( 'id' )->toArray();

		// Handle is_empty/is_not_empty operators first (don't need value)
		switch ( $operator ) {
			case 'is_empty':
				return empty( $contact_tag_ids );

			case 'is_not_empty':
				return ! empty( $contact_tag_ids );
		}

		// For other operators, we need a value
		if ( empty( $value ) ) {
			return false;
		}

		$check_tag_ids = is_array( $value ) ? array_map( 'intval', $value ) : array( intval( $value ) );

		switch ( $operator ) {
			case 'is':
				// Contact must have ALL of the specified tags.
				foreach ( $check_tag_ids as $tag_id ) {
					if ( ! in_array( $tag_id, $contact_tag_ids, true ) ) {
						return false;
					}
				}
				return true;

			case 'contains':
				// Contact must have ANY of the specified tags.
				foreach ( $check_tag_ids as $tag_id ) {
					if ( in_array( $tag_id, $contact_tag_ids, true ) ) {
						return true;
					}
				}
				return false;

			case 'is_not':
			case 'does_not_contain':
				// Contact must NOT have ANY of the specified tags.
				foreach ( $check_tag_ids as $tag_id ) {
					if ( in_array( $tag_id, $contact_tag_ids, true ) ) {
						return false;
					}
				}
				return true;

			default:
				return false;
		}
	}

	/**
	 * Evaluate is_user condition (checks if contact has associated WordPress user)
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact  Contact model
	 * @param string       $operator Operator (is/is_not)
	 *
	 * @return bool
	 */
	public function evaluate_is_user_condition( ContactModel $contact, $operator ) {
		// Get contact's user relationship (eager load if not loaded)
		if ( ! $contact->relationLoaded( 'user' ) ) {
			$contact->load( 'user' );
		}

		$has_user = ! is_null( $contact->user );

		switch ( $operator ) {
			case 'is':
				return $has_user;

			case 'is_not':
				return ! $has_user;

			default:
				return false;
		}
	}

	/**
	 * Evaluate user_role condition
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact  Contact model
	 * @param string       $operator Operator (is/is_not)
	 * @param mixed        $value    Role(s) to check
	 *
	 * @return bool
	 */
	public function evaluate_user_role_condition( ContactModel $contact, $operator, $value ) {
		// Get contact's user relationship (eager load if not loaded)
		if ( ! $contact->relationLoaded( 'user' ) ) {
			$contact->load( 'user' );
		}

		// If no user associated, contact doesn't have any role
		if ( is_null( $contact->user ) ) {
			return $operator === 'is_not';
		}

		// Ensure value is an array
		if ( empty( $value ) ) {
			return false;
		}

		$check_roles = is_array( $value ) ? $value : array( $value );

		// Get the WP_User object
		$wp_user = get_user_by( 'id', $contact->user->ID );
		if ( ! $wp_user ) {
			return $operator === 'is_not';
		}

		// Check if user has any of the specified roles
		$has_role = false;
		foreach ( $check_roles as $role ) {
			if ( in_array( $role, (array) $wp_user->roles, true ) ) {
				$has_role = true;
				break;
			}
		}

		switch ( $operator ) {
			case 'is':
				return $has_role;

			case 'is_not':
				return ! $has_role;

			default:
				return false;
		}
	}

	/**
	 * Evaluate lead_score_points condition
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact  Contact model
	 * @param string       $operator Operator (is/is_not/greater_than/lower_than)
	 * @param mixed        $value    Points value to compare
	 *
	 * @return bool
	 */
	public function evaluate_lead_score_points_condition( ContactModel $contact, $operator, $value ) {
		$contact_points = (int) $contact->getMeta( 'lead_score_points', 0 );
		$check_value    = (int) $this->unwrap_rule_scalar_value( $value );
		$operator       = $this->normalize_numeric_comparison_operator( (string) $operator );

		switch ( $operator ) {
			case 'is':
				return $contact_points === $check_value;

			case 'is_not':
				return $contact_points !== $check_value;

			case 'greater_than':
				return $contact_points > $check_value;

			case 'lower_than':
			case 'less_than':
				return $contact_points < $check_value;

			case 'greater_than_or_equal':
				return $contact_points >= $check_value;

			case 'lower_than_or_equal':
			case 'less_than_or_equal':
				return $contact_points <= $check_value;

			default:
				return false;
		}
	}

	/**
	 * Evaluate lead_score_level condition
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact  Contact model
	 * @param string       $operator Operator (is/is_not/is_empty/is_not_empty)
	 * @param mixed        $value    Level ID(s) to check
	 *
	 * @return bool
	 */
	public function evaluate_lead_score_level_condition( ContactModel $contact, $operator, $value ) {
		$value    = $this->unwrap_rule_scalar_value( $value );
		$operator = (string) $operator;
		$op_map   = array(
			'equals'     => 'is',
			'='          => 'is',
			'not_equals' => 'is_not',
			'!='         => 'is_not',
		);
		if ( isset( $op_map[ $operator ] ) ) {
			$operator = $op_map[ $operator ];
		}

		$raw_level = $contact->getMeta( 'lead_score_level_id', '' );
		// Meta may be int, string, or empty
		$contact_level = ( null === $raw_level || false === $raw_level || '' === $raw_level )
			? ''
			: (string) (int) $raw_level;

		// Handle is_empty/is_not_empty operators first
		switch ( $operator ) {
			case 'is_empty':
				return '' === $contact_level;

			case 'is_not_empty':
				return '' !== $contact_level;
		}

		$check_levels = is_array( $value ) ? $value : array( $value );

		// Normalise to level IDs — meta stores numeric IDs but rules may carry slugs.
		$check_ids = array();
		foreach ( $check_levels as $v ) {
			$v = $this->unwrap_rule_scalar_value( $v );
			if ( null === $v || '' === $v ) {
				continue;
			}
			if ( is_numeric( $v ) ) {
				$check_ids[] = (string) (int) $v;
				continue;
			}
			if ( class_exists( '\DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel' ) ) {
				$by_slug = \DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel::get_by_slug( (string) $v );
				if ( $by_slug ) {
					$check_ids[] = (string) (int) $by_slug->id;
				}
			}
		}

		if ( empty( $check_ids ) ) {
			return false;
		}

		switch ( $operator ) {
			case 'is':
			case 'equals':
			case '=':
				return in_array( $contact_level, $check_ids, true );

			case 'is_not':
			case 'not_equals':
			case '!=':
				return ! in_array( $contact_level, $check_ids, true );

			default:
				return false;
		}
	}

	/**
	 * Unwrap values produced by some UIs / JSON (e.g. { "value": 13 }).
	 *
	 * @param mixed $value Raw condition value.
	 * @return mixed
	 */
	private function unwrap_rule_scalar_value( $value ) {
		if ( is_array( $value ) ) {
			if ( array_key_exists( 'value', $value ) ) {
				return $this->unwrap_rule_scalar_value( $value['value'] );
			}
		}
		if ( is_object( $value ) && isset( $value->value ) ) {
			return $this->unwrap_rule_scalar_value( $value->value );
		}

		return $value;
	}

	/**
	 * Map common operator aliases to lead-score numeric operators.
	 *
	 * @param string $operator Raw operator slug.
	 * @return string
	 */
	private function normalize_numeric_comparison_operator( string $operator ): string {
		static $map = array(
			'equals'     => 'is',
			'='          => 'is',
			'not_equals' => 'is_not',
			'!='         => 'is_not',
			'gt'         => 'greater_than',
			'lt'         => 'lower_than',
			'gte'        => 'greater_than_or_equal',
			'ge'         => 'greater_than_or_equal',
			'>='         => 'greater_than_or_equal',
			'lte'        => 'lower_than_or_equal',
			'le'         => 'lower_than_or_equal',
			'<='         => 'lower_than_or_equal',
		);

		return $map[ $operator ] ?? $operator;
	}

	/**
	 * Fallback: Evaluate condition via database query for complex filters
	 *
	 * Used for filters that can't be easily evaluated in-memory (e.g., activity-based filters).
	 *
	 * @since 1.0.0
	 *
	 * @param array        $condition Condition array
	 * @param ContactModel $contact   Contact model
	 *
	 * @return bool
	 */
	public function evaluate_via_database( array $condition, ContactModel $contact ) {
		$query           = ContactModel::where( 'id', $contact->id );
		$contact_filters = new Process( $query, array( array( $condition ) ) );
		$filtered_query  = $contact_filters->filter();

		return $filtered_query->exists();
	}

	/**
	 * Pre-load relationships for a collection of contacts
	 *
	 * Call this before evaluating conditions on multiple contacts to avoid N+1 queries.
	 *
	 * @since 1.0.0
	 *
	 * @param \Illuminate\Database\Eloquent\Collection $contacts    Contacts collection
	 * @param array                                    $conditions  Conditions to check (to determine what to load)
	 *
	 * @return void
	 */
	public function preload_relationships( $contacts, array $conditions ) {
		$needs_lists = false;
		$needs_tags  = false;
		$needs_user  = false;

		// Check if conditions use list, tag, or user filters
		foreach ( $conditions as $and_group ) {
			if ( ! is_array( $and_group ) ) {
				continue;
			}

			foreach ( $and_group as $condition ) {
				if ( ! is_array( $condition ) ) {
					continue;
				}

				$filter_type = $condition['filter'] ?? $condition['rule'] ?? '';

				if ( $filter_type === 'lists_segment' ) {
					$needs_lists = true;
				}
				if ( $filter_type === 'tags_segment' ) {
					$needs_tags = true;
				}
				if ( $filter_type === 'is_user' || $filter_type === 'user_role' ) {
					$needs_user = true;
				}

				if ( $needs_lists && $needs_tags && $needs_user ) {
					break 2; // Found all, no need to continue
				}
			}
		}

		// Load needed relationships
		$relations = array();
		if ( $needs_lists ) {
			$relations[] = 'lists';
		}
		if ( $needs_tags ) {
			$relations[] = 'tags';
		}
		if ( $needs_user ) {
			$relations[] = 'user';
		}

		if ( ! empty( $relations ) ) {
			$contacts->load( $relations );
		}
	}
}
