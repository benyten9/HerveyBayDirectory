<?php

/**
 * Class LeadScorePoints
 *
 * This class is responsible for handling the contact lead score points filter
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\LeadScoring\Filters;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use Illuminate\Database\Eloquent\Builder;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;

/**
 * LeadScorePoints class
 */
class LeadScorePoints extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Points';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'lead_score_points';

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
	public $type = 'number';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'greater_than' => __( 'Greater than', 'doublescale' ),
			'lower_than'   => __( 'Lower than', 'doublescale' ),
		);
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

		if ( is_array( $value ) ) {
			if ( isset( $value['value'] ) ) {
				$value = $value['value'];
			} elseif ( empty( $value ) ) {
				return $query;
			}
		}

		if ( null === $value || '' === $value ) {
			return $query;
		}

		$value = (int) $value;

		global $wpdb;
		$meta_table = $wpdb->prefix . 'doublescale_contact_meta';
		// Correlate to the outer contacts row (table name / alias from the builder, not a hardcoded prefix).
		$contact_id_col = $query->getModel()->getQualifiedKeyName();

		$operator = $this->normalize_points_operator( $operator );

		$op_sql = null;
		switch ( $operator ) {
			case 'is':
				$op_sql = '=';
				break;
			case 'is_not':
				$op_sql = '<>';
				break;
			case 'greater_than':
				$op_sql = '>';
				break;
			case 'lower_than':
				$op_sql = '<';
				break;
			case 'greater_than_or_equal':
				$op_sql = '>=';
				break;
			case 'lower_than_or_equal':
				$op_sql = '<=';
				break;
		}

		if ( null === $op_sql ) {
			return $query;
		}

		// Latest meta row only; no COALESCE — contacts with no meta row are excluded (not treated as 0).
		// PK column is meta_id (see ContactMetaTable / ContactMetaModel), not id.
		$points_sql = "(SELECT CAST(m.meta_value AS SIGNED) FROM {$meta_table} m WHERE m.contact_id = {$contact_id_col} AND m.meta_key = 'lead_score_points' ORDER BY m.meta_id DESC LIMIT 1)";

		$query->whereRaw( "{$points_sql} {$op_sql} ?", array( $value ) );

		return $query;
	}

	/**
	 * Normalize operator slugs from UI / legacy payloads.
	 *
	 * @param string $operator Raw operator.
	 * @return string
	 */
	private function normalize_points_operator( string $operator ): string {
		static $map = array(
			'equals'     => 'is',
			'='          => 'is',
			'not_equals' => 'is_not',
			'!='         => 'is_not',
			'gt'         => 'greater_than',
			'lt'         => 'lower_than',
			'gte'        => 'greater_than_or_equal',
			'ge'         => 'greater_than_or_equal',
			'>='         => 'greater_than_or_equal',
			'lte'        => 'lower_than_or_equal',
			'le'         => 'lower_than_or_equal',
			'<='         => 'lower_than_or_equal',
		);

		return $map[ $operator ] ?? $operator;
	}
}

FiltersManager::instance()->register( new LeadScorePoints() );
