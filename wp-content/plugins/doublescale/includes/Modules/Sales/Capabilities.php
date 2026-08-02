<?php
/**
 * Sales module capabilities.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales;

defined( 'ABSPATH' ) || exit;

use WP_Roles;
use WP_User;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;

/**
 * Capabilities helper for the Sales module.
 */
class Capabilities {

	private const CAPS_SYNC_VERSION = '2026-06-25-sales-approval-v1';

	/**
	 * @return string[]
	 */
	public static function get_sales_capability_slugs(): array {
		return array(
			'doublescale_view_sales',
			'doublescale_manage_all_sales',
			'doublescale_manage_own_sales',
			'doublescale_approve_sales',
		);
	}

	/**
	 * @return bool
	 */
	public static function current_user_can( string $capability ): bool {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		return current_user_can( $capability );
	}

	/**
	 * Capability check for a specific user (defaults to the current user).
	 *
	 * Lets a caller that already resolved a user id — e.g. the admin calendar
	 * aggregator, which scopes every provider against a server-resolved
	 * `$viewer_id` rather than the ambient current user — check a Sales capability
	 * for that exact user.
	 *
	 * @param string $capability Capability slug.
	 * @param int    $user_id    User id (0 = current user).
	 * @return bool
	 */
	public static function user_can( string $capability, int $user_id = 0 ): bool {
		if ( $user_id <= 0 ) {
			return self::current_user_can( $capability );
		}
		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return true;
		}
		return user_can( $user_id, $capability );
	}

	/**
	 * @return bool
	 */
	public static function can_view_sales(): bool {
		return self::current_user_can( 'doublescale_view_sales' )
			|| self::current_user_can( 'doublescale_manage_all_sales' )
			|| self::current_user_can( 'doublescale_manage_own_sales' )
			|| self::current_user_can( 'doublescale_manage' )
			|| self::can_assign_sales_rep();
	}

	/**
	 * Whether a user may manage every Sales record (not just their own).
	 *
	 * @param int $user_id User id to check (0 = current user). The admin calendar
	 *                     passes its server-resolved `$viewer_id` so scoping follows
	 *                     the intended viewer, not whoever the request runs as.
	 * @return bool
	 */
	public static function can_manage_all_sales( int $user_id = 0 ): bool {
		return self::user_can( 'doublescale_manage_all_sales', $user_id )
			|| self::user_can( 'doublescale_manage', $user_id );
	}

	/**
	 * Whether a user may approve sales documents for sending.
	 *
	 * @param int $user_id User id (0 = current user).
	 * @return bool
	 */
	public static function can_approve_sales( int $user_id = 0 ): bool {
		return self::user_can( 'doublescale_approve_sales', $user_id )
			|| self::user_can( 'doublescale_manage', $user_id );
	}

	/**
	 * Whether a user may assign a sales rep on proposals, invoices, and related documents.
	 *
	 * @param int $user_id User id (0 = current user).
	 * @return bool
	 */
	public static function can_assign_sales_rep( int $user_id = 0 ): bool {
		if ( self::can_manage_all_sales( $user_id ) ) {
			return true;
		}

		return Permissions::can_assign_sales_rep( $user_id > 0 ? $user_id : null );
	}

	/**
	 * @return array<string, bool>
	 */
	public static function get_role_capability_map(): array {
		return array(
			UserRoles::SALES_MANAGER => array(
				'doublescale_view_sales'       => true,
				'doublescale_manage_all_sales' => true,
				'doublescale_approve_sales'    => true,
			),
			UserRoles::SALES_REP => array(
				'doublescale_view_sales'       => true,
				'doublescale_manage_own_sales' => true,
			),
			UserRoles::CRM_MANAGER => array(
				'doublescale_view_sales'       => true,
				'doublescale_manage_all_sales' => true,
				'doublescale_approve_sales'    => true,
			),
		);
	}

	/**
	 * @return void
	 */
	public static function sync_capabilities_for_user_roles(): void {
		$stored = get_option( 'doublescale_sales_caps_sync_version', '' );
		if ( self::CAPS_SYNC_VERSION === $stored ) {
			return;
		}

		$roles = new WP_Roles();
		foreach ( self::get_role_capability_map() as $role_slug => $caps ) {
			$role = $roles->get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap => $grant ) {
				if ( $grant ) {
					$role->add_cap( $cap, true );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}

		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			foreach ( self::get_sales_capability_slugs() as $cap ) {
				$admin_role->add_cap( $cap, true );
			}
		}

		update_option( 'doublescale_sales_caps_sync_version', self::CAPS_SYNC_VERSION );
	}

	/**
	 * @return void
	 */
	public static function ensure_capabilities_synced(): void {
		self::sync_capabilities_for_user_roles();
	}

	/**
	 * Whether the current user is restricted to own sales records only (Sales Rep).
	 *
	 * @return bool
	 */
	public static function is_sales_rep_only(): bool {
		return self::current_user_can( 'doublescale_manage_own_sales' )
			&& ! self::can_manage_all_sales()
			&& ! self::can_approve_sales()
			&& ! self::current_user_can( 'doublescale_manage' );
	}

	/**
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function user_can_manage_record( int $user_id, ?int $assigned_user_id ): bool {
		if ( self::can_manage_all_sales( $user_id ) ) {
			return true;
		}
		if ( self::can_assign_sales_rep( $user_id ) ) {
			return true;
		}
		if ( ! self::user_can( 'doublescale_manage_own_sales', $user_id ) ) {
			return false;
		}
		return (int) $assigned_user_id === $user_id;
	}
}
