<?php
/**
 * Class Username
 *
 * This class is responsible for handling the user username rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\User;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use Illuminate\Database\Eloquent\Builder;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;

/**
 * Username class
 */
class IsUser extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Is User';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'is_user';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'user';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'boolean';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'     => __( 'Yes', 'doublescale' ),
			'is_not' => __( 'No', 'doublescale' ),
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

		switch ( $operator ) {
			case 'is':
				$query->has( 'user' );
				break;
			case 'is_not':
				$query->doesntHave( 'user' );
				break;
		}

		return $query;
	}
}

FiltersManager::instance()->register( new IsUser() );
