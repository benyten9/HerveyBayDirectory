<?php

/**
 * Class EmailOpened
 *
 * This class is responsible for handling the email opened filter
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
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * EmailOpened class
 */
class EmailOpened extends Filter {

	use TimeframeContactFilter;

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Email Opened';

	/**
	 * Activity type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $activity_type = 'email_opened';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'activity_email_opened';

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
	public $type = 'email_opened';

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
		return array(
			'is'                       => 'Is',
			'is_not'                   => 'Is not',
			'greater_than'             => 'Greater than',
			'lower_than'               => 'Lower than',
			'lower_than_or_equal_to'   => 'Lower than or equal to',
			'greater_than_or_equal_to' => 'Greater than or equal to',
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

		$value    = $filter['value'] ?? array();
		$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'is';

		$timeframe_data = $value['timeframe'] ?? array( 'type' => 'at_any_time' );
		$count_value    = isset( $value['count'] ) ? intval( $value['count'] ) : 0;

		$table_tracking = $wpdb->prefix . 'doublescale_communication_tracking';
		$table_contacts = $wpdb->prefix . 'doublescale_contacts';

		$sql_timeframe = $this->build_timeframe_sql( $timeframe_data, 'opened_at' );
		$time_bindings = $this->get_timeframe_bindings( $timeframe_data );
		$operator_sql  = $this->get_sql_operator( $operator );

		$query->whereRaw(
			"(
			SELECT COUNT(*)
			FROM {$table_tracking}
			WHERE {$table_tracking}.contact_id = {$table_contacts}.id
			AND {$table_tracking}.mode = ?
			AND {$table_tracking}.opened = 1
			{$sql_timeframe}
		) {$operator_sql} ?",
			array_merge(
				array( CommunicationTrackingModel::MODE_EMAIL ),
				$time_bindings,
				array( $count_value )
			)
		);

		return $query;
	}
}

FiltersManager::instance()->register( new EmailOpened() );
