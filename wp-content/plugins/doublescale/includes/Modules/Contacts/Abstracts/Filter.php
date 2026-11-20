<?php
/**
 * Class Filter
 *
 * This class is responsible for handling the conditions filters
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * Filter class
 */
abstract class Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name;

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug;

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group;

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type;

	/**
	 * Dynamic options
	 *
	 * @var bool
	 */
	public $is_dynamic = false;

	/**
	 * Dynamic args
	 *
	 * @var array
	 */
	public $dynamic_args = array();

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'               => __( 'Is', 'doublescale' ),
			'is_not'           => __( 'Is not', 'doublescale' ),
			'contains'         => __( 'Contains', 'doublescale' ),
			'does_not_contain' => __( 'Does not contain', 'doublescale' ),
			'starts_with'      => __( 'Starts with', 'doublescale' ),
			'ends_with'        => __( 'Ends with', 'doublescale' ),
			'is_empty'         => __( 'Is empty', 'doublescale' ),
			'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
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
	 * Contacts table column this filter reads.
	 *
	 * @since 1.0.0
	 *
	 * @return string The slug without its contact_ prefix.
	 */
	protected function get_column() {
		return str_replace( 'contact_', '', $this->slug );
	}

	/**
	 * Is met
	 *
	 * @since 1.0.0
	 *
	 * @param Builder $query Query.
	 * @param array   $filter Rule.
	 *
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		global $wpdb;

		$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'is';
		$value    = isset( $filter['value'] ) ? $filter['value'] : '';

		// A local, not $this->slug: this instance is the shared registry entry,
		// so rewriting the slug renamed the filter.
		$column = $this->get_column();

		// These take no value. They must run before the empty-value guard
		// below, which otherwise dropped them and returned every contact.
		// An empty field is stored as NULL as often as '', so match both.
		switch ( $operator ) {
			case 'is_empty':
				return $query->where(
					function ( $q ) use ( $column ) {
						$q->whereNull( $column )->orWhere( $column, '' );
					}
				);
			case 'is_not_empty':
				return $query->whereNotNull( $column )->where( $column, '!=', '' );
		}

		// Every other operator needs a value; an unfinished row is ignored.
		if ( empty( $value ) ) {
			return $query;
		}

		// What the user typed is matched literally: a "_" or "%" in it is not a
		// LIKE wildcard ("contains _" matched every contact).
		$like = $wpdb->esc_like( (string) $value );

		// Add where clause
		switch ( $operator ) {
			case 'is':
				$query = $query->where( $column, $value );
				break;
			case 'is_not':
				// An empty field is "not x" too, but SQL's NULL != 'x' is not
				// true: "City is not Cairo" dropped everyone without a city.
				$query = $query->where(
					function ( $q ) use ( $column, $value ) {
						$q->whereNull( $column )->orWhere( $column, '!=', $value );
					}
				);
				break;
			case 'contains':
				$query = $query->where( $column, 'LIKE', '%' . $like . '%' );
				break;
			case 'does_not_contain':
				$query = $query->where(
					function ( $q ) use ( $column, $like ) {
						$q->whereNull( $column )->orWhere( $column, 'NOT LIKE', '%' . $like . '%' );
					}
				);
				break;
			case 'starts_with':
				$query = $query->where( $column, 'LIKE', $like . '%' );
				break;
			case 'ends_with':
				$query = $query->where( $column, 'LIKE', '%' . $like );
				break;
		}

		return $query;
	}
}
