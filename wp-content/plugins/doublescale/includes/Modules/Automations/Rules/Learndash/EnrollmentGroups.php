<?php

/**
 * Class EnrollmentGroups
 *
 * This class is responsible for handling the enrollment groups rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Learndash;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Enrollment Groups class
 */
class EnrollmentGroups extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Enrollment Groups';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'learndash_enrollment_groups';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'learndash';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'multiselect';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'includes'         => __( 'includes', 'doublescale' ),
			'not_includes_in'  => __( 'Does not include (in any)', 'doublescale' ),
			'includes_all'     => __( 'includes all', 'doublescale' ),
			'not_includes_all' => __( 'includes none of (match all)', 'doublescale' ),
		);
	}

	public function get_options() {
		$options = array();

		if ( function_exists( 'learndash_get_groups' ) ) {
			$groups = learndash_get_groups();

			if ( is_object( $groups ) && isset( $groups->posts ) ) {
				$groups = $groups->posts;
			}
		} else {
			// Fallback if LearnDash is not available
			$groups = get_posts(
				array(
					'post_type'   => 'groups',
					'numberposts' => -1,
					'post_status' => 'publish',
				)
			);
		}

		if ( empty( $groups ) || ! is_array( $groups ) ) {
			return $options;
		}

		foreach ( $groups as $group ) {
			if ( is_object( $group ) ) {
				$id    = isset( $group->ID ) ? (int) $group->ID : 0;
				$title = get_the_title( $id );
			} elseif ( is_array( $group ) ) {
				$id    = isset( $group['ID'] ) ? (int) $group['ID'] : 0;
				$title = get_post_field( 'post_title', $id );
			} else {
				continue;
			}

			if ( $id ) {
				$options[ $id ] = wp_kses_post( $title );
			}
		}

		return $options;
	}



	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return array Array of enrolled group IDs
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return array();
		}

		// Get user by email
		$user = get_user_by( 'email', $contact->email );
		if ( ! $user ) {
			return array();
		}

		$enrolled_groups = array();

		// Get enrolled groups using LearnDash function if available
		if ( function_exists( 'learndash_get_users_group_ids' ) ) {
			$enrolled_groups = learndash_get_users_group_ids( $user->ID );
		} else {
			// Fallback: Get groups from user meta
			$enrolled_groups = get_user_meta( $user->ID, 'learndash_group_users_' . $user->ID, true );
			if ( ! is_array( $enrolled_groups ) ) {
				$enrolled_groups = array();
			}
		}

		// Ensure we return an array of integers
		return array_map( 'intval', array_filter( $enrolled_groups ) );
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
		$enrolled_groups = $this->get_value( $automation_contact );
		$operator        = $rule['operator'] ?? '';
		$rule_groups     = $rule['value'] ?? array();

		// Ensure rule_groups is an array
		if ( ! is_array( $rule_groups ) ) {
			$rule_groups = array();
		}

		// Convert to integers for comparison
		$rule_groups = array_map( 'intval', $rule_groups );

		switch ( $operator ) {
			case 'includes':
				// User is enrolled in at least one of the specified groups
				return ! empty( array_intersect( $enrolled_groups, $rule_groups ) );

			case 'not_includes_in':
				// User is not enrolled in any of the specified groups
				return empty( array_intersect( $enrolled_groups, $rule_groups ) );

			case 'includes_all':
				// User is enrolled in all of the specified groups
				return empty( array_diff( $rule_groups, $enrolled_groups ) );

			case 'not_includes_all':
				// User is not enrolled in all of the specified groups (missing at least one)
				return ! empty( array_diff( $rule_groups, $enrolled_groups ) );

			default:
				return false;
		}
	}
}



add_action(
	'init',
	function () {
		if ( class_exists( 'SFWD_LMS' ) ) {
			RulesManager::instance()->register( new EnrollmentGroups() );
		} else {
			add_action(
				'learndash_loaded',
				function () {
					RulesManager::instance()->register( new EnrollmentGroups() );
				}
			);
		}
	},
	99
);
