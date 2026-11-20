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

		return self::user_has_role( UserRoles::CRM_AGENT, $user_id )
			|| self::user_has_role( UserRoles::SALES_REP, $user_id )
			|| self::user_has_role( UserRoles::SALES_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::AUTOMATION_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::AUTOMATION_AGENT, $user_id )
			|| self::user_has_role( UserRoles::CAMPAIGN_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::CAMPAIGN_AGENT, $user_id )
			|| self::user_has_role( UserRoles::SUPPORT_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::SUPPORT_AGENT, $user_id )
			|| self::user_has_role( UserRoles::BOOKING_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::BOOKING_AGENT, $user_id )
			|| self::user_has_role( UserRoles::PROJECT_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::PROJECT_MEMBER, $user_id )
			|| self::user_has_role( UserRoles::HELP_CENTER_MANAGER, $user_id )
			|| self::user_has_role( UserRoles::HELP_CENTER_AGENT, $user_id );
	}

	/**
	 * Whether the user may see and change the MCP settings screen.
	 *
	 * CRM Manager is not enough: MCP publishes a site-wide HTTP endpoint and
	 * issues keys that let an external AI client read CRM data. That is a
	 * WordPress-administrator decision, not a CRM-role one. Connecting clients
	 * still run as the user the key belongs to — this gate is only for the
	 * Settings → MCP page.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_manage_mcp( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		return user_can( $user_id, 'manage_options' );
	}

	/**
	 * Whether the user may issue and revoke MCP keys for THEMSELVES.
	 *
	 * Distinct from {@see can_manage_mcp()}, which governs the MCP surface as a
	 * whole — enabling the endpoint, and seeing or revoking everyone's keys.
	 * That stays an administrator decision.
	 *
	 * This weaker gate exists because the previous admin-only rule made the
	 * feature unusable for the people it was built for: a sales rep has dozens
	 * of callable abilities and no way to obtain a key, so every key had to be
	 * minted by an administrator on their behalf. A self-issued key grants its
	 * owner nothing they do not already have — every ability re-checks the role
	 * and the owner scope at call time, so a rep's key sees a rep's records.
	 *
	 * Requires a DoubleScale role: a key for a user with none authenticates and
	 * then exposes zero tools, which reads as a broken connection.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_manage_own_mcp_key( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( self::can_manage_mcp( $user_id ) ) {
			return true;
		}

		return UserRoles::NONE !== self::get_user_role( $user_id );
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
	 * Whether the user holds the CRM Agent role (membership, like the other
	 * `is_*` role checks).
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_crm_agent( $user_id = null ) {
		return self::user_has_role( UserRoles::CRM_AGENT, $user_id );
	}

	/**
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_campaign_manager( $user_id = null ) {
		return self::user_has_role( UserRoles::CAMPAIGN_MANAGER, $user_id );
	}

	/**
	 * Campaign Agent: their own campaigns and sequences, to their own contacts.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_campaign_agent( $user_id = null ) {
		return self::user_has_role( UserRoles::CAMPAIGN_AGENT, $user_id );
	}

	/**
	 * Whether the user holds the Team Member base role (admin access only;
	 * an add-on decides what they work on).
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_team_member( $user_id = null ) {
		return self::user_has_role( UserRoles::TEAM_MEMBER, $user_id );
	}

	/**
	 * Anyone who runs campaigns and sequences: campaign managers (every
	 * campaign), and Campaign Agents and CRM Agents (their own).
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_use_campaigns( $user_id = null ) {
		return self::can_manage_campaigns( $user_id ) || self::is_campaign_agent( $user_id ) || self::is_crm_agent( $user_id );
	}

	/**
	 * The contact whose campaigns this user's campaigns may reach: the user,
	 * when they are an agent limited to their own contacts; null (no limit)
	 * for managers, or when everyone sees all contacts.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return int|null
	 */
	public static function campaign_audience_owner( $user_id = null ): ?int {
		$user_id = self::set_current_user_id( $user_id );
		if ( $user_id <= 0 || self::can_manage_campaigns( $user_id ) ) {
			return null;
		}

		return self::is_scoped_agent( $user_id ) ? (int) $user_id : null;
	}

	/**
	 * Every campaign, sequence and email template: CRM Managers (and
	 * administrators) and Campaign Managers. Forms, lists and tags stay with
	 * CRM Managers.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_manage_campaigns( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		return self::has_crm_manager_access( $user_id ) || self::is_crm_manager( $user_id ) || self::is_campaign_manager( $user_id );
	}

	/**
	 * Site option: what agent-level team members see in Contacts.
	 * 'own' (default) — contacts they own, contacts shared with them, and
	 * unassigned contacts; 'all' — every contact.
	 *
	 * Existing contacts start unassigned, so on upgrade agents still see all
	 * of them; only contacts another agent creates afterwards are private.
	 *
	 * Its own option, not a key in the shared settings array: that array is
	 * saved whole, so a slow request holding an older copy could silently
	 * switch privacy back. Access rules must not be lost that way.
	 */
	public const CONTACT_VISIBILITY_OPTION = 'doublescale_contact_visibility';

	/**
	 * Roles that make someone a manager of at least one module. Holding any
	 * of them means the person sees every contact, whatever the setting.
	 * Help Center Manager is not one: it manages articles only and gives no
	 * contact access, so a CRM or Support Agent who also manages the help
	 * center stays an agent (scoped contacts).
	 *
	 * @return string[]
	 */
	public static function manager_tier_roles(): array {
		return array(
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SUPPORT_MANAGER,
			UserRoles::BOOKING_MANAGER,
			UserRoles::PROJECT_MANAGER,
			UserRoles::AUTOMATION_MANAGER,
			UserRoles::CAMPAIGN_MANAGER,
		);
	}

	/**
	 * Agent-level roles: the general CRM Agent and every module agent role.
	 *
	 * @return string[]
	 */
	public static function agent_tier_roles(): array {
		return array(
			UserRoles::CRM_AGENT,
			UserRoles::SALES_REP,
			UserRoles::SUPPORT_AGENT,
			UserRoles::BOOKING_AGENT,
			UserRoles::PROJECT_MEMBER,
			UserRoles::AUTOMATION_AGENT,
			UserRoles::CAMPAIGN_AGENT,
			UserRoles::HELP_CENTER_AGENT,
			UserRoles::TEAM_MEMBER,
		);
	}

	/**
	 * The site's contact visibility for agents: 'all' or 'own'.
	 *
	 * @return string
	 */
	public static function contact_visibility(): string {
		return 'all' === get_option( self::CONTACT_VISIBILITY_OPTION, 'own' ) ? 'all' : 'own';
	}

	/**
	 * @param string $mode 'all' or 'own'.
	 * @return void
	 */
	public static function set_contact_visibility( string $mode ): void {
		update_option( self::CONTACT_VISIBILITY_OPTION, 'all' === $mode ? 'all' : 'own', true );
	}

	/**
	 * Whether the user works at agent level only: holds an agent role and no
	 * manager role (and is not an administrator / CRM Manager).
	 *
	 * Independent of the visibility setting, so ownership and sharing are
	 * recorded the same way whichever mode the site is in — switching to
	 * private later then works from day one.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_agent_tier( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if ( self::is_crm_manager( $user_id ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$roles = (array) $user->roles;
		if ( array_intersect( self::manager_tier_roles(), $roles ) ) {
			return false;
		}

		return (bool) array_intersect( self::agent_tier_roles(), $roles );
	}

	/**
	 * Whether the user is limited to their own (plus shared and unassigned)
	 * contacts right now: an agent-level user on a site set to 'own'.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_scoped_agent( $user_id = null ) {
		return 'own' === self::contact_visibility() && self::is_agent_tier( $user_id );
	}

	/**
	 * The one question every own-scope branch asks: may this user see EVERY
	 * record of a module, or only their own?
	 *
	 * Rule: holding any manager-tier role for a module grants see-all for that
	 * module (role MEMBERSHIP, the same way `user_has_role()`, the Support,
	 * Booking and Sales caps and the Tasks controller already decide). A CRM
	 * Agent who is also Sales Manager sees every deal but still only their own
	 * contacts, tickets and bookings.
	 *
	 * Contacts follow the visibility setting (default 'own'); for every other
	 * module each role answers exactly as its module's own branch did before
	 * this method existed. The CRM Agent adds "own" answers (deals, tasks),
	 * mirroring the Sales Rep it contains.
	 *
	 * @param string   $module  One of: contacts, deals, sales, tasks, projects,
	 *                          support, booking, campaigns, lists, tags,
	 *                          templates, automations, activities, reports,
	 *                          dashboard.
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool True when the user sees all records of the module.
	 */
	public static function sees_all_records( $module, $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		// Administrators and CRM Managers see everything, everywhere.
		if ( self::is_crm_manager( $user_id ) ) {
			return true;
		}

		switch ( (string) $module ) {
			case 'contacts':
				// Everyone sees every contact unless the site limits agents
				// ('own'), and then only agent-level users are limited. Any
				// manager role keeps the team-wide view.
				return ! self::is_scoped_agent( $user_id );

			case 'deals':
				// Exactly the old `is_sales_rep() && ! has_sales_manager_access()`
				// rule, with the CRM Agent added. Other roles that can read deals
				// (project staff) keep seeing every deal, as before.
				if ( self::has_sales_manager_access( $user_id ) ) {
					return true;
				}
				return ! self::is_sales_rep( $user_id ) && ! self::is_crm_agent( $user_id );

			case 'sales':
				return user_can( $user_id, 'doublescale_manage_all_sales' )
					|| user_can( $user_id, 'doublescale_manage' );

			case 'tasks':
				// Mirrors TaskAccess::is_own_tasks_only(): Sales Reps and scoped
				// agents work their own tasks. The team-wide view belongs to the
				// task managers — Sales Managers and Project Managers; every
				// other role (Project Member, Helpdesk, Booking, Campaign,
				// Automation staff) works on their own tasks.
				if ( self::is_sales_rep( $user_id ) || self::is_crm_agent( $user_id ) ) {
					return false;
				}
				return self::has_sales_manager_access( $user_id )
					|| self::is_project_manager( $user_id )
					|| self::can_manage_all_projects( $user_id );

			case 'projects':
				return self::can_manage_all_projects( $user_id );

			case 'support':
				return self::can_manage_all_tickets( $user_id );

			case 'booking':
				return user_can( $user_id, 'doublescale_booking_read_all_bookings' );

			case 'automations':
				return self::can_manage_all_automations( $user_id );

			case 'campaigns':
			case 'lists':
			case 'tags':
			case 'templates':
				// Campaign managers see every campaign; a Campaign Agent
				// sees the ones they created.
				return self::can_manage_campaigns( $user_id );

			case 'activities':
			case 'reports':
			case 'dashboard':
				return self::has_sales_manager_access( $user_id );
		}

		// An unknown module name must never widen access.
		return false;
	}

	/**
	 * Whether the user may set or change a contact's owner.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_assign_contact_owner( $user_id = null ) {
		// Choosing an owner changes the contact, so it follows the edit rule:
		// administrators and CRM Managers, not module managers.
		return self::can_edit_any_contact( $user_id );
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
		if ( self::has_agent_access( $user_id ) ) {
			return true;
		}

		if ( self::has_project_access( $user_id ) ) {
			return true;
		}

		// Every team role works on tasks — Helpdesk, Booking, Campaign and
		// Automation staff included. Who sees which tasks is
		// sees_all_records( 'tasks' ): outside the task managers, their own.
		$user_id = self::set_current_user_id( $user_id );
		return user_can( $user_id, 'manage_options' ) || self::check_user_has_role( $user_id );
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
		if ( self::has_agent_access( $user_id ) ) {
			return true;
		}

		$scope = is_string( $scope ) ? strtolower( trim( $scope ) ) : '';

		if ( 'project' === $scope && self::has_project_access( $user_id ) ) {
			return true;
		}

		if ( 'task' === $scope && self::can_access_tasks_api( $user_id ) ) {
			return true;
		}

		// Everyone who opens contacts reads their custom fields.
		if ( 'contact' === $scope && self::can_read_contacts( $user_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the user may read shared taxonomy terms (tags, lists).
	 *
	 * Any customer-facing DoubleScale user needs this for pickers on contacts, deals, and
	 * projects ({@see self::has_crm_or_support_access()}). Creating or deleting terms remains CRM-manager-only.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_read_taxonomy_terms( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		// Tag and list names describe the customer base: not for the Help Center
		// or Team Member roles, which hold `doublescale_access` for the shell only.
		return user_can( $user_id, 'doublescale_access' ) && self::has_crm_or_support_access( $user_id );
	}

	/**
	 * Check if the user may read contacts (list/detail).
	 *
	 * Every team role opens contacts: CRM and Sales roles work them, and the
	 * module roles (Campaigns, Support, Booking, Projects, Automations) read
	 * the customers behind their work. Which contacts follows the Contact
	 * visibility setting ({@see sees_all_records()}); changing one is for its
	 * owner, CRM Managers and administrators ({@see can_edit_any_contact()}).
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_read_contacts( $user_id = null ) {
		if ( self::has_agent_access( $user_id ) ) {
			return true;
		}

		// Campaigns build audiences from contacts (read only); a Campaign
		// Agent's list is narrowed like any agent's.
		if ( self::is_campaign_manager( $user_id ) || self::is_campaign_agent( $user_id ) ) {
			return true;
		}

		return self::has_project_access( $user_id )
			|| self::has_support_access( $user_id )
			|| self::has_booking_access( $user_id )
			|| self::can_access_automations( $user_id );
	}

	/**
	 * Whether the user sees a contact's website visits: administrators, CRM
	 * Managers, CRM Agents, Sales Managers and Sales Reps.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_view_website_tracking( $user_id = null ) {
		return self::has_agent_access( $user_id );
	}

	/**
	 * Whether the user may edit and delete EVERY contact: administrators and
	 * CRM Managers. Everyone else changes only the contacts they own; module
	 * managers and collaborators view the rest.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_edit_any_contact( $user_id = null ) {
		return self::is_crm_manager( $user_id );
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
		if ( self::has_agent_access( $user_id ) ) {
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
		if ( self::has_agent_access( $user_id ) ) {
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
		// An agent limited to their own contacts messages only contacts they
		// can see; anything else answers like a missing contact.
		if ( self::is_scoped_agent() ) {
			$contact = \DoubleScale\Modules\Contacts\Models\ContactModel::find( absint( $contact_id ) );
			if ( $contact && \DoubleScale\Core\Scope\OwnerScope::current()->can_view_contact( $contact ) ) {
				return true;
			}
			return new WP_Error( 'not_found', __( 'Contact not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( self::has_agent_access() ) {
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
			|| ! doublescale_module_data_ready( 'projects' ) ) {
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
			UserRoles::CRM_AGENT,
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

		if ( array_intersect( self::automation_roles(), $roles ) ) {
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
			UserRoles::CRM_AGENT,
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

		if ( array_intersect( self::automation_roles(), $roles ) ) {
			return false;
		}

		// Help Center staff keep their articles in the menu.
		if ( array_intersect( self::help_center_roles(), $roles ) ) {
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
			UserRoles::CRM_AGENT,
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

		if ( array_intersect( self::automation_roles(), $roles ) ) {
			return false;
		}

		// Help Center staff keep their articles in the menu.
		if ( array_intersect( self::help_center_roles(), $roles ) ) {
			return false;
		}

		$project_roles = array( UserRoles::PROJECT_MANAGER, UserRoles::PROJECT_MEMBER );
		return (bool) array_intersect( $project_roles, $roles );
	}

	/**
	 * The two automation roles.
	 *
	 * @return array<int, string>
	 */
	private static function automation_roles(): array {
		return array( UserRoles::AUTOMATION_MANAGER, UserRoles::AUTOMATION_AGENT );
	}

	/**
	 * Whether the user's ONLY DoubleScale roles are automation roles. Scopes
	 * the admin menu down to Automations for dedicated automation staff.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_automation_only( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user || user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		$roles = (array) $user->roles;
		$other = array(
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
			UserRoles::SUPPORT_MANAGER,
			UserRoles::SUPPORT_AGENT,
			UserRoles::BOOKING_MANAGER,
			UserRoles::BOOKING_AGENT,
			UserRoles::PROJECT_MANAGER,
			UserRoles::PROJECT_MEMBER,
			UserRoles::HELP_CENTER_MANAGER,
			UserRoles::HELP_CENTER_AGENT,
		);
		if ( array_intersect( $other, $roles ) ) {
			return false;
		}

		return (bool) array_intersect( self::automation_roles(), $roles );
	}

	/**
	 * The two help center roles.
	 *
	 * @return array<int, string>
	 */
	private static function help_center_roles(): array {
		return array( UserRoles::HELP_CENTER_MANAGER, UserRoles::HELP_CENTER_AGENT );
	}

	/**
	 * Whether the user's ONLY DoubleScale roles are help center roles. Scopes
	 * the admin menu down to the Help Center (plus the Dashboard and Tasks
	 * every team role has) for people who only write help articles.
	 *
	 * Helpdesk staff who also write articles stay "support only": the
	 * Helpdesk landing keeps the Help Center beside the inbox.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function is_help_center_only( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user || user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		$roles = (array) $user->roles;
		$other = array_diff( UserRoles::get_known_role_slugs(), self::help_center_roles() );
		if ( array_intersect( $other, $roles ) ) {
			return false;
		}

		// With the help center off there is no Help Center page to land on
		// (the admin would redirect to it forever).
		return (bool) array_intersect( self::help_center_roles(), $roles )
			&& UserRoles::is_role_module_enabled( UserRoles::HELP_CENTER_MANAGER );
	}

	/**
	 * Whether the user may open the Automations module at all (their own
	 * workflows at least). Administrators and CRM Managers always may.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_access_automations( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		return self::has_crm_manager_access( $user_id ) || user_can( $user_id, 'doublescale_view_automations' );
	}

	/**
	 * Whether the user sees and edits every workflow, and gets every
	 * automation alert: administrators, CRM Managers and Automation Managers.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_manage_all_automations( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		return self::has_crm_manager_access( $user_id ) || user_can( $user_id, 'doublescale_manage_all_automations' );
	}

	/**
	 * Whether the user may open and change one workflow: any workflow for
	 * managers, only the ones they created for Automation Agents.
	 *
	 * @param object|int $automation Automation model (or id).
	 * @param int|null   $user_id    User ID (null for current user)
	 * @return bool
	 */
	public static function can_edit_automation( $automation, $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		if ( self::can_manage_all_automations( $user_id ) ) {
			return true;
		}
		if ( ! user_can( $user_id, 'doublescale_manage_own_automations' ) ) {
			return false;
		}
		if ( is_numeric( $automation ) ) {
			$automation = \DoubleScale\Modules\Automations\Models\AutomationModel::find( (int) $automation );
		}

		return $automation && (int) $automation->created_by === (int) $user_id && $user_id > 0;
	}


	/**
	 * Whether the user may permanently delete contacts (single or bulk).
	 *
	 * Sales Reps must never delete contacts — including when they also hold the
	 * WordPress `administrator` role but are scoped to Sales Rep in Team settings.
	 * Administrators and CRM Managers delete any contact; everyone else who
	 * reads contacts deletes only the ones they own (checked per record).
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_delete_contacts( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		if (
			self::user_has_role( UserRoles::SALES_REP, $user_id )
			&& ! self::user_has_role( UserRoles::SALES_MANAGER, $user_id )
			&& ! self::user_has_role( UserRoles::CRM_MANAGER, $user_id )
		) {
			return false;
		}

		if ( self::can_edit_any_contact( $user_id ) ) {
			return true;
		}

		// Everyone else deletes only the contacts they OWN; the delete
		// handlers enforce ownership per record (collaborating is not enough,
		// and a module manager's team-wide view is read only).
		return self::can_read_contacts( $user_id );
	}

	/**
	 * Whether the user works with customers: an administrator, a CRM Manager,
	 * or anyone holding a CRM, Sales, Support, Booking, Project, Automation or
	 * Campaign role. NOT the Help Center roles (articles only) and not Team
	 * Member (add-on areas only): they hold `doublescale_access` for the admin
	 * shell, which is no proof they may open customer data or shared settings.
	 *
	 * Gate shared-inbox, shared-email and webhook-address routes with this (or a
	 * stricter check), never with `doublescale_access` alone.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function has_crm_or_support_access( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );
		if ( $user_id <= 0 || ! user_can( $user_id, 'doublescale_access' ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) || self::is_crm_manager( $user_id ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$customer_roles = array_diff(
			UserRoles::get_known_role_slugs(),
			array( UserRoles::HELP_CENTER_MANAGER, UserRoles::HELP_CENTER_AGENT, UserRoles::TEAM_MEMBER )
		);

		return (bool) array_intersect( $customer_roles, (array) $user->roles );
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
	/**
	 * Sales-tier access for working records: Sales Rep and above, plus the
	 * CRM Agent. Holding it says nothing about *which* records — callers
	 * narrow with {@see \DoubleScale\Core\Scope\OwnerScope}.
	 *
	 * Kept separate from has_sales_rep_access() so the CRM Agent is added to
	 * each surface deliberately (team reports, for example, still are not).
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function has_agent_access( $user_id = null ) {
		$user_id = self::set_current_user_id( $user_id );

		return self::has_sales_rep_access( $user_id ) || self::is_crm_agent( $user_id );
	}

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

		// Provider must be configured for features that CALL a provider.
		// Deliberately not part of has_ai_role_access(): see that method.
		if ( empty( $ai_settings['provider'] ) ) {
			return new \WP_Error( 'ai_not_configured', __( 'AI provider not configured.', 'doublescale' ), array( 'status' => 400 ) );
		}

		return self::has_ai_role_access( $user_id );
	}

	/**
	 * Whether this user's ROLE may use AI-facing features.
	 *
	 * The role half of {@see has_ai_access()}, without the provider check.
	 *
	 * Those are two unrelated questions, and merging them broke MCP. Outbound
	 * features (writing an email, summarising a ticket) genuinely need a
	 * configured provider — DoubleScale is the one making the API call. MCP is
	 * the opposite direction: an external agent connects IN and reads CRM data
	 * through the abilities layer, and nothing on this site calls a provider at
	 * all. Requiring an OpenAI key before Claude may read an invoice is a
	 * condition with no mechanism behind it.
	 *
	 * The role gate still applies to both, because "who may reach CRM data
	 * through an AI tool" is a real question the administrator answers in
	 * Settings → AI.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return true|\WP_Error
	 */
	public static function has_ai_role_access( $user_id = null ) {
		$ai_settings = Settings::get( 'ai', array() );
		$access      = $ai_settings['access'] ?? self::default_ai_access();

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

		// Priority order: Administrator > CRM Manager > CRM Agent > Sales Manager
		// > Sales Rep > Support Manager > Support Agent > Booking Manager
		// > Booking Agent > Project Manager > Project Member > Automation Manager
		// > Automation Agent > Campaign Manager > Campaign Agent
		// > Help Center Manager > Help Center Agent > Team Member. Every role in
		// UserRoles::get_roles() must be listed, or its holders count as having
		// none (TeamRoleDashboardTest).
		$priority = array(
			UserRoles::ADMINISTRATOR,
			UserRoles::CRM_MANAGER,
			UserRoles::CRM_AGENT,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
			UserRoles::SUPPORT_MANAGER,
			UserRoles::SUPPORT_AGENT,
			UserRoles::BOOKING_MANAGER,
			UserRoles::BOOKING_AGENT,
			UserRoles::PROJECT_MANAGER,
			UserRoles::PROJECT_MEMBER,
			UserRoles::AUTOMATION_MANAGER,
			UserRoles::AUTOMATION_AGENT,
			UserRoles::CAMPAIGN_MANAGER,
			UserRoles::CAMPAIGN_AGENT,
			UserRoles::HELP_CENTER_MANAGER,
			UserRoles::HELP_CENTER_AGENT,
			UserRoles::TEAM_MEMBER,
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
