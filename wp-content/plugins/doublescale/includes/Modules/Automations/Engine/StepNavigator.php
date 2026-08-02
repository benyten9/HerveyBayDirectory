<?php
/**
 * Resolves the next active step in an automation graph (root and branch-aware).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Engine;

defined( 'ABSPATH' ) || exit;

final class StepNavigator {

	/**
	 * @param \DoubleScale\Modules\Automations\Models\AutomationModel|object $automation Automation with steps() relation (tests may pass a lightweight fake).
	 * @param object                                                         $step       Step row (parent_id, order, condition, id).
	 * @return object|null
	 */
	public static function get_next_step( $automation, $step ) {
		if ( 0 == $step->parent_id ) {
			return $automation->steps()
				->where( 'status', 'active' )
				->where( 'parent_id', 0 )
				->where( 'order', '>', $step->order )
				->orderBy( 'order', 'asc' )
				->first();
		}

		$next_step = $automation->steps()
			->where( 'status', 'active' )
			->where( 'parent_id', $step->parent_id )
			->where( 'condition', $step->condition )
			->where( 'order', '>', $step->order )
			->orderBy( 'order', 'asc' )
			->first();

		if ( ! $next_step ) {
			$parent_step = $automation->steps()
				->where( 'id', $step->parent_id )
				->first();

			if ( $parent_step ) {
				if ( $parent_step->parent_id > 0 ) {
					$next_step = $automation->steps()
						->where( 'status', 'active' )
						->where( 'parent_id', $parent_step->parent_id )
						->where( 'condition', $parent_step->condition )
						->where( 'order', '>', $parent_step->order )
						->orderBy( 'order', 'asc' )
						->first();
				} else {
					$next_step = $automation->steps()
						->where( 'status', 'active' )
						->where( 'parent_id', 0 )
						->where( 'order', '>', $parent_step->order )
						->orderBy( 'order', 'asc' )
						->first();
				}
			}
		}

		return $next_step;
	}
}
