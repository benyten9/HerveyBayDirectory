<?php
/**
 * Class Role
 *
 * This class is responsible for handling the user role rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\User;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;
use Illuminate\Database\Eloquent\Builder;

/**
 * Role class
 */
class Role extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Role';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'user_role';

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
			'is'     => __( 'Matches', 'doublescale' ),
			'is_not' => __( 'Does not match', 'doublescale' ),
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
		// Get all WordPress roles
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles();
		}

		$roles   = $wp_roles->roles;
		$options = array();

		foreach ( $roles as $role => $role_data ) {
			$options[ $role ] = $role_data['name'];
		}

		return $options;
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

		if ( empty( $value ) ) {
			return $query;
		}

		switch ( $operator ) {
			case 'is':
				$query->whereHas(
					'user',
					function ( $query ) use ( $value ) {
						$query->whereHas(
							'capability',
							function ( $query ) use ( $value ) {
								foreach ( $value as $role ) {
									$query->where( 'meta_value', 'like', '%' . $role . '%' );
								}
							}
						);
					}
				);
				break;
			case 'is_not':
				$query->whereDoesntHave(
					'user',
					function ( $query ) use ( $value ) {
						$query->whereHas(
							'capability',
							function ( $query ) use ( $value ) {
								foreach ( $value as $role ) {
									$query->where( 'meta_value', 'like', '%' . $role . '%' );
								}
							}
						);
					}
				);
				break;
		}

		return $query;
	}
}

FiltersManager::instance()->register( new Role() );
