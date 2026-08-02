<?php

/**
 * Class FirstEnrollmentDate
 *
 * This class is responsible for handling the first enrollment date rule for LearnPress
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Learnpress;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * First Enrollment Date class
 */
class FirstEnrollmentDate extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'First Enrollment Date';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'learnpress_first_enrollment_date';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'learnpress';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'date';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'before'  => __( 'Before', 'doublescale' ),
			'after'   => __( 'After', 'doublescale' ),
			'on'      => __( 'On', 'doublescale' ),
			'between' => __( 'Between', 'doublescale' ),
			'within'  => __( 'Within', 'doublescale' ),
		);
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return string|null First enrollment date in Y-m-d format or null if no enrollments
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return null;
		}

		$user = get_user_by( 'email', $contact->email );
		if ( ! $user ) {
			return null;
		}

		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'learnpress_user_items' );

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return null;
		}

		// Get the earliest enrollment date.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is the LearnPress-prefixed user_items table; user id bound via prepare().
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(start_time) FROM {$table_name} WHERE user_id = %d AND item_type = 'lp_course' AND status = 'enrolled'",
				$user->ID
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $result ) {
			return gmdate( 'Y-m-d', strtotime( $result ) );
		}

		return null;
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
		$first_enrollment_date = $this->get_value( $automation_contact );
		$operator              = $rule['operator'] ?? '';
		$rule_value            = $rule['value'] ?? '';

		if ( empty( $first_enrollment_date ) ) {
			return false;
		}

		$enrollment_timestamp = strtotime( $first_enrollment_date );

		switch ( $operator ) {
			case 'before':
				$rule_timestamp = strtotime( $rule_value );
				return $enrollment_timestamp < $rule_timestamp;

			case 'after':
				$rule_timestamp = strtotime( $rule_value );
				return $enrollment_timestamp > $rule_timestamp;

			case 'on':
				$rule_timestamp = strtotime( $rule_value );
				return gmdate( 'Y-m-d', $enrollment_timestamp ) === gmdate( 'Y-m-d', $rule_timestamp );

			case 'between':
				if ( ! is_array( $rule_value ) || count( $rule_value ) < 2 ) {
					return false;
				}
				$start_timestamp = strtotime( $rule_value[0] );
				$end_timestamp   = strtotime( $rule_value[1] );
				return $enrollment_timestamp >= $start_timestamp && $enrollment_timestamp <= $end_timestamp;

			case 'within':
				if ( ! is_numeric( $rule_value ) ) {
					return false;
				}
				$days_ago         = (int) $rule_value;
				$cutoff_timestamp = strtotime( "-{$days_ago} days" );
				return $enrollment_timestamp >= $cutoff_timestamp;

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( \doublescale_is_plugin_active( 'learnpress/learnpress.php' ) ) {
			RulesManager::instance()->register( new FirstEnrollmentDate() );
		}
	},
	99
);
