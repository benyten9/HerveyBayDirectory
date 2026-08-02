<?php
/**
 * Class LastEmailOpen
 *
 * This class is responsible for handling the last email open rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\Activity;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;
use Illuminate\Database\Eloquent\Builder;

/**
 * LastEmailOpen class
 */
class LastEmailOpen extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Last Email Open';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'activity_last_email_open';

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
		$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'before';
		$value    = isset( $filter['value'] ) ? $filter['value'] : '';

		if ( 'within' === $operator && ! is_array( $value ) ) {
			$value = array( $value, $value );
		} elseif ( 'within' !== $operator && is_array( $value ) ) {
			$value = $value[0];
		}

		// Convert string to date
		if ( is_array( $value ) ) {
			$value = array_map(
				function ( $val ) {
					$date = new \DateTime( $val );
					if ( $date ) {
						return $date->format( 'Y-m-d' );
					} else {
						return $val;
					}
				},
				$value
			);
		} else {
			$date = new \DateTime( $value );
			if ( $date ) {
				$value = $date->format( 'Y-m-d' );
			} else {
				return $query;
			}
		}

		switch ( $operator ) {
			case 'before':
				$query->whereHas(
					'campaign_emails',
					function ( $query ) use ( $value ) {
						$query->whereDate( 'opened_at', '<', $value );
					}
				);
				break;
			case 'after':
				$query->whereHas(
					'campaign_emails',
					function ( $query ) use ( $value ) {
						$query->whereDate( 'opened_at', '>', $value );
					}
				);
				break;
			case 'on':
				$query->whereHas(
					'campaign_emails',
					function ( $query ) use ( $value ) {
						$query->whereDate( 'opened_at', $value );
					}
				);
				break;
			case 'between':
				$query->whereHas(
					'campaign_emails',
					function ( $query ) use ( $value ) {
						$query->whereBetween( 'opened_at', $value );
					}
				);
				break;
			case 'within':
				if ( ! is_array( $value ) || count( $value ) !== 2 ) {
					return $query;
				}
				$query->whereHas(
					'campaign_emails',
					function ( $query ) use ( $value ) {
						$query->whereDate( 'opened_at', '>=', $value[0] )
						->whereDate( 'opened_at', '<=', $value[1] );
					}
				);
				break;
		}

		return $query;
	}
}

FiltersManager::instance()->register( new LastEmailOpen() );
