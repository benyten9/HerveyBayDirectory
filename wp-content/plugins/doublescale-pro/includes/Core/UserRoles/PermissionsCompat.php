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
	 * Campaigns, templates, sequences, forms, lists and tags (CRM Manager or
	 * Campaign Manager). Falls back to CRM Manager access on an older Free.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function can_manage_campaigns( $user_id = null ) {
		if ( method_exists( Permissions::class, 'can_manage_campaigns' ) ) {
			return Permissions::can_manage_campaigns( $user_id );
		}

		return Permissions::has_crm_manager_access( $user_id );
	}

	/**
	 * Runs campaigns and sequences: campaign managers, and Campaign Agents
	 * for their own. Falls back to {@see can_manage_campaigns()}.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_use_campaigns( $user_id = null ) {
		if ( method_exists( Permissions::class, 'can_use_campaigns' ) ) {
			return Permissions::can_use_campaigns( $user_id );
		}

		return self::can_manage_campaigns( $user_id );
	}

	/**
	 * The user whose own contacts a campaign of theirs is limited to, or null.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return int|null
	 */
	public static function campaign_audience_owner( $user_id = null ) {
		if ( method_exists( Permissions::class, 'campaign_audience_owner' ) ) {
			return Permissions::campaign_audience_owner( $user_id );
		}

		return null;
	}

	/**
	 * Sales-tier access including the CRM Agent (records are scoped separately).
	 *
	 * Falls back to sales rep access when the free plugin is outdated.
	 *
	 * @param int|null $user_id User ID (null for current user)
	 * @return bool
	 */
	public static function has_agent_access( $user_id = null ) {
		if ( method_exists( Permissions::class, 'has_agent_access' ) ) {
			return Permissions::has_agent_access( $user_id );
		}

		return Permissions::has_sales_rep_access( $user_id );
	}

	/**
	 * Check if the user has project access.
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

	// ------------------------------------------------------------------
	// Team roles (Free 1.5.3+). On an older Free there is no CRM Agent, no
	// contact owners and no OwnerScope: everything answers as before.
	// ------------------------------------------------------------------

	/**
	 * The CRM Agent role slug, or null when the free plugin predates it.
	 *
	 * @return string|null
	 */
	public static function crm_agent_role() {
		return defined( UserRoles::class . '::CRM_AGENT' ) ? constant( UserRoles::class . '::CRM_AGENT' ) : null;
	}

	/**
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_crm_agent( $user_id = null ) {
		return method_exists( Permissions::class, 'is_crm_agent' ) && Permissions::is_crm_agent( $user_id );
	}

	/**
	 * An agent-level user: an agent role and no manager role (and not an
	 * administrator or CRM Manager). Older Free: the agent roles, minus anyone
	 * holding a manager role.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_agent_tier( $user_id = null ) {
		if ( method_exists( Permissions::class, 'is_agent_tier' ) ) {
			return Permissions::is_agent_tier( $user_id );
		}

		$user = get_userdata( null === $user_id ? get_current_user_id() : (int) $user_id );
		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return false;
		}
		$roles    = (array) $user->roles;
		$managers = array( UserRoles::CRM_MANAGER, UserRoles::SALES_MANAGER, UserRoles::PROJECT_MANAGER );
		$agents   = array_filter( array( self::crm_agent_role(), UserRoles::SALES_REP, UserRoles::PROJECT_MEMBER ) );

		return ! array_intersect( $managers, $roles ) && (bool) array_intersect( $agents, $roles );
	}

	/**
	 * An agent-level user limited to their own contacts.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function is_scoped_agent( $user_id = null ) {
		return method_exists( Permissions::class, 'is_scoped_agent' ) && Permissions::is_scoped_agent( $user_id );
	}

	/**
	 * Whether the user sees every deal. Older Free: everyone but a Sales Rep
	 * without sales manager access (the rule before OwnerScope).
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function sees_all_deals( $user_id = null ) {
		if ( class_exists( \DoubleScale\Core\Scope\OwnerScope::class ) ) {
			$scope = null === $user_id ? \DoubleScale\Core\Scope\OwnerScope::current() : \DoubleScale\Core\Scope\OwnerScope::for_user( (int) $user_id );
			return $scope->sees_all( 'deals' );
		}

		return ! ( Permissions::is_sales_rep( $user_id ) && ! self::has_sales_manager_access( $user_id ) );
	}

	/**
	 * Whether the user's sales document lists show every record. Older Free:
	 * managers and anyone who may assign sales reps.
	 *
	 * @return bool
	 */
	public static function sees_all_sales_records() {
		$caps = \DoubleScale\Modules\Sales\Capabilities::class;
		if ( method_exists( $caps, 'sees_all_records' ) ) {
			return $caps::sees_all_records();
		}

		return $caps::can_manage_all_sales() || $caps::can_assign_sales_rep();
	}

	/**
	 * Whether the current user may see this contact (always, on an older Free).
	 *
	 * @param object|null $contact Contact model.
	 * @return bool
	 */
	public static function can_view_contact( $contact ) {
		if ( ! $contact ) {
			return false;
		}
		if ( ! class_exists( \DoubleScale\Core\Scope\OwnerScope::class ) ) {
			return true;
		}
		$scope = \DoubleScale\Core\Scope\OwnerScope::current();

		return $scope->sees_all( 'contacts' ) || $scope->can_view_contact( $contact );
	}

	/**
	 * Why the current user may not link a record to this contact, or null.
	 *
	 * @param int $contact_id Contact ID.
	 * @return mixed|null
	 */
	public static function contact_reference_error( int $contact_id ) {
		if ( ! method_exists( \DoubleScale\Core\Scope\OwnerScope::class, 'contact_reference_error' ) ) {
			return null;
		}

		return \DoubleScale\Core\Scope\OwnerScope::contact_reference_error( $contact_id );
	}

	/**
	 * Who sees a contact's website visits. Older Free: CRM Managers.
	 *
	 * @return bool
	 */
	public static function can_view_website_tracking() {
		if ( method_exists( Permissions::class, 'can_view_website_tracking' ) ) {
			return Permissions::can_view_website_tracking();
		}

		return Permissions::has_crm_manager_access();
	}

	/**
	 * Website tracking is on: the module switch, else the old Settings →
	 * Website Tracking toggle (see WebsiteTracking\Module::switched_on()).
	 * Not delegated to Free: before 1.9.10 Free read only the old toggle.
	 *
	 * @return bool
	 */
	public static function website_tracking_enabled() {
		return (bool) apply_filters( 'doublescale_website_tracking_enabled', \DoubleScale\Pro\Modules\WebsiteTracking\Module::switched_on() );
	}

	/**
	 * Create a contact under a lead routing source (Free picks its owner);
	 * an older Free just creates it.
	 *
	 * @param string   $source  Lead routing source.
	 * @param array    $context Routing context.
	 * @param callable $create  Creates and returns the contact.
	 * @return mixed
	 */
	public static function create_routed_contact( string $source, array $context, callable $create ) {
		$router = \DoubleScale\Modules\Contacts\Services\LeadRouter::class;
		if ( class_exists( $router ) && method_exists( $router, 'with_context' ) ) {
			return $router::with_context( $source, $context, $create );
		}

		return $create();
	}

	/**
	 * Create a contact for a workflow (it gets the workflow's owner); an
	 * older Free just creates it.
	 *
	 * @param object   $automation Automation.
	 * @param callable $create     Creates and returns the contact.
	 * @return mixed
	 */
	public static function create_workflow_contact( $automation, callable $create ) {
		$enrollment = \DoubleScale\Modules\Automations\Engine\ContactEnrollment::class;
		if ( class_exists( $enrollment ) && method_exists( $enrollment, 'creating_for' ) ) {
			return $enrollment::creating_for( $automation, $create );
		}

		return $create();
	}
}
