<?php

/**
 * Trait EventCountConditionFilter
 *
 * This trait provides shared event count condition filtering functionality for rules
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * EventCountConditionFilter trait
 */
trait EventCountConditionFilter {

	/**
	 * Check if event count condition is met
	 *
	 * @since 1.0.0
	 *
	 * @param int   $actual_count The actual count from the query.
	 * @param array $event_count_condition Event count condition data.
	 *
	 * @return bool
	 */
	protected function check_event_count_condition( $actual_count, $event_count_condition ) {
		if ( empty( $event_count_condition ) ) {
			$event_count_condition = array(
				'type'  => 'extactly',
				'count' => 1,
			);
		} else {
			$event_count_condition = array(
				'type'  => $event_count_condition['type'] ?? 'extactly',
				'count' => $event_count_condition['count'] ?? 1,
			);
		}

		$type  = $event_count_condition['type'] ?? 'extactly';
		$count = isset( $event_count_condition['count'] ) ? intval( $event_count_condition['count'] ) : 1;

		switch ( $type ) {
			case 'extactly': // Note: typo in frontend, keeping for consistency
			case 'exactly':
				return $actual_count === $count;

			case 'less_than':
				return $actual_count < $count;

			case 'more_than':
				return $actual_count > $count;

			case 'at_least':
				return $actual_count >= $count;

			case 'at_most':
				return $actual_count <= $count;

			default:
				return true;
		}
	}
}
