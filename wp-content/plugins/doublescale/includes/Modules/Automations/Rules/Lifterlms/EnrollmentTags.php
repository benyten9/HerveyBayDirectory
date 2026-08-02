<?php

/**
 * Class EnrollmentTags
 *
 * This class is responsible for handling the enrollment tags rule for LifterLMS
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
 * Enrollment Tags class
 */
class EnrollmentTags extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Enrollment Tags';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'lifterlms_enrollment_tags';

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
			'matches_any_of' => __( 'Matches any of', 'doublescale' ),
			'matches_all_of' => __( 'Matches all of', 'doublescale' ),
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

		$tags = get_terms(
			array(
				'taxonomy'   => 'course_tag',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $tags ) || empty( $tags ) ) {
			return $options;
		}

		foreach ( $tags as $tag ) {
			if ( isset( $tag->term_id ) && isset( $tag->name ) ) {
				$options[ $tag->term_id ] = wp_kses_post( $tag->name );
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
	 * @return array Array of tag IDs from enrolled courses
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

		$enrolled_course_tags = array();

		if ( function_exists( 'llms_get_student' ) ) {
			$student = llms_get_student( $user->ID );
			if ( $student ) {
				$courses = $student->get_courses(
					array(
						'limit'  => 10000,
						'status' => 'enrolled',
					)
				);
				if ( ! empty( $courses['results'] ) ) {
					foreach ( $courses['results'] as $course_id ) {
						$course_tags = get_the_terms( $course_id, 'course_tag' );

						if ( ! is_wp_error( $course_tags ) && ! empty( $course_tags ) ) {
							foreach ( $course_tags as $tag ) {
								if ( isset( $tag->term_id ) ) {
									$enrolled_course_tags[] = (int) $tag->term_id;
								}
							}
						}
					}
				}
			}
		}

		return array_unique( array_map( 'intval', $enrolled_course_tags ) );
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
		$enrolled_tags = $this->get_value( $automation_contact );
		$operator      = $rule['operator'] ?? '';
		$rule_tags     = $rule['value'] ?? array();

		if ( ! is_array( $rule_tags ) ) {
			$rule_tags = array();
		}

		$rule_tags = array_map( 'intval', $rule_tags );

		switch ( $operator ) {
			case 'matches_any_of':
				return ! empty( array_intersect( $enrolled_tags, $rule_tags ) );

			case 'matches_all_of':
				return empty( array_diff( $rule_tags, $enrolled_tags ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( \doublescale_is_plugin_active( 'lifterlms/lifterlms.php' ) ) {
			RulesManager::instance()->register( new EnrollmentTags() );
		}
	},
	99
);
