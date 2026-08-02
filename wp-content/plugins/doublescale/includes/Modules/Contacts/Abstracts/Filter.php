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

use Illuminate\Database\Eloquent\Builder;

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
		$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'is';
		$value    = isset( $filter['value'] ) ? $filter['value'] : '';

		if ( empty( $value ) ) {
			return $query;
		}

		// Remove contact_ prefix from slug
		$this->slug = str_replace( 'contact_', '', $this->slug );

		// Add where clause
		switch ( $operator ) {
			case 'is':
				$query = $query->where( $this->slug, $value );
				break;
			case 'is_not':
				$query = $query->where( $this->slug, '!=', $value );
				break;
			case 'contains':
				$query = $query->where( $this->slug, 'LIKE', '%' . $value . '%' );
				break;
			case 'does_not_contain':
				$query = $query->where( $this->slug, 'NOT LIKE', '%' . $value . '%' );
				break;
			case 'starts_with':
				$query = $query->where( $this->slug, 'LIKE', $value . '%' );
				break;
			case 'ends_with':
				$query = $query->where( $this->slug, 'LIKE', '%' . $value );
				break;
			case 'is_empty':
				$query = $query->where( $this->slug, '' );
				break;
			case 'is_not_empty':
				$query = $query->where( $this->slug, '!=', '' );
				break;
		}

		return $query;
	}
}
