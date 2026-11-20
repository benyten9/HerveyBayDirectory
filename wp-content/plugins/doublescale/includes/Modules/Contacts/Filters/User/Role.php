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
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

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
		global $wpdb;

		$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'is';
		$value    = isset( $filter['value'] ) ? $filter['value'] : array();

		// The role select sends one role as text; older rules saved a list.
		// Text used to reach a foreach and add no condition, so every WordPress
		// user matched "Role is Editor".
		$roles = array_values(
			array_filter(
				array_map( 'strval', is_array( $value ) ? $value : array( $value ) ),
				static function ( $role ) {
					return '' !== trim( $role );
				}
			)
		);

		if ( empty( $roles ) ) {
			return $query;
		}

		// Any of the picked roles. Roles are stored serialized
		// (s:6:"editor";b:1;): matching the quoted key keeps "editor" from
		// matching "senior_editor".
		$has_any_role = static function ( $user ) use ( $roles, $wpdb ) {
			$user->whereHas(
				'capability',
				static function ( $meta ) use ( $roles, $wpdb ) {
					$meta->where(
						static function ( $any ) use ( $roles, $wpdb ) {
							foreach ( $roles as $role ) {
								$any->orWhere( 'meta_value', 'like', '%"' . $wpdb->esc_like( $role ) . '"%' );
							}
						}
					);
				}
			);
		};

		switch ( $operator ) {
			case 'is':
				$query->whereHas( 'user', $has_any_role );
				break;
			case 'is_not':
				$query->whereDoesntHave( 'user', $has_any_role );
				break;
		}

		return $query;
	}
}

FiltersManager::instance()->register( new Role() );
