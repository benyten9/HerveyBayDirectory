<?php

/**
 * Class Permissions
 *
 * This class is responsible for handling CRM permissions
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\UserRoles;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_Error;

/**
 * Permissions class
 *
 * Handles role-based permission checking for the CRM system
 */
final class Permissions {





	/**
	 * Check if user is a CRM Manager/Admin
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool True if user is CRM manager
	 */
	public static function is_crm_manager( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		// WP admins always count as CRM Managers for DoubleScale UI/API gates.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Use role membership (not only the single highest role) so a user who
		// is CRM Manager / Administrator AND Sales Rep still resolves as CRM
		// Manager for settings and other manager-tier UI.
		return self::user_has_role( UserRoles::CRM_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::ADMINISTRATOR, $user_id );
	}

	/**
	 * Whether Settings should be limited to Mailbox + Notifications.
	 *
	 * True only for Sales Rep / Sales Manager users who are NOT also CRM
	 * Manager / Administrator. Multi-role users keep full settings access.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function has_limited_settings_access( $user_id = null ) {
		if ( self::is_crm_manager( $user_id ) ) {
			return false;
		}

		return self::user_has_role( UserRoles::SALES_REP, $user_id )
			|| self::user_has_role( UserRoles::SALES_MANAGER, $user_id );
	}

	/**
	 * Check if user is a Sales Manager
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool True if user is sales manager
	 */
	public static function is_sales_manager( $user_id = null ) {
		return self::user_has_role( UserRoles::SALES_MANAGER, $user_id );
	}

	/**
	 * Check if user is a Sales Rep
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool True if user is sales rep
	 */
	public static function is_sales_rep( $user_id = null ) {
		return self::user_has_role( UserRoles::SALES_REP, $user_id );
	}

	/**
	 * Check if user is a Support Manager
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_support_manager( $user_id = null ) {
		$user_role = self::get_user_role( $user_id );
		return UserRoles::SUPPORT_MANAGER === $user_role;
	}

	/**
	 * Check if user is a Support Agent
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_support_agent( $user_id = null ) {
		$user_role = self::get_user_role( $user_id );
		return UserRoles::SUPPORT_AGENT === $user_role;
	}

	/**
	 * Check if user is a Booking Manager
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_booking_manager( $user_id = null ) {
		return self::user_has_role( UserRoles::BOOKING_MANAGER, $user_id );
	}

	/**
	 * Check if user is a Booking Agent
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_booking_agent( $user_id = null ) {
		return self::user_has_role( UserRoles::BOOKING_AGENT, $user_id );
	}

	/**
	 * Check if user is a Project Manager
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_project_manager( $user_id = null ) {
		return self::user_has_role( UserRoles::PROJECT_MANAGER, $user_id );
	}

	/**
	 * Check if user is a Project Member
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_project_member( $user_id = null ) {
		return self::user_has_role( UserRoles::PROJECT_MEMBER, $user_id );
	}

	/**
	 * Check if the user can see and manage every project (not just their own).
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_manage_all_projects( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( user_can( $user_id, 'manage_options' ) || self::is_crm_manager( $user_id ) ) {
			return true;
		}

		return user_can( $user_id, 'doublescale_project_manage_all_projects' );
	}

	/**
	 * Check if the user may assign a project to any eligible owner.
	 *
	 * Project Members (own-scope only) must assign themselves. Project
	 * Managers, CRM/Sales managers, and administrators may pick any user.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_assign_project_owner( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( self::has_crm_manager_access( $user_id ) ) {
			return true;
		}

		if ( self::has_sales_manager_access( $user_id ) ) {
			return true;
		}

		return self::can_manage_all_projects( $user_id );
	}

	/**
	 * Check if the user may assign tasks (or subtasks) to someone other than themselves.
	 *
	 * Project Members and Sales Reps are limited to self-assignment. Project
	 * Managers, CRM/Sales managers, and administrators may pick any eligible user.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_assign_task_assignee( $user_id = null ) {
		return self::can_assign_project_owner( $user_id );
	}

	/**
	 * Check if the user may assign a sales rep on proposals, invoices, etc.
	 *
	 * Sales Reps are limited to self-assignment. Project Managers, Project
	 * Members, CRM/Sales managers, and administrators may pick any sales-team user.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_assign_sales_rep( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( self::has_crm_manager_access( $user_id ) ) {
			return true;
		}

		if ( self::has_sales_manager_access( $user_id ) ) {
			return true;
		}

		return self::has_project_access( $user_id );
	}

	/**
	 * Check if the user can access the projects module at all.
	 *
	 * CRM Manager and WP admins have full project access (org-admin tier).
	 * Sales Manager / Sales Rep do not — they need an explicit project role.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function has_project_access( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( user_can( $user_id, 'manage_options' ) || self::is_crm_manager( $user_id ) ) {
			return true;
		}

		return user_can( $user_id, 'doublescale_project_read_own_projects' )
			|| user_can( $user_id, 'doublescale_project_read_all_projects' )
			|| user_can( $user_id, 'doublescale_project_manage_own_projects' )
			|| user_can( $user_id, 'doublescale_project_manage_all_projects' );
	}

	/**
	 * Check if the user may access task APIs (list, create, update on projects).
	 *
	 * Sales/CRM users retain access. Project users need tasks on their projects.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_access_tasks_api( $user_id = null ) {
		if ( self::has_sales_rep_access( $user_id ) ) {
			return true;
		}

		return self::has_project_access( $user_id );
	}

	/**
	 * Check if the user may read custom field definitions for a given scope.
	 *
	 * Sales/CRM users retain global read access. Project-only users may read
	 * project-scoped definitions so project forms can render field metadata.
	 *
	 * @param string|null $scope   Entity scope (contact, deal, task, project, …).
	 * @param int|null    $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_read_custom_field_definitions( $scope = null, $user_id = null ) {
		if ( self::has_sales_rep_access( $user_id ) ) {
			return true;
		}

		$scope = is_string( $scope ) ? strtolower( trim( $scope ) ) : '';

		if ( 'project' === $scope && self::has_project_access( $user_id ) ) {
			return true;
		}

		if ( 'task' === $scope && self::can_access_tasks_api( $user_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the user may read shared taxonomy terms (tags, lists).
	 *
	 * Any DoubleScale app user needs this for pickers on contacts, deals, and
	 * projects. Creating or deleting terms remains CRM-manager-only.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_read_taxonomy_terms( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		return user_can( $user_id, 'doublescale_access' );
	}

	/**
	 * Check if the user may read contacts (list/detail).
	 *
	 * Sales/CRM users retain access. Project users need read access to pick a
	 * related contact when creating or editing projects.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_read_contacts( $user_id = null ) {
		if ( self::has_sales_rep_access( $user_id ) ) {
			return true;
		}

		return self::has_project_access( $user_id );
	}

	/**
	 * Check if the user may read deals (list/detail).
	 *
	 * Sales/CRM users retain access. Project users need read access to pick a
	 * related deal when creating or editing projects.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_read_deals( $user_id = null ) {
		if ( self::has_sales_rep_access( $user_id ) ) {
			return true;
		}

		return self::has_project_access( $user_id );
	}

	/**
	 * Check if the user may send a message (email/SMS/WhatsApp) to a contact.
	 *
	 * Sales/CRM users retain access. Project users may send when scoped to a
	 * project they can manage (validated in {@see validate_send_contact_message_access()}).
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_send_contact_message( $user_id = null ) {
		if ( self::has_sales_rep_access( $user_id ) ) {
			return true;
		}

		return self::has_project_access( $user_id );
	}

	/**
	 * Enforce project-scoped send rules for project-only users.
	 *
	 * Sales/CRM users pass unconditionally. Project users must send in the
	 * context of a project they manage, to that project's linked contact.
	 *
	 * @param int      $contact_id Contact ID from the route.
	 * @param int|null $project_id Optional project ID from the request body.
	 * @param int|null $deal_id    Optional deal ID (ignored for project-only users).
	 * @return true|WP_Error
	 */
	public static function validate_send_contact_message_access( $contact_id, $project_id = null, $deal_id = null ) {
		if ( self::has_sales_rep_access() ) {
			return true;
		}

		if ( ! self::has_project_access() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		unset( $deal_id );

		$contact_id = absint( $contact_id );
		$project_id = absint( $project_id );

		if ( $project_id <= 0 || $contact_id <= 0 ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		if ( ! class_exists( '\DoubleScale\Pro\Modules\Projects\Capabilities' )
			|| ! class_exists( '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		if ( ! \DoubleScale\Pro\Modules\Projects\Capabilities::can_manage_project( $project_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You cannot send messages for this project.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		$project = \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::find( $project_id );
		if ( ! $project || (int) $project->contact_id !== $contact_id ) {
			return new WP_Error(
				'invalid_contact',
				__( 'This contact is not linked to the project.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if the user can see and manage every booking (not just their own).
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_manage_all_bookings( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		return user_can( $user_id, 'doublescale_booking_manage_all_bookings' );
	}

	/**
	 * Check if the user can access the booking module at all.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function has_booking_access( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( user_can( $user_id, 'manage_options' ) || self::is_crm_manager( $user_id ) ) {
			return true;
		}

		return user_can( $user_id, 'doublescale_booking_read_own_bookings' )
			|| user_can( $user_id, 'doublescale_booking_read_all_bookings' )
			|| user_can( $user_id, 'doublescale_booking_manage_own_calendars' )
			|| user_can( $user_id, 'doublescale_booking_read_all_calendars' );
	}

	/**
	 * Check if the user may open the Support Settings page (mailboxes, SMTP
	 * identities, notification toggles). Granted to:
	 *   - Administrators (manage_options),
	 *   - CRM Managers (treated like an admin for Support),
	 *   - the Support Manager role.
	 *
	 * Support AGENTS are intentionally excluded: managing mailboxes (create /
	 * delete, SMTP sending identity, bulk ticket re-routing) is a manager-tier
	 * action. Agents are scoped to working their assigned tickets.
	 *
	 * Sales Manager / Sales Rep get NO support access unless an admin ALSO
	 * assigns them a support role. Mirrors the frontend route gate in
	 * `src/client/pages/support/index.tsx`.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_access_support_settings( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		// Administrators and CRM Managers always have full support access.
		if ( user_can( $user_id, 'manage_options' ) || self::is_crm_manager( $user_id ) ) {
			return true;
		}

		// Role-membership check (NOT single-highest-role): a user who is e.g.
		// Sales Rep + Support Manager still gets settings access from the
		// Support Manager role. Capabilities merge across roles. Support AGENTS
		// are deliberately NOT included here — mailbox/channel configuration is
		// manager-tier (see method docblock).
		return self::user_has_role( UserRoles::SUPPORT_MANAGER, $user_id );
	}

	/**
	 * Check if the user can see and manage every support ticket (not just
	 * tickets assigned to them). Granted to administrators, CRM Managers, and
	 * Support Managers (NOT Sales Managers — support is exclusive to the support
	 * roles plus the CRM Manager / admin tier).
	 *
	 * Bypasses the `agent_user_id` ownership filter on Support REST routes.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_manage_all_tickets( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		return user_can( $user_id, 'doublescale_manage_all_tickets' );
	}

	/**
	 * Check if the user can access the support module at all (read and reply
	 * to at least their own tickets). Granted to every CRM/Support role.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function has_support_access( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		return user_can( $user_id, 'doublescale_view_support' );
	}

	/**
	 * Whether a logged-in DoubleScale staff member should be turned away from
	 * the customer portal shortcode (staff redirect instead of the SPA).
	 *
	 * "Staff" is any administrator OR any DoubleScale role (CRM Manager, Sales
	 * Manager, Sales Rep, Support Manager/Agent, Booking Manager/Agent) — NOT
	 * just support-capable roles. The previous gate keyed off
	 * `doublescale_view_support`, which let Sales Manager / Sales Rep (who lack
	 * that cap) into an empty customer portal; every staff role is now treated
	 * the same.
	 *
	 * Exception (kept intentionally): a staff member whose WP email also matches
	 * a CRM contact is a genuine customer too, so they may use the portal as
	 * that contact. Customers (no staff role) are never blocked.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function should_block_customer_portal( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		$is_staff = $user_id > 0
			&& ( user_can( $user_id, 'manage_options' ) || self::check_user_has_role( $user_id ) );
		if ( ! $is_staff ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) || ! is_email( $user->user_email ) ) {
			return true;
		}

		$email = strtolower( trim( (string) $user->user_email ) );

		return ! ContactModel::where( 'email', $email )->exists();
	}

	/**
	 * Check if the user's ONLY DoubleScale roles are support roles (no CRM
	 * Manager / Sales Manager / Sales Rep / Administrator). Used to scope the
	 * admin menu down to only the Support submenu for dedicated support staff.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_support_only( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return false; // Administrators / super-admins never get scoped down.
		}

		$roles             = (array) $user->roles;
		$broader_crm_roles = array(
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
		);
		if ( array_intersect( $broader_crm_roles, $roles ) ) {
			return false;
		}

		$booking_roles = array( UserRoles::BOOKING_MANAGER, UserRoles::BOOKING_AGENT );
		if ( array_intersect( $booking_roles, $roles ) ) {
			return false;
		}

		$project_roles = array( UserRoles::PROJECT_MANAGER, UserRoles::PROJECT_MEMBER );
		if ( array_intersect( $project_roles, $roles ) ) {
			return false;
		}

		$support_roles = array( UserRoles::SUPPORT_MANAGER, UserRoles::SUPPORT_AGENT );
		return (bool) array_intersect( $support_roles, $roles );
	}

	/**
	 * Check if the user's ONLY DoubleScale roles are booking roles (no CRM /
	 * Support / Administrator). Used to scope the admin menu down to only the
	 * Booking submenu for dedicated booking staff.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_booking_only( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		$roles             = (array) $user->roles;
		$broader_crm_roles = array(
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
		);
		if ( array_intersect( $broader_crm_roles, $roles ) ) {
			return false;
		}

		$support_roles = array( UserRoles::SUPPORT_MANAGER, UserRoles::SUPPORT_AGENT );
		if ( array_intersect( $support_roles, $roles ) ) {
			return false;
		}

		$project_roles = array( UserRoles::PROJECT_MANAGER, UserRoles::PROJECT_MEMBER );
		if ( array_intersect( $project_roles, $roles ) ) {
			return false;
		}

		$booking_roles = array( UserRoles::BOOKING_MANAGER, UserRoles::BOOKING_AGENT );
		return (bool) array_intersect( $booking_roles, $roles );
	}

	/**
	 * Check if the user's ONLY DoubleScale roles are project roles (no CRM /
	 * Support / Booking / Administrator). Used to scope the admin menu down to
	 * only the Projects submenu for dedicated project staff.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_project_only( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		$roles             = (array) $user->roles;
		$broader_crm_roles = array(
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
		);
		if ( array_intersect( $broader_crm_roles, $roles ) ) {
			return false;
		}

		$support_roles = array( UserRoles::SUPPORT_MANAGER, UserRoles::SUPPORT_AGENT );
		if ( array_intersect( $support_roles, $roles ) ) {
			return false;
		}

		$booking_roles = array( UserRoles::BOOKING_MANAGER, UserRoles::BOOKING_AGENT );
		if ( array_intersect( $booking_roles, $roles ) ) {
			return false;
		}

		$project_roles = array( UserRoles::PROJECT_MANAGER, UserRoles::PROJECT_MEMBER );
		return (bool) array_intersect( $project_roles, $roles );
	}


	/**
	 * Check if user has basic CRM access
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool True if user has CRM access
	 */
	public static function has_crm_manager_access( $user_id = null ) {
		$user_role = self::get_user_role( $user_id );
		// Check if user has one of the allowed roles
		return in_array( $user_role, array( UserRoles::CRM_MANAGER, UserRoles::ADMINISTRATOR ) ) ? true : false;
	}

	/**
	 * Check if user has sales manager access (can manage all deals)
	 *
	 * Sales Manager, CRM Manager, and Administrator all have this access
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool True if user has sales manager access
	 */
	public static function has_sales_manager_access( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( self::has_crm_manager_access( $user_id ) ) {
			return true;
		}

		return self::user_has_role( UserRoles::SALES_MANAGER, $user_id );
	}

	/**
	 * Check if user has sales rep access
	 *
	 * Uses role membership (not single highest role) so a user who is e.g.
	 * Booking Agent + Sales Rep still gets sales endpoints from the Sales Rep
	 * role. CRM Manager / Administrator always included.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool True if user has sales rep access
	 */
	public static function has_sales_rep_access( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( self::has_crm_manager_access( $user_id ) ) {
			return true;
		}

		return self::user_has_role( UserRoles::SALES_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::SALES_REP, $user_id );
	}

	/**
	 * Check if user has a CRM role
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID
	 * @return bool True if user has a CRM role
	 */
	public static function check_user_has_role( $user_id ) {
		$user_role = self::get_user_role( $user_id );
		return in_array( $user_role, UserRoles::get_known_role_slugs(), true );
	}


	/**
	 * Check if user has AI access based on dynamic settings.
	 *
	 * NOTE: Unlike other has_*_access() methods which return plain bool,
	 * this returns bool|WP_Error because it serves as both:
	 * 1. A WordPress REST/Abilities permission_callback (supports WP_Error for detailed error responses)
	 * 2. An internal permission check (truthy = access granted)
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return true|\WP_Error True if access granted, WP_Error with reason if denied.
	 */
	public static function has_ai_access( $user_id = null ) {
		$ai_settings = Settings::get( 'ai', array() );
		$access      = $ai_settings['access'] ?? self::default_ai_access();

		// Provider must be configured (hard requirement for everyone).
		if ( empty( $ai_settings['provider'] ) ) {
			return new \WP_Error( 'ai_not_configured', __( 'AI provider not configured.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$user_role = self::get_user_role( $user_id );

		// Administrators always have access regardless of the master switch.
		if ( UserRoles::ADMINISTRATOR === $user_role ) {
			return true;
		}

		// Master switch (only governs non-admin roles).
		if ( empty( $access['enabled'] ) ) {
			return new \WP_Error( 'ai_disabled', __( 'AI features are disabled.', 'doublescale' ), array( 'status' => 403 ) );
		}

		$allowed_roles = $access['allowed_roles'] ?? array( UserRoles::CRM_MANAGER, UserRoles::ADMINISTRATOR );

		if ( ! in_array( $user_role, $allowed_roles, true ) ) {
			return new \WP_Error( 'ai_no_access', __( 'Your role does not have AI access.', 'doublescale' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Default AI access settings (applied when keys are missing).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function default_ai_access() {
		return array(
			'enabled'       => true,
			'allowed_roles' => array(
				UserRoles::CRM_MANAGER,
				UserRoles::ADMINISTRATOR,
				UserRoles::SALES_MANAGER,
				UserRoles::SALES_REP,
			),
		);
	}

	/**
	 * Default AI data access settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function default_ai_data_access() {
		return array(
			'crm_data'          => true,
			'conversation_data' => true,
			'campaign_data'     => true,
			'activity_data'     => true,
			'support_data'      => true,
			'booking_data'      => true,
		);
	}

	/**
	 * Default AI data sources settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function default_ai_data_sources() {
		return array(
			'business_profile'       => true,
			'brand_voice'            => '',
			'industry'               => '',
			'product_description'    => '',
			'ideal_customer_profile' => '',
			'custom_instructions'    => '',
		);
	}

	// helper methods

	/**
	 * Set current user ID
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID
	 * @return int User ID
	 */
	public static function set_current_user_id( $user_id ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		return $user_id;
	}

	/**
	 * Get user role
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID
	 * @return string User role
	 */
	public static function get_user_role( $user_id ) {
		$user_id = self::set_current_user_id( $user_id );

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return UserRoles::NONE;
		}

		// In multisite, super admins have full access regardless of per-site roles.
		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return UserRoles::ADMINISTRATOR;
		}

		$roles = (array) $user->roles;

		// Priority order: Administrator > CRM Manager > Sales Manager > Sales Rep
		// > Support Manager > Support Agent > Booking Manager > Booking Agent
		// > Project Manager > Project Member.
		$priority = array(
			UserRoles::ADMINISTRATOR,
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

		foreach ( $priority as $role ) {
			if ( in_array( $role, $roles ) ) {
				return $role; // return highest role
			}
		}

		return UserRoles::NONE;
	}

	/**
	 * Check whether the user actually HOLDS a given role — looking at ALL of
	 * their roles, not just the single highest one ({@see get_user_role()}).
	 *
	 * This is what makes capabilities merge across roles: a user who is both
	 * Sales Rep AND Support Manager returns true for BOTH
	 * `user_has_role(SALES_REP)` and `user_has_role(SUPPORT_MANAGER)`, so each
	 * role's permissions apply independently. Use this (not `is_*`/`get_user_role`)
	 * whenever a permission should be granted by the mere presence of a role.
	 *
	 * @param string   $role    Role slug to look for.
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function user_has_role( $role, $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		// Multisite super admins implicitly hold the administrator role.
		if ( UserRoles::ADMINISTRATOR === $role && is_multisite() && is_super_admin( $user_id ) ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return in_array( $role, (array) $user->roles, true );
	}
}
