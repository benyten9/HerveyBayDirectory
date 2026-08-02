<?php

/**
 * Class WasActiveInactive
 *
 * This class is responsible for handling the was active inactive filter
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\Activity;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;
use DoubleScale\Modules\Contacts\Filters\Traits\TimeframeContactFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * WasActiveInactive class
 */
class WasActiveInactive extends Filter {

	use TimeframeContactFilter;

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = '';

	/**
	 * Activity type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $activity_type = '';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = '';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'activity';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'was_active_inactive';

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 *
	 * @param string $name          Filter name.
	 * @param string $activity_type Activity type.
	 * @param string $slug          Filter slug.
	 */
	public function __construct( $name, $activity_type, $slug ) {
		$this->name          = $name;
		$this->activity_type = $activity_type;
		$this->slug          = $slug;
	}

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array();
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
	 * Apply filter
	 *
	 * @since 1.0.0
	 *
	 * @param Builder $query Query.
	 * @param array   $filter Filter.
	 *
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		global $wpdb;

		$value = $filter['value'] ?? array();

		$timeframe_data = $value['timeframe'] ?? array( 'type' => 'at_any_time' );

		$table_activities       = $wpdb->prefix . 'doublescale_activities';
		$table_form_submissions = $wpdb->prefix . 'doublescale_form_submissions';
		$table_page_visits      = $wpdb->prefix . 'doublescale_page_visits';
		$table_contacts         = $wpdb->prefix . 'doublescale_contacts';

		// Defense-in-depth: only sum from tables that exist. Pro-owned tables
		// (`form_submissions`, `page_visits`) may be missing on Free-standalone
		// installs or when their owning modules never bootstrapped.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot existence check; caching would mask DDL state.
		$has_form_submissions = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_form_submissions ) ) === $table_form_submissions;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot existence check; caching would mask DDL state.
		$has_page_visits = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_page_visits ) ) === $table_page_visits;

		$contact_link_sql = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::sql_activity_linked_to_contact_exists(
			'a.id',
			"{$table_contacts}.id"
		);

		$sql_timeframe          = $this->build_timeframe_sql( $timeframe_data );
		$sql_timeframe_activity = $this->build_timeframe_sql( $timeframe_data, 'a.created_at' );
		$time_bindings          = $this->get_timeframe_bindings( $timeframe_data );

		// Determine operator based on slug (was_active = count > 0, was_not_active = count = 0)
		if ( 'activity_was_not_active' === $this->slug ) {
			$operator       = '<=';
			$expected_count = 0;
		} else {
			$operator       = '>';
			$expected_count = 0;
		}

		$sum_parts = array(
			"(SELECT COUNT(*) FROM {$table_activities} a WHERE {$contact_link_sql} {$sql_timeframe_activity})",
		);
		$bindings  = $time_bindings;

		if ( $has_form_submissions ) {
			$sum_parts[] = "(SELECT COUNT(*) FROM {$table_form_submissions} WHERE {$table_form_submissions}.contact_id = {$table_contacts}.id {$sql_timeframe})";
			$bindings    = array_merge( $bindings, $time_bindings );
		}
		if ( $has_page_visits ) {
			$sum_parts[] = "(SELECT COUNT(*) FROM {$table_page_visits} WHERE {$table_page_visits}.contact_id = {$table_contacts}.id {$sql_timeframe})";
			$bindings    = array_merge( $bindings, $time_bindings );
		}

		$bindings[] = $expected_count;

		$query->whereRaw(
			'( ' . implode( ' + ', $sum_parts ) . " ) {$operator} ?",
			$bindings
		);

		return $query;
	}
}
