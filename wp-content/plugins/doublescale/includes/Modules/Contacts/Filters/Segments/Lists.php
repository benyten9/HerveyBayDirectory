<?php
/**
 * Class Lists
 *
 * This class is responsible for handling the contact class lists rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\Segments;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lists class
 */
class Lists extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Lists';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'lists_segment';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'segments';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'select';

	/**
	 * Is dynamic
	 *
	 * @var bool
	 */
	public $is_dynamic = true;

	/**
	 * Dynamic args
	 *
	 * @var array
	 */
	public $dynamic_args = array(
		'endpoint' => '/doublescale/v1/lists',
		'key'      => 'id',
		'label'    => 'name',
	);

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'               => __( 'Matches', 'doublescale' ),
			'is_not'           => __( 'Does not match', 'doublescale' ),
			'contains'         => __( 'Has', 'doublescale' ),
			'does_not_contain' => __( 'Does not have', 'doublescale' ),
			'is_empty'         => __( 'Is empty', 'doublescale' ),
			'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
		);
	}

	/**
	 * Apply filter
	 *
	 * @since 1.0.0
	 *
	 * @param Builder $query Query.
	 * @param array   $filter Rule.
	 *
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'is';
		$value    = isset( $filter['value'] ) ? $filter['value'] : array();

		if ( empty( $value ) && ! in_array( $operator, array( 'is_empty', 'is_not_empty' ), true ) ) {
			return $query;
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		// The closure passed to whereHas()/whereDoesntHave() receives a plain
		// query Builder, not the BelongsToMany relation, so `wherePivot()` is not
		// available there — calling it emits a literal `pivot` column and the
		// query fails with "Unknown column 'pivot'". Reference the pivot
		// (`status`) column on the relationship table directly, mirroring how the
		// related model's `id` is already qualified below.
		global $wpdb;
		$pivot_status = $wpdb->prefix . 'doublescale_contact_taxonomy_relationship.status';

		// Add where clause
		switch ( $operator ) {
			case 'is':
				foreach ( $value as $list_id ) {
					$query->whereHas(
						'lists',
						function ( $query ) use ( $list_id, $pivot_status ) {
							$query->where( $query->getModel()->getTable() . '.id', $list_id )
								->where( $pivot_status, 'subscribed' );
						}
					);
				}
				break;
			case 'is_not':
				$query->whereDoesntHave(
					'lists',
					function ( $query ) use ( $value, $pivot_status ) {
						$query->whereIn( $query->getModel()->getTable() . '.id', $value )
							->where( $pivot_status, 'subscribed' );
					}
				);
				break;
			case 'contains':
				$query->whereHas(
					'lists',
					function ( $query ) use ( $value, $pivot_status ) {
						$query->whereIn( $query->getModel()->getTable() . '.id', $value )
							->where( $pivot_status, 'subscribed' );
					}
				);
				break;
			case 'does_not_contain':
				$query->whereDoesntHave(
					'lists',
					function ( $query ) use ( $value, $pivot_status ) {
						$query->whereIn( $query->getModel()->getTable() . '.id', $value )
							->where( $pivot_status, 'subscribed' );
					}
				);
				break;
			case 'is_empty':
				$query->whereDoesntHave(
					'lists',
					function ( $query ) use ( $pivot_status ) {
						$query->where( $pivot_status, 'subscribed' );
					}
				);
				break;
			case 'is_not_empty':
				$query->whereHas(
					'lists',
					function ( $query ) use ( $pivot_status ) {
						$query->where( $pivot_status, 'subscribed' );
					}
				);
				break;
		}

		return $query;
	}
}

FiltersManager::instance()->register( new Lists() );
