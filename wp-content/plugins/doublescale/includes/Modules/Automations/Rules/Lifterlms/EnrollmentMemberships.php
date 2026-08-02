<?php

/**
 * Class EnrollmentMemberships
 *
 * This class is responsible for handling the enrollment memberships rule for LifterLMS
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Lifterlms;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Enrollment Memberships class
 */
class EnrollmentMemberships extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Enrollment Memberships';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'lifterlms_enrollment_memberships';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'lifterlms';

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

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		$options = array();

		if ( ! defined( 'LLMS_PLUGIN_FILE' ) ) {
			return $options;
		}

		$memberships = get_posts(
			array(
				'post_type'   => 'llms_membership',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

		if ( empty( $memberships ) || ! is_array( $memberships ) ) {
			return $options;
		}

		foreach ( $memberships as $membership ) {
			if ( is_object( $membership ) ) {
				$id    = isset( $membership->ID ) ? (int) $membership->ID : 0;
				$title = get_the_title( $id );
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
	 * @return array Array of enrolled membership IDs
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return array();
		}

		$user = get_user_by( 'email', $contact->email );
		if ( ! $user ) {
			return array();
		}

		$enrolled_memberships = array();

		if ( function_exists( 'llms_get_student' ) ) {
			$student = llms_get_student( $user->ID );
			if ( $student ) {
				$memberships = $student->get_memberships(
					array(
						'limit'  => 10000,
						'status' => 'enrolled',
					)
				);
				if ( ! empty( $memberships['results'] ) ) {
					$enrolled_memberships = $memberships['results'];
				}
			}
		}

		return array_map( 'intval', array_filter( $enrolled_memberships ) );
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
		$enrolled_memberships = $this->get_value( $automation_contact );
		$operator             = $rule['operator'] ?? '';
		$rule_memberships     = $rule['value'] ?? array();

		if ( ! is_array( $rule_memberships ) ) {
			$rule_memberships = array();
		}

		$rule_memberships = array_map( 'intval', $rule_memberships );

		switch ( $operator ) {
			case 'includes':
				return ! empty( array_intersect( $enrolled_memberships, $rule_memberships ) );

			case 'not_includes_in':
				return empty( array_intersect( $enrolled_memberships, $rule_memberships ) );

			case 'includes_all':
				return empty( array_diff( $rule_memberships, $enrolled_memberships ) );

			case 'not_includes_all':
				return ! empty( array_diff( $rule_memberships, $enrolled_memberships ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( \doublescale_is_plugin_active( 'lifterlms/lifterlms.php' ) ) {
			RulesManager::instance()->register( new EnrollmentMemberships() );
		}
	},
	99
);
