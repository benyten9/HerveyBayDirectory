<?php

/**
 * Class LeadScoreLevel
 *
 * This class is responsible for handling the contact lead score level filter
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\LeadScoring\Filters;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use Illuminate\Database\Eloquent\Builder;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;
use DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel;

/**
 * LeadScoreLevel class
 */
class LeadScoreLevel extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Level';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'lead_score_level';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'lead_scoring';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'select';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale'),
			'is_not' => __( 'Is not', 'doublescale'),
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

		// Boot-time guard: Pro's filter file is required during Module::boot(), which runs
		// before ProInstall::ensure_db_ready() (which only fires on `doublescale_ready`, after
		// the kernel finishes booting all modules). If migrations haven't run yet — fresh
		// install, just-activated Pro, dropped DB — the table won't exist and any query here
		// fatals the whole site. Return [] so the filter stays registered but renders empty.
		try {
			$levels = LeadScoringRuleLevelModel::get_ordered_by_points();
		} catch ( \Throwable $e ) {
			return $options;
		}

		foreach ( $levels as $level ) {
			// Keys must be level IDs — contact meta stores lead_score_level_id, not slug
			$options[ (string) (int) $level->id ] = $level->name . ' (' . (int) $level->points . '+ ' . __( 'pts', 'doublescale' ) . ')';
		}

		return $options;
	}

	/**
	 * Normalize filter values to numeric level IDs (supports legacy slug-based values).
	 *
	 * @param array<int|string> $values Raw values from the filter UI or stored JSON.
	 * @return array<int>
	 */
	private function normalize_level_ids( array $values ): array {
		$ids = array();
		foreach ( $values as $v ) {
			if ( is_array( $v ) && isset( $v['value'] ) ) {
				$v = $v['value'];
			}
			if ( null === $v || '' === $v ) {
				continue;
			}
			if ( is_numeric( $v ) ) {
				$ids[] = (int) $v;
				continue;
			}
			try {
				$by_slug = LeadScoringRuleLevelModel::get_by_slug( (string) $v );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( $by_slug ) {
				$ids[] = (int) $by_slug->id;
			}
		}

		return array_values( array_unique( $ids ) );
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
		$operator = isset( $filter['operator'] ) ? (string) $filter['operator'] : 'is';
		$value    = isset( $filter['value'] ) ? $filter['value'] : '';

		$operator = $this->normalize_level_operator( $operator );

		if ( is_array( $value ) ) {
			if ( isset( $value['value'] ) ) {
				$value = $value['value'];
			} elseif ( empty( $value ) ) {
				return $query;
			}
		}

		// Handle empty operators
		if ( 'is_empty' === $operator ) {
			return $query->whereDoesntHave(
				'meta',
				function ( $q ) {
					$q->where( 'meta_key', 'lead_score_level_id' );
				}
			);
		}

		if ( 'is_not_empty' === $operator ) {
			return $query->whereHas(
				'meta',
				function ( $q ) {
					$q->where( 'meta_key', 'lead_score_level_id' )
						->where( 'meta_value', '!=', '' );
				}
			);
		}

		// Other operators require a value (avoid empty() — slug "0" / id 0 must be valid)
		if ( null === $value || '' === $value ) {
			return $query;
		}

		// Ensure value is an array
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		$value_ids = $this->normalize_level_ids( $value );
		if ( empty( $value_ids ) ) {
			return $query;
		}

		$meta_values = array_map( 'strval', $value_ids );

		// Add where clause based on operator
		switch ( $operator ) {
			case 'is':
				$query->whereHas(
					'meta',
					function ( $q ) use ( $meta_values ) {
						$q->where( 'meta_key', 'lead_score_level_id' )
							->whereIn( 'meta_value', $meta_values );
					}
				);
				break;

			case 'is_not':
				$query->whereHas(
					'meta',
					function ( $q ) use ( $meta_values ) {
						$q->where( 'meta_key', 'lead_score_level_id' )
							->whereNotIn( 'meta_value', $meta_values );
					}
				);
				break;
		}

		return $query;
	}

	/**
	 * Map common operator aliases for level filters.
	 *
	 * @param string $operator Raw operator.
	 * @return string
	 */
	private function normalize_level_operator( string $operator ): string {
		static $map = array(
			'equals'     => 'is',
			'='          => 'is',
			'not_equals' => 'is_not',
			'!='         => 'is_not',
		);

		return $map[ $operator ] ?? $operator;
	}
}

FiltersManager::instance()->register( new LeadScoreLevel() );
