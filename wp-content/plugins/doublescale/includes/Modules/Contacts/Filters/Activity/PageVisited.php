<?php

/**
 * Class PageVisited
 *
 * This class is responsible for handling the page visited filter
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
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * PageVisited class
 */
class PageVisited extends Filter {

	use TimeframeContactFilter;

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Page Visited';

	/**
	 * Activity type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $activity_type = 'page_visited';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'activity_page_visited';

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
	public $type = 'page_visited';

	/**
	 * Is automation
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
		if ( ! isset( $GLOBALS['wp_rewrite'] ) ) {
			global $wp_rewrite;
			$GLOBALS['wp_rewrite'] = $wp_rewrite ?? new \WP_Rewrite();
		}
		$pages   = get_pages();
		$options = array();
		foreach ( $pages as $page ) {
			$options[ $page->guid ] = empty( $page->post_title ) ? '(Page)' : $page->post_title;
		}
		return $options;
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

		// Defense-in-depth: skip if the Pro-owned tracking table isn't present
		// (Free-standalone install, or websitetracking never bootstrapped).
		$table_activities = $wpdb->prefix . 'doublescale_page_visits';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot existence check; caching would mask DDL state.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_activities ) ) !== $table_activities ) {
			return $query;
		}

		$value = $filter['value'] ?? array();

		$page_guid      = $this->page_address( (string) ( $value['guid'] ?? '' ) );
		$timeframe_data = $value['timeframe'] ?? array( 'type' => 'at_any_time' );

		$event_count_condition = $value['event_count_condition'] ?? array(
			'type'  => 'exactly',
			'count' => 1,
		);

		$count_type     = $event_count_condition['type'] ?? 'exactly';
		$expected_count = intval( $event_count_condition['count'] ?? 1 );

		$operator = $this->get_comparison_operator( $count_type );

		$table_contacts = $wpdb->prefix . 'doublescale_contacts';

		$sql_timeframe = $this->build_timeframe_sql( $timeframe_data );
		$time_bindings = $this->get_timeframe_bindings( $timeframe_data );
		$sql_page_guid = $this->build_page_guid_sql( $page_guid );
		$page_bindings = $this->get_page_guid_bindings( $page_guid );

		$query->whereRaw(
			"(
			SELECT COUNT(*)
			FROM {$table_activities}
			WHERE {$table_activities}.contact_id = {$table_contacts}.id
			{$sql_page_guid}
			{$sql_timeframe}
		) {$operator} ?",
			array_merge(
				$page_bindings,
				$time_bindings,
				array( $expected_count )
			)
		);

		return $query;
	}

	/**
	 * Where a page is visited today, for a page picked from the list.
	 *
	 * The list offers each page's guid, which is fixed when the page is created
	 * — usually ?page_id=N under plain permalinks — while visits are recorded at
	 * the address the visitor opened (/support/). Such a page matched no visit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $page_guid Page GUID from the rule.
	 *
	 * @return string The page's permalink, or the GUID when it is not a page here.
	 */
	protected function page_address( $page_guid ) {
		if ( '' === $page_guid ) {
			return '';
		}

		global $wp_rewrite;

		// url_to_postid() reads the rewrite rules for a pretty address, and they
		// only exist once WordPress has set up the theme; the guid lookup below
		// covers both forms without them.
		$post_id = $wp_rewrite instanceof \WP_Rewrite ? url_to_postid( $page_guid ) : 0;
		if ( ! $post_id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup per rule.
			$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE guid = %s LIMIT 1", $page_guid ) );
		}

		$permalink = $post_id ? get_permalink( $post_id ) : false;

		return $permalink ? $permalink : $page_guid;
	}

	/**
	 * Build page guid SQL condition
	 *
	 * @since 1.0.0
	 *
	 * @param string $page_guid Page GUID.
	 *
	 * @return string
	 */
	protected function build_page_guid_sql( $page_guid ) {
		if ( empty( $page_guid ) ) {
			return '';
		}

		$query_string = wp_parse_url( $page_guid, PHP_URL_QUERY );

		if ( ! empty( $query_string ) ) {
			return ' AND path = ? AND query = ?';
		}

		return ' AND path = ?';
	}

	/**
	 * Get page guid bindings
	 *
	 * @since 1.0.0
	 *
	 * @param string $page_guid Page GUID.
	 *
	 * @return array
	 */
	protected function get_page_guid_bindings( $page_guid ) {
		if ( empty( $page_guid ) ) {
			return array();
		}

		$path         = wp_parse_url( $page_guid, PHP_URL_PATH );
		$query_string = wp_parse_url( $page_guid, PHP_URL_QUERY );

		if ( ! empty( $query_string ) ) {
			return array( $path, $query_string );
		}

		return array( $path );
	}
}
