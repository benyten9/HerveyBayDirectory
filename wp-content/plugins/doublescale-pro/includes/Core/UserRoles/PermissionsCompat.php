<?php

/**
 * Class PermissionsCompat
 *
 * Backward compatibility wrapper for Permissions class methods.
 * This ensures the Pro plugin works even if the free plugin hasn't been updated
 * to include the new Sales Manager role methods.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Core\UserRoles;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;

/**
 * PermissionsCompat class
 *
 * Provides backward-compatible permission checking methods
 */
final class PermissionsCompat {

	/**
	 * Check if user is a Sales Manager
	 *
	 * Falls back to false if the method doesn't exist in the free plugin
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool True if user is sales manager
	 */
	public static function is_sales_manager( $user_id = null ) {
		if ( method_exists( Permissions::class, 'is_sales_manager' ) ) {
			return Permissions::is_sales_manager( $user_id );
		}
		
		// Fallback: check if user has the sales_manager role directly.
		$user_id = $user_id ?: get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( $user && in_array( UserRoles::SALES_MANAGER, (array) $user->roles, true ) ) {
			return true;
		}
		
		return false;
	}

	/**
	 * Check if user has sales manager access (can manage all deals)
	 *
	 * Sales Manager, CRM Manager, and Administrator all have this access.
	 * Falls back to has_crm_manager_access if the method doesn't exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool True if user has sales manager access
	 */
	public static function has_sales_manager_access( $user_id = null ) {
		if ( method_exists( Permissions::class, 'has_sales_manager_access' ) ) {
			return Permissions::has_sales_manager_access( $user_id );
		}
		
		// Fallback: CRM Manager access or is_sales_manager
		return Permissions::has_crm_manager_access( $user_id ) || self::is_sales_manager( $user_id );
	}

	/**
	 * Check if the user can access the projects module at all.
	 *
	 * Falls back to sales rep access when the free plugin is outdated.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function has_project_access( $user_id = null ) {
		if ( method_exists( Permissions::class, 'has_project_access' ) ) {
			return Permissions::has_project_access( $user_id );
		}

		return Permissions::has_sales_rep_access( $user_id );
	}

	/**
	 * Check if the user can see and manage every project.
	 *
	 * Falls back to sales manager access when the free plugin is outdated.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_manage_all_projects( $user_id = null ) {
		if ( method_exists( Permissions::class, 'can_manage_all_projects' ) ) {
			return Permissions::can_manage_all_projects( $user_id );
		}

		return self::has_sales_manager_access( $user_id );
	}

	/**
	 * Check if the user may assign any user as a project owner.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_assign_project_owner( $user_id = null ) {
		if ( method_exists( Permissions::class, 'can_assign_project_owner' ) ) {
			return Permissions::can_assign_project_owner( $user_id );
		}

		return self::has_sales_manager_access( $user_id ) || self::can_manage_all_projects( $user_id );
	}

	/**
	 * Check if the user may assign a sales rep on proposals, invoices, etc.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_assign_sales_rep( $user_id = null ) {
		if ( method_exists( Permissions::class, 'can_assign_sales_rep' ) ) {
			return Permissions::can_assign_sales_rep( $user_id );
		}

		if ( class_exists( '\DoubleScale\Modules\Sales\Capabilities' )
			&& method_exists( '\DoubleScale\Modules\Sales\Capabilities', 'can_assign_sales_rep' ) ) {
			return \DoubleScale\Modules\Sales\Capabilities::can_assign_sales_rep(
				$user_id ? (int) $user_id : 0
			);
		}

		return self::has_sales_manager_access( $user_id ) || self::has_project_access( $user_id );
	}
}
