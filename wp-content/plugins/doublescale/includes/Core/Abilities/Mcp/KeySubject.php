<?php
/**
 * Decides which users an administrator may issue an MCP API key for.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities\Mcp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;
use WP_Error;

/**
 * A key acts as the user it belongs to, so choosing that user is the whole
 * security decision. Two rules, both narrowing:
 *
 * 1. Never another administrator. An administrator can already become any user
 *    by resetting a password, so issuing a key is not new power — but an
 *    admin-bound key is permanent full access that leaves no login record and
 *    keeps working after that administrator is removed from the site. That
 *    defeats accountability rather than permission, and an administrator can
 *    issue their own key from this same screen anyway.
 *
 * 2. Only users who already hold a DoubleScale role. A key for a plain
 *    subscriber authenticates fine and then exposes zero tools, because every
 *    ability gate denies them — a key that silently does nothing is worse than
 *    a refusal that explains why.
 */
final class KeySubject {

	/**
	 * Whether the current user may issue keys on behalf of others at all.
	 *
	 * Deliberately the same gate as the settings screen itself: issuing a key
	 * for someone else is an administrative act, not a CRM one.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_issue_for_others(): bool {
		return Permissions::can_manage_mcp();
	}

	/**
	 * Validate a requested key subject.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id Requested subject.
	 * @return int|WP_Error Subject user id, or an error explaining the refusal.
	 */
	public static function validate( int $user_id ) {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'doublescale_mcp_unknown_user',
				__( 'That user does not exist.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		// Issuing for yourself is always allowed and skips the rules below —
		// an administrator must still be able to create their own key.
		if ( get_current_user_id() === $user_id ) {
			return $user_id;
		}

		if ( ! self::can_issue_for_others() ) {
			return new WP_Error(
				'doublescale_mcp_forbidden_subject',
				__( 'You may only create API keys for yourself.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'doublescale_mcp_admin_subject',
				__( 'Cannot create a key for an administrator. Ask them to create their own from this screen.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		if ( UserRoles::NONE === Permissions::get_user_role( $user_id ) ) {
			return new WP_Error(
				'doublescale_mcp_no_crm_access',
				__( 'That user has no DoubleScale role, so a key for them would expose no tools. Give them a role first.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return $user_id;
	}

	/**
	 * Users the current administrator may issue a key for.
	 *
	 * Queried by DoubleScale role rather than listing every user: a site can
	 * hold thousands of subscribers, none of whom are valid subjects.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function eligible(): array {
		if ( ! self::can_issue_for_others() ) {
			return array();
		}

		$users = get_users(
			array(
				'role__in' => self::crm_roles(),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'number'   => 200,
				'fields'   => array( 'ID', 'user_login', 'display_name' ),
			)
		);

		$out = array();
		foreach ( $users as $user ) {
			// A multi-role user can hold a CRM role and manage_options at once.
			if ( user_can( (int) $user->ID, 'manage_options' ) ) {
				continue;
			}

			$out[] = array(
				'id'         => (int) $user->ID,
				'user_login' => (string) $user->user_login,
				'label'      => (string) ( $user->display_name ?: $user->user_login ),
				'role'       => Permissions::get_user_role( (int) $user->ID ),
			);
		}

		return $out;
	}

	/**
	 * Every DoubleScale role that grants CRM access.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	private static function crm_roles(): array {
		return array(
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
			UserRoles::SUPPORT_MANAGER,
			UserRoles::SUPPORT_AGENT,
			UserRoles::BOOKING_MANAGER,
			UserRoles::BOOKING_AGENT,
			UserRoles::PROJECT_MANAGER,
			UserRoles::PROJECT_MEMBER,
		);
	}
}
