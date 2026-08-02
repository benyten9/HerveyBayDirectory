<?php
/**
 * Projects module capabilities.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects;

defined( 'ABSPATH' ) || exit;

use WP_Roles;
use WP_User;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

class Capabilities {

	/**
	 * Bump when the project role-to-capability map changes so existing installs
	 * re-run {@see sync_capabilities_for_user_roles()} on next boot.
	 */
	private const CAPS_SYNC_VERSION = '2026-07-26-crm-manager-project-caps-v3';

	public static function get_core_capabilities() {
		return array(
			'projects' => array(
				'title'        => __( 'Project Access', 'doublescale' ),
				'capabilities' => array(
					'doublescale_project_read_own_projects'   => __( 'Read only the user\'s own projects', 'doublescale' ),
					'doublescale_project_read_all_projects'   => __( 'Read access to all projects', 'doublescale' ),
					'doublescale_project_manage_own_projects' => __( 'Manage only the user\'s own projects', 'doublescale' ),
					'doublescale_project_manage_all_projects' => __( 'Manage all projects across users', 'doublescale' ),
					'doublescale_project_manage_statuses'     => __( 'Manage project kanban statuses', 'doublescale' ),
				),
			),
		);
	}

	public static function current_user_can( $capability ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		return current_user_can( $capability );
	}

	public static function get_current_user_capabilities() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		if ( is_multisite() && is_super_admin( $user_id ) ) {
			$all_caps = self::get_all_capabilities();
			return array_fill_keys( $all_caps, true );
		}

		$user         = new WP_User( $user_id );
		$capabilities = $user->get_role_caps();
		$project_caps = self::get_all_capabilities();
		$allowed_keys = array_merge( $project_caps, array( 'manage_options' ) );

		return array_intersect_key( $capabilities, array_flip( $allowed_keys ) );
	}

	/**
	 * Project-module capability slugs only (`doublescale_project_*`).
	 *
	 * @return string[]
	 */
	public static function get_project_capability_slugs(): array {
		$slugs = array();

		foreach ( self::get_core_capabilities() as $group ) {
			$slugs = array_merge( $slugs, array_keys( $group['capabilities'] ) );
		}

		return $slugs;
	}

	public static function get_all_capabilities() {
		return array_merge( array( 'doublescale_access' ), self::get_project_capability_slugs() );
	}

	/**
	 * Capabilities granted to Project Member (own-scope read + manage only).
	 *
	 * @return string[]
	 */
	public static function get_member_capabilities(): array {
		return array(
			'doublescale_access',
			'doublescale_project_read_own_projects',
			'doublescale_project_manage_own_projects',
		);
	}

	/**
	 * Capabilities granted to a single role per the matrix.
	 *
	 * @param string $role Role slug.
	 * @return string[] Capability names.
	 */
	public static function get_caps_for_role( string $role ): array {
		switch ( $role ) {
			case 'administrator':
			case UserRoles::CRM_MANAGER:
			case UserRoles::PROJECT_MANAGER:
				return self::get_all_capabilities();

			case UserRoles::PROJECT_MEMBER:
				return self::get_member_capabilities();
		}

		return array();
	}

	/**
	 * Roles that may receive project caps (including roles we must strip caps from).
	 *
	 * @return string[]
	 */
	private static function sync_role_slugs(): array {
		return array(
			'administrator',
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
			UserRoles::PROJECT_MANAGER,
			UserRoles::PROJECT_MEMBER,
		);
	}

	/**
	 * Idempotently sync project caps onto every role that may hold them.
	 *
	 * Uses {@see WP_Role::add_cap()} / {@see WP_Role::remove_cap()} (not
	 * `$wp_roles->add_cap()`) so both the in-memory role objects and the
	 * `wp_user_roles` option stay aligned — WP_Roles::add_cap() only updates
	 * the option array and leaves role_objects stale for the rest of the request.
	 *
	 * @return void
	 */
	public static function sync_capabilities_for_user_roles() {
		if ( ! class_exists( 'WP_Roles' ) ) {
			return;
		}

		// Ensure the global is initialized before get_role().
		wp_roles();

		$all_caps = self::get_project_capability_slugs();

		foreach ( self::sync_role_slugs() as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			$should_have = array_fill_keys( self::get_caps_for_role( $role_slug ), true );

			foreach ( $all_caps as $capability ) {
				if ( isset( $should_have[ $capability ] ) ) {
					$role->add_cap( $capability );
				} else {
					$role->remove_cap( $capability );
				}
			}
		}

		if ( is_multisite() ) {
			add_filter( 'user_has_cap', array( __CLASS__, 'grant_super_admin_capabilities' ), 10, 4 );
		}

		update_option( 'doublescale_projects_caps_version', self::CAPS_SYNC_VERSION, false );
	}

	/**
	 * Re-sync project caps when the role map changes.
	 *
	 * @return void
	 */
	public static function ensure_capabilities_synced(): void {
		$current = (string) get_option( 'doublescale_projects_caps_version', '' );
		if ( self::CAPS_SYNC_VERSION === $current ) {
			return;
		}

		self::sync_capabilities_for_user_roles();

		if ( is_user_logged_in() ) {
			wp_get_current_user()->get_role_caps();
		}
	}

	public static function grant_super_admin_capabilities( $allcaps, $caps, $args, $user ) {
		if ( ! is_multisite() || ! is_super_admin( $user->ID ) ) {
			return $allcaps;
		}

		$plugin_capabilities = self::get_project_capability_slugs();

		foreach ( $caps as $cap ) {
			if ( in_array( $cap, $plugin_capabilities, true ) ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	public static function can_read_project( $project_id ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		if ( \DoubleScale\Core\UserRoles\Permissions::is_crm_manager() ) {
			return true;
		}

		$project = ProjectModel::find( $project_id );

		if ( ! $project ) {
			return false;
		}

		if ( current_user_can( 'doublescale_project_read_all_projects' ) ) {
			return true;
		}

		if ( current_user_can( 'doublescale_project_read_own_projects' )
			&& (int) $project->owner_id === get_current_user_id() ) {
			return true;
		}

		return false;
	}

	public static function can_manage_project( $project_id ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		if ( \DoubleScale\Core\UserRoles\Permissions::is_crm_manager() ) {
			return true;
		}

		$project = ProjectModel::find( $project_id );

		if ( ! $project ) {
			return false;
		}

		if ( current_user_can( 'doublescale_project_manage_all_projects' ) ) {
			return true;
		}

		if ( current_user_can( 'doublescale_project_manage_own_projects' )
			&& (int) $project->owner_id === get_current_user_id() ) {
			return true;
		}

		return false;
	}

	public static function remove_capabilities() {
		global $wp_roles;

		if ( ! class_exists( 'WP_Roles' ) ) {
			return;
		}

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}

		$all_caps = self::get_project_capability_slugs();

		foreach ( self::sync_role_slugs() as $role_slug ) {
			foreach ( $all_caps as $capability ) {
				$wp_roles->remove_cap( $role_slug, $capability );
			}
		}

		delete_option( 'doublescale_projects_caps_version' );

		remove_filter( 'user_has_cap', array( __CLASS__, 'grant_super_admin_capabilities' ), 10 );
	}
}
