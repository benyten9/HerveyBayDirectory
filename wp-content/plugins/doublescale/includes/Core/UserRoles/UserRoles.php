<?php

/**
 * Class UserRoles
 *
 * This class is responsible for handling the CRM user roles
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\UserRoles;

defined( 'ABSPATH' ) || exit;

/**
 * UserRoles class
 *
 * Manages the CRM role system:
 * - CRM Manager/Admin (doublescale_crm_manager)
 * - Sales Manager (doublescale_sales_manager)
 * - Sales Rep (doublescale_sales_rep)
 * - Automation Manager / Automation Agent (doublescale_automation_manager / _agent)
 * - Help Center Manager / Help Center Agent (doublescale_help_center_manager / _agent):
 *   articles only; neither opens tickets, customer chats, contacts or shared settings
 */
final class UserRoles {


	public const PREFIX          = 'doublescale_';
	public const CRM_MANAGER     = self::PREFIX . 'crm_manager';
	// A CRM Manager scoped to their own records in every module (Pro only).
	public const CRM_AGENT       = self::PREFIX . 'crm_agent';
	public const SALES_MANAGER   = self::PREFIX . 'sales_manager';
	public const SALES_REP       = self::PREFIX . 'sales_rep';
	public const SUPPORT_MANAGER = self::PREFIX . 'support_manager';
	public const SUPPORT_AGENT   = self::PREFIX . 'support_agent';
	public const BOOKING_MANAGER = self::PREFIX . 'booking_manager';
	public const BOOKING_AGENT   = self::PREFIX . 'booking_agent';
	public const PROJECT_MANAGER = self::PREFIX . 'project_manager';
	public const PROJECT_MEMBER  = self::PREFIX . 'project_member';
	public const AUTOMATION_MANAGER = self::PREFIX . 'automation_manager';
	/** Campaigns, email templates, sequences, forms, lists and tags — for every contact. */
	public const CAMPAIGN_MANAGER = self::PREFIX . 'campaign_manager';
	public const CAMPAIGN_AGENT   = self::PREFIX . 'campaign_agent';
	public const AUTOMATION_AGENT   = self::PREFIX . 'automation_agent';
	/** Every help-center article and category (the knowledge base). */
	public const HELP_CENTER_MANAGER = self::PREFIX . 'help_center_manager';
	/** Their own help-center articles; reads everyone's. */
	public const HELP_CENTER_AGENT   = self::PREFIX . 'help_center_agent';

	/**
	 * WordPress's own capabilities the Help Center roles hold: uploading
	 * images and files into their articles (the block editor's image block).
	 * Kept out of `kb_common` on purpose: that list is also what is taken
	 * away from everyone while the help center is off, and `upload_files`
	 * belongs to administrators and editors too. Goes with the role itself.
	 */
	private const HELP_CENTER_MEDIA_CAPS = array( 'upload_files' );
	/**
	 * Base role for someone who works only in an add-on's areas (for example
	 * custom object types): admin access and nothing else. The add-on that
	 * stores what they may work on switches it on through the
	 * `doublescale_team_member_role_enabled` filter.
	 */
	public const TEAM_MEMBER        = self::PREFIX . 'team_member';
	public const ADMINISTRATOR   = 'administrator';
	public const NONE            = self::PREFIX . 'none';


	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var UserRoles
	 */
	private static $instance;

	/**
	 * User Roles Instance.
	 *
	 * Instantiates or reuses an instance of UserRoles.
	 *
	 * @since 1.0.0
	 *
	 * @return UserRoles
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {}



	/**
	 * WordPress admin setup
	 *
	 * Add CRM capabilities to WordPress administrators
	 *
	 * @since 1.0.0
	 */
	public static function add_roles_and_capabilities() {
		$roles = self::get_roles();

		foreach ( $roles as $role => $label ) {
			if ( ! self::is_role_module_enabled( $role ) ) {
				continue;
			}

			self::provision_role( $role );
		}

		// Administrators always get every CRM + Support cap so they can see
		// the entire admin regardless of which roles are provisioned.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_capabilities = array_merge(
				self::get_crm_manager_capabilities(),
				self::get_support_manager_capabilities()
			);
			foreach ( array_unique( $admin_capabilities ) as $capability ) {
				$admin_role->add_cap( $capability, true );
			}
		}
	}

	/**
	 * Add one DoubleScale role with its caps if missing, else sync caps.
	 *
	 * @param string $role Role slug from {@see get_roles()}.
	 * @return void
	 */
	public static function provision_role( string $role ): void {
		$roles = self::get_roles();
		if ( ! isset( $roles[ $role ] ) ) {
			return;
		}

		$capabilities = self::get_role_capability_list( $role );
		if ( null === $capabilities ) {
			return;
		}

		$capabilities = array_fill_keys( $capabilities, true );
		$label        = $roles[ $role ];

		if ( ! get_role( $role ) ) {
			add_role( $role, $label, $capabilities );
			return;
		}

		$role_obj = get_role( $role );

		foreach ( $capabilities as $cap => $grant ) {
			$role_obj->add_cap( $cap, $grant );
		}

		foreach ( self::all_capabilities() as $known_cap ) {
			if ( 0 !== strpos( $known_cap, self::PREFIX ) ) {
				continue;
			}
			if ( ! isset( $capabilities[ $known_cap ] ) && $role_obj->has_cap( $known_cap ) ) {
				$role_obj->remove_cap( $known_cap );
			}
		}
	}

	/**
	 * Remove a DoubleScale role definition from `wp_user_roles` only. User
	 * assignments in `wp_usermeta` are preserved so re-enabling the module
	 * restores caps without re-assigning the team.
	 *
	 * @param string $role Role slug.
	 * @return void
	 */
	public static function deprovision_role( string $role ): void {
		if ( get_role( $role ) ) {
			remove_role( $role );
		}
	}

	/**
	 * @return void
	 */
	public static function provision_support_roles(): void {
		self::provision_role( self::SUPPORT_MANAGER );
		self::provision_role( self::SUPPORT_AGENT );

		// The help center runs inside the Helpdesk: switching the Helpdesk
		// back on brings its roles back when its own toggle is still on.
		if ( self::is_role_module_enabled( self::HELP_CENTER_MANAGER ) ) {
			self::provision_kb_roles();
		}
	}

	/**
	 * @return void
	 */
	public static function deprovision_support_roles(): void {
		self::deprovision_role( self::SUPPORT_MANAGER );
		self::deprovision_role( self::SUPPORT_AGENT );
		// Helpdesk off means the help center is off too.
		self::deprovision_kb_roles();
	}

	/**
	 * Help Center Manager + Agent (the knowledge base module).
	 *
	 * @return void
	 */
	public static function provision_kb_roles(): void {
		self::provision_role( self::HELP_CENTER_MANAGER );
		self::provision_role( self::HELP_CENTER_AGENT );
	}

	/**
	 * @return void
	 */
	public static function deprovision_kb_roles(): void {
		self::deprovision_role( self::HELP_CENTER_MANAGER );
		self::deprovision_role( self::HELP_CENTER_AGENT );
	}

	/**
	 * @return void
	 */
	public static function provision_booking_roles(): void {
		self::provision_role( self::BOOKING_MANAGER );
		self::provision_role( self::BOOKING_AGENT );
	}

	/**
	 * @return void
	 */
	public static function deprovision_booking_roles(): void {
		self::deprovision_role( self::BOOKING_MANAGER );
		self::deprovision_role( self::BOOKING_AGENT );
	}

	/**
	 * @return void
	 */
	public static function provision_project_roles(): void {
		self::provision_role( self::PROJECT_MANAGER );
		self::provision_role( self::PROJECT_MEMBER );
	}

	/**
	 * @return void
	 */
	public static function deprovision_project_roles(): void {
		self::deprovision_role( self::PROJECT_MANAGER );
		self::deprovision_role( self::PROJECT_MEMBER );
	}

	/**
	 * Provision Sales Rep + Sales Manager only. CRM Manager is org admin and
	 * is not tied to the deals module toggle.
	 *
	 * @return void
	 */
	public static function provision_crm_roles(): void {
		self::provision_role( self::SALES_REP );
		self::provision_role( self::SALES_MANAGER );
	}

	/**
	 * @return void
	 */
	public static function deprovision_crm_roles(): void {
		self::deprovision_role( self::SALES_REP );
		self::deprovision_role( self::SALES_MANAGER );
	}

	/**
	 * DoubleScale roles owned by a toggleable module (for disable warnings).
	 *
	 * @param string $module_slug Module slug (`support`, `deals`, …).
	 * @return array<int, string>
	 */
	public static function get_role_slugs_for_module( string $module_slug ): array {
		if ( 'support' === $module_slug ) {
			// The help center goes with the Helpdesk, so its roles do too.
			return array( self::SUPPORT_MANAGER, self::SUPPORT_AGENT, self::HELP_CENTER_MANAGER, self::HELP_CENTER_AGENT );
		}

		if ( 'knowledge_base' === $module_slug ) {
			return array( self::HELP_CENTER_MANAGER, self::HELP_CENTER_AGENT );
		}

		if ( 'booking' === $module_slug ) {
			return array( self::BOOKING_MANAGER, self::BOOKING_AGENT );
		}

		if ( 'projects' === $module_slug ) {
			return array( self::PROJECT_MANAGER, self::PROJECT_MEMBER );
		}

		if ( 'automations' === $module_slug ) {
			return array( self::AUTOMATION_MANAGER, self::AUTOMATION_AGENT );
		}

		if ( 'campaigns' === $module_slug ) {
			return array( self::CAMPAIGN_MANAGER, self::CAMPAIGN_AGENT );
		}

		// The pipeline (`deals`) is a child of Sales and no longer owns roles:
		// disabling only the pipeline keeps SALES_REP / SALES_MANAGER alive,
		// so it must not trigger the role-loss warning.
		if ( 'sales' === $module_slug ) {
			return array( self::SALES_REP, self::SALES_MANAGER );
		}

		return array();
	}

	/**
	 * Team members who currently hold a role tied to the given module.
	 *
	 * @param string $module_slug Module slug.
	 * @return array<int, array{id: int, name: string, email: string, roles: array<int, string>}>
	 */
	public static function get_users_with_module_roles( string $module_slug ): array {
		$roles = self::get_role_slugs_for_module( $module_slug );
		if ( empty( $roles ) ) {
			return array();
		}

		$users   = get_users(
			array(
				'role__in' => $roles,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		$payload = array();

		foreach ( $users as $user ) {
			$matched_roles = array_values( array_intersect( (array) $user->roles, $roles ) );
			if ( empty( $matched_roles ) ) {
				continue;
			}

			$payload[] = array(
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'email' => (string) $user->user_email,
				'roles' => $matched_roles,
			);
		}

		return $payload;
	}

	/**
	 * Role slugs that require DoubleScale Pro (CRM + pipeline/deals roles).
	 *
	 * @return array<int, string>
	 */
	public static function get_pro_role_slugs(): array {
		return array(
			self::CRM_MANAGER,
			self::SALES_MANAGER,
			self::SALES_REP,
			self::PROJECT_MANAGER,
			self::PROJECT_MEMBER,
		);
	}

	/**
	 * Provision every Pro-gated role. Sales roles are provisioned when either
	 * owning module (Sales parent or its pipeline child) is active, keeping
	 * the invariant "role definitions exist iff is_role_module_enabled()"
	 * coherent from every entry point.
	 *
	 * @return void
	 */
	public static function provision_pro_roles(): void {
		if ( ! self::is_pro_addon_active() ) {
			return;
		}

		self::provision_role( self::CRM_MANAGER );
		self::provision_role( self::CRM_AGENT );

		if ( ! function_exists( 'doublescale_is_module_active' )
			|| doublescale_is_module_active( 'deals' )
			|| doublescale_is_module_active( 'sales' ) ) {
			self::provision_crm_roles();
		}

		if ( function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'projects' ) ) {
			self::provision_project_roles();
		}
	}

	/**
	 * Remove Pro-gated role definitions from wp_user_roles. User assignments
	 * in usermeta are preserved for when Pro is re-activated.
	 *
	 * @return void
	 */
	public static function deprovision_pro_roles(): void {
		self::deprovision_role( self::CRM_MANAGER );
		self::deprovision_role( self::CRM_AGENT );
		self::deprovision_crm_roles();
		self::deprovision_project_roles();
	}

	/**
	 * @return bool
	 */
	public static function is_pro_addon_active(): bool {
		if ( function_exists( 'doublescale_is_pro_addon_active' ) ) {
			return doublescale_is_pro_addon_active();
		}

		return defined( 'DOUBLESCALE_PRO_PLUGIN_FILE' );
	}

	/**
	 * @param string $plugin Plugin basename relative to wp-content/plugins.
	 * @return void
	 */
	public static function handle_pro_plugin_activated( string $plugin ): void {
		if ( ! self::is_pro_plugin_basename( $plugin ) ) {
			return;
		}

		self::provision_pro_roles();
	}

	/**
	 * @param string $plugin Plugin basename relative to wp-content/plugins.
	 * @return void
	 */
	public static function handle_pro_plugin_deactivated( string $plugin ): void {
		if ( ! self::is_pro_plugin_basename( $plugin ) ) {
			return;
		}

		self::deprovision_pro_roles();
	}

	/**
	 * Whether a plugin basename refers to the DoubleScale Pro main file (any folder name).
	 *
	 * @param string $plugin Plugin basename relative to wp-content/plugins.
	 * @return bool
	 */
	private static function is_pro_plugin_basename( string $plugin ): bool {
		if ( function_exists( 'doublescale_get_pro_plugin_basenames' ) ) {
			$needle = strtolower( $plugin );
			foreach ( doublescale_get_pro_plugin_basenames() as $basename ) {
				if ( strtolower( $basename ) === $needle ) {
					return true;
				}
			}
		}

		return (bool) preg_match( '#/doublescale-pro\.php$#i', $plugin );
	}

	/**
	 * @param string $role Role slug.
	 * @return array<int, string>|null
	 */
	private static function get_role_capability_list( string $role ): ?array {
		if ( $role === self::CRM_MANAGER ) {
			return self::get_crm_manager_capabilities();
		}
		if ( $role === self::CRM_AGENT ) {
			return self::get_crm_agent_capabilities();
		}
		if ( $role === self::SALES_MANAGER ) {
			return self::get_sales_manager_capabilities();
		}
		if ( $role === self::SALES_REP ) {
			return self::get_sales_rep_capabilities();
		}
		if ( $role === self::SUPPORT_MANAGER ) {
			return self::get_support_manager_capabilities();
		}
		if ( $role === self::SUPPORT_AGENT ) {
			return self::get_support_agent_capabilities();
		}
		if ( $role === self::BOOKING_MANAGER ) {
			return self::get_booking_manager_capabilities();
		}
		if ( $role === self::BOOKING_AGENT ) {
			return self::get_booking_agent_capabilities();
		}
		if ( $role === self::PROJECT_MANAGER ) {
			return self::get_project_manager_capabilities();
		}
		if ( $role === self::PROJECT_MEMBER ) {
			return self::get_project_member_capabilities();
		}
		if ( $role === self::CAMPAIGN_MANAGER ) {
			return self::get_campaign_manager_capabilities();
		}
		if ( $role === self::CAMPAIGN_AGENT ) {
			return self::get_campaign_agent_capabilities();
		}
		if ( $role === self::AUTOMATION_MANAGER ) {
			return self::get_automation_manager_capabilities();
		}
		if ( $role === self::AUTOMATION_AGENT ) {
			return self::get_automation_agent_capabilities();
		}
		if ( $role === self::HELP_CENTER_MANAGER ) {
			return self::get_help_center_manager_capabilities();
		}
		if ( $role === self::HELP_CENTER_AGENT ) {
			return self::get_help_center_agent_capabilities();
		}
		if ( $role === self::TEAM_MEMBER ) {
			return self::get_team_member_capabilities();
		}

		return null;
	}

	/**
	 * Remove capabilities from role
	 *
	 * @since 1.0.0
	 *
	 * @param string $role Role name
	 */
	public static function remove_role_and_capabilities( $user_id ) {
		$user  = get_user_by( 'ID', $user_id );
		$roles = $user->roles;
		foreach ( $roles as $role ) {
			foreach ( self::all_capabilities() as $capability ) {
				$user->remove_cap( $capability );
			}
			$user->remove_role( $role );
		}
	}



	/**
	 * Get CRM capabilities grouped by role
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of capabilities organized by role
	 */
	private static function get_capabilities() {
		return array(
			'common'              => array(
				'doublescale_access',           // Basic CRM access (top-level menu + REST)
				'read',                         // For Wordpress
				'view_admin_dashboard',         // WP/WC: lets the user reach wp-admin
				                                // even when they don't have edit_posts
				                                // or manage_woocommerce. Required so
				                                // `WC_Admin::prevent_admin_access` and
				                                // `wc_disable_admin_bar` don't kick
				                                // CRM/Support users out to `/my-account/`.
			),
			'crm_common'          => array(
				'doublescale_view_contacts',    // View contacts
				'doublescale_view_deals',       // View deals
				'doublescale_view_activities',  // View activities
				// NOTE: Support is intentionally NOT granted to CRM roles.
				// Support access (view_support / manage_all_tickets /
				// reply_own_tickets) is exclusive to the dedicated support
				// roles. A CRM user (CRM Manager / Sales Manager / Sales Rep)
				// only sees the Support module if an admin ALSO assigns them a
				// support role. See get_capabilities() support_* entries.
			),
			'support_common'      => array(
				'doublescale_view_support',     // View support tickets (own-scope by default)
			),
			// Automations: an agent builds and runs their own workflows; a
			// manager sees and edits every workflow. Ownership is the
			// automation's `created_by`.
			'automation_common'   => array(
				'doublescale_view_automations',        // The Automations module (own scope by default)
				'doublescale_manage_own_automations',  // Create, edit and delete own workflows
			),
			self::AUTOMATION_AGENT   => array(),
			self::AUTOMATION_MANAGER => array(
				'doublescale_manage_all_automations',  // See and edit every workflow
			),
			// Help center (knowledge base): an agent reads every article and
			// writes their own; a manager changes every article, the
			// categories, the order, the import and the page address.
			// Ownership is the article's `author_id`. None of these open
			// tickets: helpdesk access stays with the support roles.
			'kb_common'               => array(
				'doublescale_view_kb',   // Every article in the admin, any status
				'doublescale_write_kb',  // Create articles; edit, publish and delete their own
			),
			self::HELP_CENTER_AGENT   => array(),
			self::HELP_CENTER_MANAGER => array(
				'doublescale_manage_kb', // Every article and category
			),
			self::CAMPAIGN_MANAGER  => array(
				'doublescale_view_contacts',           // Build audiences from every contact
				'doublescale_manage_campaigns',        // Every campaign, sequence and email template
			),
			self::CAMPAIGN_AGENT    => array(
				'doublescale_view_contacts',           // Their own contacts (scoped by contact visibility)
				'doublescale_manage_own_campaigns',    // Their own campaigns and sequences, to their own contacts
			),
			self::SALES_REP       => array(
				'doublescale_edit_own_deals',     // Edit own deals
				'doublescale_create_deals',       // Create new deals (assigned to self)
				'doublescale_edit_own_contacts',  // Edit own contacts
				'doublescale_create_contacts',    // Create new contacts
				'doublescale_create_activities',  // Create activities
				'doublescale_view_sales',         // View proposals and invoices
				'doublescale_manage_own_sales',   // Manage own proposals/invoices
				// Support caps removed — granted only by support roles.
			),
			self::SALES_MANAGER   => array(
				'doublescale_manage_deals',       // Manage all deals (CRUD for all deals)
				'doublescale_view_all_deals',     // View all deals (assigned to anyone)
				'doublescale_create_activities',  // Create activities
				'doublescale_manage_contacts',    // Manage all contacts (create, edit, delete)
				'doublescale_import_data',        // Import data
				'doublescale_export_data',        // Export data
				'doublescale_view_sales',         // View proposals and invoices
				'doublescale_manage_all_sales',   // Manage all proposals/invoices
				// Support caps removed — granted only by support roles.
			),
			self::CRM_MANAGER     => array(
				'doublescale_manage',             // Full CRM management
				'doublescale_manage_users',       // Manage CRM users
				'doublescale_manage_settings',    // Manage CRM settings
				'doublescale_manage_contacts',    // Manage all contacts
				'doublescale_manage_deals',       // Manage all deals
				'doublescale_view_all_deals',     // View all deals
				'doublescale_manage_pipelines',   // Manage pipelines
				'doublescale_manage_activities',  // Manage all activities
				'doublescale_view_reports',       // View reports
				'doublescale_export_data',        // Export data
				'doublescale_import_data',        // Import data
				// CRM Manager is the org admin of the CRM — treated like a WP
				// admin for Support: full access (inbox, all tickets, settings)
				// WITHOUT needing a separate support role. Sales Manager / Sales
				// Rep do NOT get this; they still need an explicit support role.
				'doublescale_view_support',       // See the Support module
				'doublescale_manage_all_tickets', // See and manage every ticket
				'doublescale_reply_own_tickets',  // Reply on tickets
				'doublescale_view_sales',         // View proposals and invoices
				'doublescale_manage_all_sales',   // Manage all proposals/invoices
				'doublescale_view_automations',        // CRM Manager runs every workflow
				'doublescale_manage_own_automations',
				'doublescale_manage_all_automations',
				'list_users',                  // For Wordpress List users
			),
			// CRM Agent: every module a CRM Manager has, on their OWN records only
			// (see {@see Permissions::sees_all_records()}). The role's cap list is
			// assembled in {@see get_crm_agent_capabilities()} from the agent-tier
			// groups of each module; these are the agent's own extras.
			self::CRM_AGENT       => array(
				'doublescale_manage_own_campaigns', // Their own campaigns, sent to their own contacts
				'doublescale_manage_own_lists',     // Their own lists (shared ones are read-only)
				'doublescale_manage_own_tags',      // Their own tags
				'doublescale_manage_own_templates', // Their own email templates
				'doublescale_export_data',          // Export — their own contacts only
				'doublescale_import_data',          // Import — contacts land under their ownership
			),
			self::SUPPORT_AGENT   => array(
				'doublescale_reply_own_tickets',  // Reply on tickets assigned to self
			),
			self::SUPPORT_MANAGER => array(
				'doublescale_manage_all_tickets', // See and manage every support ticket
				'doublescale_reply_own_tickets',  // Reply on tickets assigned to self
			),
			// Booking caps (doublescale_booking_*) are assigned by
			// {@see \DoubleScale\Modules\Booking\Capabilities}. These entries
			// only grant admin-shell access (menu + REST bootstrap).
			self::BOOKING_MANAGER => array(),
			self::BOOKING_AGENT   => array(),
			// Project caps (doublescale_project_*) are assigned by
			// {@see \DoubleScale\Pro\Modules\Projects\Capabilities}.
			self::PROJECT_MANAGER => array(),
			self::PROJECT_MEMBER  => array(),
		);
	}

	/**
	 * Get all capabilities
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of all capabilities
	 */
	public static function all_capabilities() {
		$caps = self::get_capabilities();
		return array_values(
			array_unique(
				array_merge(
					$caps['common'],
					$caps['crm_common'],
					$caps['support_common'],
					$caps[ self::CRM_MANAGER ],
					$caps[ self::CRM_AGENT ],
					$caps[ self::SALES_MANAGER ],
					$caps[ self::SALES_REP ],
					$caps[ self::SUPPORT_MANAGER ],
					$caps[ self::SUPPORT_AGENT ],
					$caps[ self::BOOKING_MANAGER ],
					$caps[ self::BOOKING_AGENT ],
					$caps[ self::PROJECT_MANAGER ],
					$caps[ self::PROJECT_MEMBER ],
					$caps['automation_common'],
					$caps[ self::AUTOMATION_MANAGER ],
					$caps[ self::AUTOMATION_AGENT ],
					$caps[ self::CAMPAIGN_MANAGER ],
					$caps[ self::CAMPAIGN_AGENT ],
					$caps['kb_common'],
					$caps[ self::HELP_CENTER_MANAGER ],
					$caps[ self::HELP_CENTER_AGENT ],
					// Module-owned slugs so {@see provision_role()} can strip them
					// from roles that should not hold them (e.g. Sales).
					self::get_booking_capability_slugs(),
					self::get_project_capability_slugs()
				)
			)
		);
	}


	/**
	 * Get available CRM roles
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of CRM roles
	 */
	public static function get_roles() {
		return array(
			self::CRM_MANAGER     => __( 'CRM Manager', 'doublescale' ),
			self::CRM_AGENT       => __( 'CRM Agent', 'doublescale' ),
			self::SALES_MANAGER   => __( 'Sales Manager', 'doublescale' ),
			self::SALES_REP       => __( 'Sales Rep', 'doublescale' ),
			self::SUPPORT_MANAGER => __( 'Support Manager', 'doublescale' ),
			self::SUPPORT_AGENT   => __( 'Support Agent', 'doublescale' ),
			self::BOOKING_MANAGER => __( 'Booking Manager', 'doublescale' ),
			self::BOOKING_AGENT   => __( 'Booking Agent', 'doublescale' ),
			self::PROJECT_MANAGER => __( 'Project Manager', 'doublescale' ),
			self::PROJECT_MEMBER  => __( 'Project Member', 'doublescale' ),
			self::AUTOMATION_MANAGER => __( 'Automation Manager', 'doublescale' ),
			self::AUTOMATION_AGENT   => __( 'Automation Agent', 'doublescale' ),
			self::CAMPAIGN_MANAGER  => __( 'Campaign Manager', 'doublescale' ),
			self::CAMPAIGN_AGENT    => __( 'Campaign Agent', 'doublescale' ),
			self::HELP_CENTER_MANAGER => __( 'Help Center Manager', 'doublescale' ),
			self::HELP_CENTER_AGENT   => __( 'Help Center Agent', 'doublescale' ),
			// Lowest priority: only for people with no other DoubleScale role.
			self::TEAM_MEMBER       => __( 'Team Member', 'doublescale' ),
		);
	}

	/**
	 * Whether a DoubleScale role should exist / grant caps given Pro + module state.
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function is_role_module_enabled( string $role ): bool {
		if ( ! isset( self::get_roles()[ $role ] ) ) {
			return false;
		}

		$pro_active    = self::is_pro_addon_active();
		$is_support    = in_array( $role, array( self::SUPPORT_AGENT, self::SUPPORT_MANAGER ), true );
		$is_booking    = in_array( $role, array( self::BOOKING_AGENT, self::BOOKING_MANAGER ), true );
		$is_project    = in_array( $role, array( self::PROJECT_MEMBER, self::PROJECT_MANAGER ), true );
		$is_automation = in_array( $role, array( self::AUTOMATION_AGENT, self::AUTOMATION_MANAGER ), true );
		$is_campaigns  = in_array( $role, array( self::CAMPAIGN_MANAGER, self::CAMPAIGN_AGENT ), true );
		$is_kb         = in_array( $role, array( self::HELP_CENTER_MANAGER, self::HELP_CENTER_AGENT ), true );
		$is_deals_role = in_array( $role, array( self::SALES_REP, self::SALES_MANAGER ), true );
		$sales_on      = function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'sales' );

		// Exists only while an add-on gives it something to work on.
		if ( self::TEAM_MEMBER === $role ) {
			return self::is_team_member_role_enabled();
		}

		// The scoped agent spans every module, so it exists only with Pro.
		if ( self::CRM_AGENT === $role ) {
			return $pro_active;
		}

		if ( ! $pro_active && ! $is_support && ! $is_booking && ! $is_project && ! $is_automation && ! $is_campaigns && ! $is_kb && ! ( $is_deals_role && $sales_on ) ) {
			return false;
		}

		// The help center is a Helpdesk child: active only while both are on.
		if ( $is_kb && function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'knowledge_base' ) ) {
			return false;
		}

		if ( $is_campaigns && function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'campaigns' ) ) {
			return false;
		}

		if ( $is_automation && function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'automations' ) ) {
			return false;
		}

		if ( $is_support && function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'support' ) ) {
			return false;
		}

		if ( $is_booking && function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'booking' ) ) {
			return false;
		}

		if ( $is_project && function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'projects' ) ) {
			return false;
		}

		if ( $is_deals_role && function_exists( 'doublescale_is_module_active' ) ) {
			$deals_on = doublescale_is_module_active( 'deals' );
			$sales_on = doublescale_is_module_active( 'sales' );
			if ( ! $deals_on && ! $sales_on ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the role may be assigned to a user (module on + role definition exists).
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function is_role_assignable( string $role ): bool {
		if ( ! self::is_role_module_enabled( $role ) ) {
			return false;
		}

		return null !== get_role( $role );
	}

	/**
	 * Every DoubleScale role slug (for listing team members and resolving
	 * effective roles — includes roles whose module is temporarily off).
	 *
	 * @return array<int, string>
	 */
	public static function get_known_role_slugs(): array {
		return array_keys( self::get_roles() );
	}

	/**
	 * Returns the list of role slugs that can be newly assigned through the Team
	 * settings UI / REST endpoints. Module-disabled roles stay on existing users
	 * but cannot be added until the module is turned back on.
	 *
	 * @return array<int, string>
	 */
	public static function get_assignable_role_slugs(): array {
		return array_values(
			array_filter(
				self::get_known_role_slugs(),
				array( self::class, 'is_role_assignable' )
			)
		);
	}

	/**
	 * Remove role definitions that belong to a disabled module (covers manual
	 * tampering with the `user_roles` option in wp_options).
	 *
	 * @return void
	 */
	public static function enforce_module_scoped_roles(): void {
		foreach ( array_keys( self::get_roles() ) as $role ) {
			if ( self::is_role_module_enabled( $role ) ) {
				continue;
			}
			self::deprovision_role( $role );
		}
	}

	/**
	 * Block assignment paths outside the Team UI (WP Users screen, direct API).
	 *
	 * @return void
	 */
	public static function register_enforcement_hooks(): void {
		add_action( 'init', array( self::class, 'enforce_module_scoped_roles' ), 1 );
		add_filter( 'editable_roles', array( self::class, 'filter_editable_roles' ) );
		add_filter( 'user_has_cap', array( self::class, 'filter_capabilities_for_module_state' ), 99, 4 );
		add_filter( 'user_has_cap', array( self::class, 'grant_shell_access_for_active_roles' ), 100, 4 );
		// Help center roles upload images into their articles, but must not browse
		// everyone else's media library (invoices, order files, other staff's uploads).
		add_filter( 'ajax_query_attachments_args', array( self::class, 'limit_media_query_to_own_uploads' ) );
		add_filter( 'rest_attachment_query', array( self::class, 'limit_rest_media_query_to_own_uploads' ) );
		add_action( 'activated_plugin', array( self::class, 'handle_pro_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( self::class, 'handle_pro_plugin_deactivated' ), 10, 1 );
	}

	/**
	 * @param array<string, string> $roles Editable roles for the Users screen.
	 * @return array<string, string>
	 */
	public static function filter_editable_roles( array $roles ): array {
		foreach ( array_keys( self::get_roles() ) as $slug ) {
			if ( ! self::is_role_assignable( $slug ) && isset( $roles[ $slug ] ) ) {
				unset( $roles[ $slug ] );
			}
		}

		return $roles;
	}

	/**
	 * Is the user's only way to upload files a Help Center role? (No core
	 * role such as Author or Editor grants `upload_files`, and no administrator.)
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function uploads_only_from_help_center( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! array_intersect( array( self::HELP_CENTER_MANAGER, self::HELP_CENTER_AGENT ), (array) $user->roles ) ) {
			return false;
		}
		if ( ! empty( $user->caps['upload_files'] ) || user_can( $user, 'manage_options' ) ) {
			return false;
		}
		foreach ( (array) $user->roles as $slug ) {
			if ( in_array( $slug, array( self::HELP_CENTER_MANAGER, self::HELP_CENTER_AGENT ), true ) ) {
				continue;
			}
			$role = wp_roles()->get_role( (string) $slug );
			if ( $role && ! empty( $role->capabilities['upload_files'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Media modal (admin-ajax query-attachments): a help-center-only uploader
	 * sees their own uploads only.
	 *
	 * @param array<string, mixed> $query WP_Query arguments.
	 * @return array<string, mixed>
	 */
	public static function limit_media_query_to_own_uploads( $query ) {
		$query = is_array( $query ) ? $query : array();
		if ( self::uploads_only_from_help_center( get_current_user_id() ) ) {
			$query['author'] = get_current_user_id();
			unset( $query['author__in'], $query['author__not_in'] );
		}

		return $query;
	}

	/**
	 * REST /wp/v2/media list: same rule.
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
	 * @return array<string, mixed>
	 */
	public static function limit_rest_media_query_to_own_uploads( $args ) {
		return self::limit_media_query_to_own_uploads( $args );
	}

	/**
	 * Does one of the user's other roles grant a capability?
	 *
	 * @param string[] $user_roles Roles the user holds.
	 * @param string   $except     The role being looked at.
	 * @param string   $cap        Capability.
	 * @return bool
	 */
	private static function another_role_grants( array $user_roles, string $except, string $cap ): bool {
		foreach ( $user_roles as $slug ) {
			if ( $slug === $except ) {
				continue;
			}
			$role = wp_roles()->get_role( (string) $slug );
			if ( $role && ! empty( $role->capabilities[ $cap ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Deny DoubleScale caps when the owning module is off, even if someone
	 * re-inserted the role into wp_options or assigned it directly.
	 *
	 * @param array<string, bool> $allcaps All capabilities for the user.
	 * @param array<int, string>  $caps    Requested capabilities.
	 * @param array<int, mixed>   $args    Capability check args.
	 * @param \WP_User            $user    User object.
	 * @return array<string, bool>
	 */
	public static function filter_capabilities_for_module_state( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $allcaps;
		}

		if ( in_array( self::ADMINISTRATOR, (array) $user->roles, true ) ) {
			return $allcaps;
		}

		$user_roles = (array) $user->roles;

		foreach ( $user_roles as $role ) {
			if ( ! isset( self::get_roles()[ $role ] ) || self::is_role_module_enabled( $role ) ) {
				continue;
			}

			$role_caps = self::get_role_capability_list( $role );
			if ( null === $role_caps ) {
				continue;
			}

			$shell_caps = array_flip( self::get_shell_capability_slugs() );
			foreach ( $role_caps as $cap ) {
				if ( isset( $shell_caps[ $cap ] ) ) {
					continue;
				}
				// WordPress's own caps in a DoubleScale role (`upload_files` in the
				// help center roles) stay when another role of the user grants
				// them: an Author who also holds a help center role keeps uploads.
				if ( 0 !== strpos( (string) $cap, 'doublescale_' ) && self::another_role_grants( $user_roles, $role, (string) $cap ) ) {
					continue;
				}
				unset( $allcaps[ $cap ] );
			}
		}

		$shell_caps = array_flip( self::get_shell_capability_slugs() );

		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'support' ) ) {
			foreach ( self::get_support_capability_slugs() as $cap ) {
				if ( isset( $shell_caps[ $cap ] ) ) {
					continue;
				}
				unset( $allcaps[ $cap ] );
			}
		}

		// Pipeline off (Sales may still be on): strip only the deal/pipeline
		// caps — proposals/invoices and contact caps stay with the Sales roles.
		if (
			function_exists( 'doublescale_is_module_active' )
			&& ! doublescale_is_module_active( 'deals' )
			&& ! in_array( self::CRM_MANAGER, $user_roles, true )
		) {
			foreach ( self::get_pipeline_capability_slugs() as $cap ) {
				if ( isset( $shell_caps[ $cap ] ) ) {
					continue;
				}
				unset( $allcaps[ $cap ] );
			}
		}

		// Sales off: the whole sales-role cap set goes (the pipeline is also
		// off by derivation, so this is a superset of the strip above).
		if (
			function_exists( 'doublescale_is_module_active' )
			&& ! doublescale_is_module_active( 'sales' )
			&& ! in_array( self::CRM_MANAGER, $user_roles, true )
		) {
			foreach ( self::get_sales_role_capability_slugs() as $cap ) {
				if ( isset( $shell_caps[ $cap ] ) ) {
					continue;
				}
				unset( $allcaps[ $cap ] );
			}
		}

		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'booking' ) ) {
			foreach ( self::get_booking_capability_slugs() as $cap ) {
				if ( isset( $shell_caps[ $cap ] ) ) {
					continue;
				}
				unset( $allcaps[ $cap ] );
			}
		}

		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'projects' ) ) {
			foreach ( self::get_project_capability_slugs() as $cap ) {
				if ( isset( $shell_caps[ $cap ] ) ) {
					continue;
				}
				unset( $allcaps[ $cap ] );
			}
		}

		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'automations' ) ) {
			foreach ( self::get_automation_capability_slugs() as $cap ) {
				unset( $allcaps[ $cap ] );
			}
		}

		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'knowledge_base' ) ) {
			foreach ( self::get_kb_capability_slugs() as $cap ) {
				unset( $allcaps[ $cap ] );
			}
		}

		return $allcaps;
	}

	/**
	 * Admin-shell caps that must never be stripped by module toggles.
	 *
	 * @return array<int, string>
	 */
	private static function get_shell_capability_slugs(): array {
		return array( 'doublescale_access', 'read', 'view_admin_dashboard' );
	}

	/**
	 * Restore shell access for any user holding an active DoubleScale role.
	 *
	 * Runs after {@see filter_capabilities_for_module_state()} so a disabled
	 * optional module (e.g. Booking) cannot revoke `doublescale_access` from
	 * Sales / Support / CRM users.
	 *
	 * @param array<string, bool> $allcaps All capabilities for the user.
	 * @param array<int, string>  $caps    Requested capabilities.
	 * @param array<int, mixed>   $args    Capability check args.
	 * @param \WP_User            $user    User object.
	 * @return array<string, bool>
	 */
	public static function grant_shell_access_for_active_roles( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $allcaps;
		}

		if ( in_array( self::ADMINISTRATOR, (array) $user->roles, true ) ) {
			return $allcaps;
		}

		foreach ( (array) $user->roles as $role ) {
			if ( ! isset( self::get_roles()[ $role ] ) || ! self::is_role_module_enabled( $role ) ) {
				continue;
			}

			foreach ( self::get_shell_capability_slugs() as $cap ) {
				$allcaps[ $cap ] = true;
			}

			return $allcaps;
		}

		return $allcaps;
	}

	/**
	 * @return array<int, string>
	 */
	private static function get_support_capability_slugs(): array {
		$caps = self::get_capabilities();

		return array_values(
			array_unique(
				array_merge(
					$caps['support_common'],
					$caps[ self::SUPPORT_MANAGER ],
					$caps[ self::SUPPORT_AGENT ]
				)
			)
		);
	}

	/**
	 * Every automation cap (stripped when the Automations module is off).
	 *
	 * @return array<int, string>
	 */
	public static function get_automation_capability_slugs(): array {
		$caps = self::get_capabilities();

		return array_values(
			array_unique(
				array_merge(
					$caps['automation_common'],
					$caps[ self::AUTOMATION_MANAGER ],
					$caps[ self::AUTOMATION_AGENT ]
				)
			)
		);
	}

	/**
	 * Every help-center cap (stripped when the knowledge base is off).
	 *
	 * @return array<int, string>
	 */
	public static function get_kb_capability_slugs(): array {
		$caps = self::get_capabilities();

		return array_values(
			array_unique(
				array_merge(
					$caps['kb_common'],
					$caps[ self::HELP_CENTER_MANAGER ],
					$caps[ self::HELP_CENTER_AGENT ]
				)
			)
		);
	}

	/**
	 * Deal/pipeline-specific caps — owned by the `deals` (pipeline) child
	 * module. Excludes proposals/invoices and contact caps, which belong to
	 * the Sales roles and survive a pipeline-only disable.
	 *
	 * @return array<int, string>
	 */
	private static function get_pipeline_capability_slugs(): array {
		return array(
			'doublescale_view_deals',
			'doublescale_edit_own_deals',
			'doublescale_create_deals',
			'doublescale_manage_deals',
			'doublescale_view_all_deals',
			'doublescale_manage_pipelines',
		);
	}

	/**
	 * Every cap the sales roles carry (deal, sales, and contact caps) — the
	 * full strip set when the Sales parent module is off.
	 *
	 * @return array<int, string>
	 */
	private static function get_sales_role_capability_slugs(): array {
		$caps = self::get_capabilities();

		return array_values(
			array_unique(
				array_merge(
					$caps['crm_common'],
					$caps[ self::SALES_REP ],
					$caps[ self::SALES_MANAGER ]
				)
			)
		);
	}

	/**
	 * Pick the highest-priority assignable role from a set of role slugs.
	 *
	 * Priority follows {@see get_roles()} order (highest → lowest):
	 * CRM Manager > Sales Manager > Sales Rep > Support Manager > Support Agent
	 * > Booking Manager > Booking Agent.
	 * When a user holds several DoubleScale roles (e.g. CRM Manager AND Sales
	 * Rep), this returns the one that should drive their effective permissions
	 * (CRM Manager). Administrator is intentionally NOT considered here — it is
	 * a WP role handled separately by {@see Permissions::get_user_role()}.
	 *
	 * @param array<int, string> $roles Role slugs to choose from (e.g. a user's roles).
	 * @return string|null The highest-priority assignable role, or null if none match.
	 */
	public static function get_highest_role( array $roles ): ?string {
		foreach ( self::get_known_role_slugs() as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return $role;
			}
		}
		return null;
	}

	/**
	 * All CRM caps as associative array (cap => true) for multisite super-admin grants.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, bool>
	 */
	public static function get_all_caps() {
		return array_fill_keys( self::all_capabilities(), true );
	}

	/**
	 * Get CRM manager capabilities
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of CRM manager capabilities
	 */
	public static function get_crm_manager_capabilities() {
		$caps = self::get_capabilities();
		return array_values(
			array_unique(
				array_merge(
					$caps['common'],
					$caps['crm_common'],
					$caps[ self::CRM_MANAGER ],
					$caps[ self::SALES_REP ],
					// Manager in every module: the help center included.
					$caps['kb_common'],
					$caps[ self::HELP_CENTER_MANAGER ]
				)
			)
		);
	}

	/**
	 * CRM Agent: the union of every module's AGENT tier, plus own-scoped
	 * campaigns, lists, tags, templates, import and export.
	 *
	 * Deliberately NOT included: `doublescale_manage`, `manage_users`,
	 * `manage_settings`, `manage_contacts`, `manage_deals`, `view_all_deals`,
	 * `manage_all_*` and `view_reports` — those are the manager-tier caps that
	 * {@see Permissions::sees_all_records()} keys on. An agent holding any of
	 * them through a second role is a manager for that module, by design.
	 *
	 * @return array
	 */
	public static function get_crm_agent_capabilities() {
		$caps = self::get_capabilities();
		$list = array_merge(
			$caps['common'],
			$caps['crm_common'],
			$caps[ self::SALES_REP ],           // own deals, own sales docs, create contacts/activities
			$caps['support_common'],
			$caps[ self::SUPPORT_AGENT ],       // own tickets
			$caps['automation_common'],         // own workflows (enrollment limited to
			$caps[ self::AUTOMATION_AGENT ],    // owned contacts in private mode)
			$caps['kb_common'],                 // own help articles, reads everyone's
			$caps[ self::HELP_CENTER_AGENT ],
			$caps[ self::BOOKING_AGENT ],
			$caps[ self::PROJECT_MEMBER ],
			$caps[ self::CRM_AGENT ]
		);

		// Module-owned slugs (booking / projects assign their own caps per role).
		if ( class_exists( '\DoubleScale\Modules\Booking\Capabilities' ) ) {
			$list = array_merge( $list, \DoubleScale\Modules\Booking\Capabilities::get_caps_for_role( self::BOOKING_AGENT ) );
		}
		if ( class_exists( '\DoubleScale\Pro\Modules\Projects\Capabilities' ) ) {
			$list = array_merge( $list, \DoubleScale\Pro\Modules\Projects\Capabilities::get_caps_for_role( self::PROJECT_MEMBER ) );
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Campaign Manager: every campaign, sequence and email template, sending to
	 * every contact. No forms, lists, tags, deals, documents or settings.
	 *
	 * @return array
	 */
	public static function get_campaign_manager_capabilities() {
		$caps = self::get_capabilities();
		return array_values( array_unique( array_merge( $caps['common'], $caps[ self::CAMPAIGN_MANAGER ] ) ) );
	}

	/**
	 * Team Member: admin access only. What they may open comes from the
	 * add-on that enabled the role, never from WordPress capabilities.
	 *
	 * @return array
	 */
	public static function get_team_member_capabilities() {
		$caps = self::get_capabilities();
		return array_values( array_unique( $caps['common'] ) );
	}

	/**
	 * Whether an add-on has switched the Team Member role on.
	 *
	 * @return bool
	 */
	public static function is_team_member_role_enabled(): bool {
		/**
		 * Switch on the Team Member role: a person with admin access and no
		 * module role, who works only in the add-on's areas.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'doublescale_team_member_role_enabled', false );
	}

	/**
	 * Campaign Agent: their own campaigns and sequences, sent to the contacts
	 * they own. Shared email templates to use; their own to change.
	 *
	 * @return array
	 */
	public static function get_campaign_agent_capabilities() {
		$caps = self::get_capabilities();
		return array_values( array_unique( array_merge( $caps['common'], $caps[ self::CAMPAIGN_AGENT ] ) ) );
	}

	/**
	 * Automation Manager: every workflow, every automation alert.
	 *
	 * @return array
	 */
	public static function get_automation_manager_capabilities() {
		$caps = self::get_capabilities();
		return array_values( array_unique( array_merge( $caps['common'], $caps['automation_common'], $caps[ self::AUTOMATION_MANAGER ] ) ) );
	}

	/**
	 * Automation Agent: their own workflows and their alerts.
	 *
	 * @return array
	 */
	public static function get_automation_agent_capabilities() {
		$caps = self::get_capabilities();
		return array_values( array_unique( array_merge( $caps['common'], $caps['automation_common'], $caps[ self::AUTOMATION_AGENT ] ) ) );
	}

	/**
	 * Help Center Manager: manages ARTICLES only: every article and category of
	 * the help center, its order, the import and the page address. No ticket
	 * access, no customer chats, no contacts, no shared inbox or settings; and
	 * not a manager tier for contact visibility ({@see Permissions::manager_tier_roles()}).
	 *
	 * @return array
	 */
	public static function get_help_center_manager_capabilities() {
		$caps = self::get_capabilities();
		return array_values( array_unique( array_merge( $caps['common'], $caps['kb_common'], $caps[ self::HELP_CENTER_MANAGER ], self::HELP_CENTER_MEDIA_CAPS ) ) );
	}

	/**
	 * Help Center Agent: reads every article, writes their own. No ticket access.
	 *
	 * @return array
	 */
	public static function get_help_center_agent_capabilities() {
		$caps = self::get_capabilities();
		return array_values( array_unique( array_merge( $caps['common'], $caps['kb_common'], $caps[ self::HELP_CENTER_AGENT ], self::HELP_CENTER_MEDIA_CAPS ) ) );
	}

	/**
	 * Get sales manager capabilities
	 *
	 * Sales Manager has same base capabilities as Sales Rep but can manage all deals
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of sales manager capabilities
	 */
	public static function get_sales_manager_capabilities() {
		$caps = self::get_capabilities();
		return array_values(
			array_unique(
				array_merge(
					$caps['common'],
					$caps['crm_common'],
					$caps[ self::SALES_REP ],
					$caps[ self::SALES_MANAGER ]
				)
			)
		);
	}

	/**
	 * Get sales rep capabilities
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of sales rep capabilities
	 */
	public static function get_sales_rep_capabilities() {
		$caps = self::get_capabilities();
		return array_values(
			array_unique(
				array_merge(
					$caps['common'],
					$caps['crm_common'],
					$caps[ self::SALES_REP ]
				)
			)
		);
	}

	/**
	 * Get support manager capabilities
	 *
	 * Support Manager can see and act on every ticket and is not bound by
	 * `agent_user_id` ownership.
	 *
	 * @return array
	 */
	public static function get_support_manager_capabilities() {
		$caps = self::get_capabilities();
		return array_values(
			array_unique(
				array_merge(
					$caps['common'],
					$caps['support_common'],
					$caps[ self::SUPPORT_MANAGER ]
				)
			)
		);
	}

	/**
	 * Get support agent capabilities
	 *
	 * Support Agent only sees tickets where they are the assigned agent and
	 * can reply / change status on those tickets.
	 *
	 * @return array
	 */
	public static function get_support_agent_capabilities() {
		$caps = self::get_capabilities();
		return array_values(
			array_unique(
				array_merge(
					$caps['common'],
					$caps['support_common'],
					$caps[ self::SUPPORT_AGENT ]
				)
			)
		);
	}

	/**
	 * Booking Manager: admin-shell access only; booking module caps are synced
	 * separately by {@see \DoubleScale\Modules\Booking\Capabilities}.
	 *
	 * @return array
	 */
	public static function get_booking_manager_capabilities() {
		$caps = self::get_capabilities();
		return array_values(
			array_unique(
				array_merge(
					$caps['common'],
					$caps[ self::BOOKING_MANAGER ]
				)
			)
		);
	}

	/**
	 * Booking Agent: admin-shell access only; own-scope booking caps are synced
	 * separately by {@see \DoubleScale\Modules\Booking\Capabilities}.
	 *
	 * @return array
	 */
	public static function get_booking_agent_capabilities() {
		$caps = self::get_capabilities();
		return array_values(
			array_unique(
				array_merge(
					$caps['common'],
					$caps[ self::BOOKING_AGENT ]
				)
			)
		);
	}

	/**
	 * Project Manager: shell caps + full project module caps when Pro Projects
	 * is loaded. Module caps are also kept in sync by
	 * {@see \DoubleScale\Pro\Modules\Projects\Capabilities}.
	 *
	 * @return array
	 */
	public static function get_project_manager_capabilities() {
		$caps = self::get_capabilities();
		$list = array_merge(
			$caps['common'],
			$caps[ self::PROJECT_MANAGER ]
		);

		if ( class_exists( '\DoubleScale\Pro\Modules\Projects\Capabilities' ) ) {
			$list = array_merge(
				$list,
				\DoubleScale\Pro\Modules\Projects\Capabilities::get_caps_for_role( self::PROJECT_MANAGER )
			);
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Project Member: shell caps + own-scope project module caps when Pro
	 * Projects is loaded.
	 *
	 * @return array
	 */
	public static function get_project_member_capabilities() {
		$caps = self::get_capabilities();
		$list = array_merge(
			$caps['common'],
			$caps[ self::PROJECT_MEMBER ]
		);

		if ( class_exists( '\DoubleScale\Pro\Modules\Projects\Capabilities' ) ) {
			$list = array_merge(
				$list,
				\DoubleScale\Pro\Modules\Projects\Capabilities::get_caps_for_role( self::PROJECT_MEMBER )
			);
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function get_booking_capability_slugs(): array {
		if ( ! class_exists( '\DoubleScale\Modules\Booking\Capabilities' ) ) {
			return array();
		}

		// Only `doublescale_booking_*` slugs — never `doublescale_access`, which
		// must stay available to Sales/Support/CRM roles when Booking is toggled off.
		return \DoubleScale\Modules\Booking\Capabilities::get_booking_capability_slugs();
	}

	/**
	 * @return array<int, string>
	 */
	private static function get_project_capability_slugs(): array {
		if ( ! class_exists( '\DoubleScale\Pro\Modules\Projects\Capabilities' ) ) {
			return array();
		}

		return \DoubleScale\Pro\Modules\Projects\Capabilities::get_project_capability_slugs();
	}

	/**
	 * Provision DoubleScale roles on existing installs without requiring a
	 * plugin re-activation or version bump. Idempotent: stamps an option so
	 * subsequent boots are a no-op. Bumping {@see ROLES_PROVISION_VERSION}
	 * forces re-provisioning when role/cap definitions change.
	 *
	 * @return void
	 */
	public static function ensure_provisioned() {
		self::ensure_role_combinations_normalized();

		$option_key      = 'doublescale_roles_provisioned';
		$current_version = (string) get_option( $option_key, '' );
		if ( self::ROLES_PROVISION_VERSION === $current_version ) {
			return;
		}

		self::add_roles_and_capabilities();
		update_option( $option_key, self::ROLES_PROVISION_VERSION, false );
	}

	/**
	 * General roles cover every module; a person holds at most one of them,
	 * and never together with module roles.
	 *
	 * @return string[]
	 */
	public static function general_roles(): array {
		return array( self::CRM_MANAGER, self::CRM_AGENT );
	}

	/**
	 * Module roles, as module => [manager role, agent role]. A person picks
	 * one level, Manager or Agent, and the modules they work in.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function module_role_pairs(): array {
		return array(
			'sales'       => array( self::SALES_MANAGER, self::SALES_REP ),
			'support'     => array( self::SUPPORT_MANAGER, self::SUPPORT_AGENT ),
			'booking'     => array( self::BOOKING_MANAGER, self::BOOKING_AGENT ),
			'projects'    => array( self::PROJECT_MANAGER, self::PROJECT_MEMBER ),
			'automations' => array( self::AUTOMATION_MANAGER, self::AUTOMATION_AGENT ),
			'campaigns'   => array( self::CAMPAIGN_MANAGER, self::CAMPAIGN_AGENT ),
			'knowledge_base' => array( self::HELP_CENTER_MANAGER, self::HELP_CENTER_AGENT ),
		);
	}

	/**
	 * Why a set of DoubleScale roles is not a valid combination, or null.
	 *
	 * @param string[] $roles DoubleScale role slugs.
	 * @return \WP_Error|null
	 */
	public static function role_combination_error( array $roles ): ?\WP_Error {
		$roles   = array_values( array_unique( $roles ) );

		// Team Member is the base role for people with no module role, so it
		// never sits beside another one.
		if ( in_array( self::TEAM_MEMBER, $roles, true ) && count( $roles ) > 1 ) {
			return new \WP_Error(
				'doublescale_invalid_role_combination',
				__( 'Team Member is only for people with no other DoubleScale role.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$general = array_values( array_intersect( $roles, self::general_roles() ) );

		if ( count( $general ) > 1 || ( 1 === count( $general ) && count( $roles ) > 1 ) ) {
			return new \WP_Error(
				'doublescale_invalid_role_combination',
				__( 'Choose either one general role (CRM Manager or CRM Agent) or module roles, not both.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$pairs = self::module_role_pairs();
		foreach ( $pairs as $pair ) {
			if ( count( array_intersect( $roles, $pair ) ) > 1 ) {
				return new \WP_Error(
					'doublescale_invalid_role_combination',
					__( 'Choose one level per module: Manager or Agent.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
		}

		$managers = array_intersect( $roles, array_column( $pairs, 0 ) );
		$agents   = array_intersect( $roles, array_filter( array_column( $pairs, 1 ) ) );
		if ( $managers && $agents ) {
			return new \WP_Error(
				'doublescale_invalid_role_combination',
				__( 'A team member is a Manager or an Agent in all of their modules, not a mix.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * The valid combination closest to $roles, keeping the highest role:
	 * CRM Manager alone; a CRM Agent gives way to module manager roles (they
	 * would lose access otherwise) and otherwise stands alone; within a
	 * module, Manager beats Agent; and a Manager in any module becomes the
	 * Manager in each of their modules.
	 *
	 * @param string[] $roles DoubleScale role slugs.
	 * @return string[]
	 */
	public static function normalize_role_combination( array $roles ): array {
		$roles = array_values( array_unique( $roles ) );

		// Any other role covers what Team Member gives.
		if ( in_array( self::TEAM_MEMBER, $roles, true ) ) {
			if ( 1 === count( $roles ) ) {
				return $roles;
			}
			$roles = array_values( array_diff( $roles, array( self::TEAM_MEMBER ) ) );
		}

		if ( in_array( self::CRM_MANAGER, $roles, true ) ) {
			return array( self::CRM_MANAGER );
		}

		$pairs        = self::module_role_pairs();
		$module_heads = array_column( $pairs, 0 );

		if ( in_array( self::CRM_AGENT, $roles, true ) ) {
			if ( ! array_intersect( $roles, $module_heads ) ) {
				return array( self::CRM_AGENT );
			}
			$roles = array_values( array_diff( $roles, array( self::CRM_AGENT ) ) );
		}

		$is_manager = (bool) array_intersect( $roles, $module_heads );

		$kept = array();
		foreach ( $pairs as $pair ) {
			if ( in_array( $pair[0], $roles, true ) ) {
				$kept[] = $pair[0];
			} elseif ( '' !== $pair[1] && in_array( $pair[1], $roles, true ) ) {
				$kept[] = $is_manager ? $pair[0] : $pair[1];
			}
		}

		return $kept;
	}

	/**
	 * One-time clean-up of members holding combinations that are no longer
	 * allowed: keep the highest role, drop the rest, and make a member who
	 * manages one module the Manager of their other modules too. A WordPress
	 * administrator already has full access, so their DoubleScale roles are
	 * dropped (the administrator role itself is never touched).
	 *
	 * @return void
	 */
	public static function ensure_role_combinations_normalized(): void {
		$option_key = 'doublescale_role_combinations_normalized';
		if ( self::ROLE_COMBINATIONS_VERSION === (string) get_option( $option_key, '' ) ) {
			return;
		}

		$known = self::get_known_role_slugs();
		$users = get_users(
			array(
				'role__in' => $known,
				'fields'   => 'all',
			)
		);

		foreach ( $users as $user ) {
			$current = array_values( array_intersect( (array) $user->roles, $known ) );
			$keep    = in_array( 'administrator', (array) $user->roles, true )
				? array()
				: self::normalize_role_combination( $current );

			foreach ( array_diff( $keep, $current ) as $role ) {
				if ( ! get_role( $role ) ) {
					self::provision_role( $role );
				}
				$user->add_role( $role );
				/** This action is documented in RestUserManagementController::assign_crm_role(). */
				do_action( 'doublescale_user_role_assigned', $user->ID, $role );
			}

			foreach ( array_diff( $current, $keep ) as $role ) {
				$user->remove_role( $role );
				/** This action is documented in RestUserManagementController::assign_crm_role(). */
				do_action( 'doublescale_user_role_revoked', $user->ID, $role );
			}
		}

		update_option( $option_key, self::ROLE_COMBINATIONS_VERSION, false );
	}

	/**
	 * Bump to re-run {@see ensure_role_combinations_normalized()}.
	 */
	private const ROLE_COMBINATIONS_VERSION = '2026-10-12-one-level';

	/**
	 * Bump this string when the role-to-capability map changes so existing
	 * installs re-run {@see add_roles_and_capabilities()} on next boot.
	 */
	private const ROLES_PROVISION_VERSION = '2026-11-18-help-center-uploads';

	/**
	 * Allow logged-in users with any DoubleScale role to bypass WooCommerce's
	 * "coming soon" mode. WC otherwise only lets `manage_woocommerce` holders
	 * through, which 404-style-blocks our CRM / Support users from reaching
	 * `wp-admin/admin.php?page=doublescale`.
	 *
	 * Wired from {@see CoreModule::boot()} alongside {@see LoginRedirect}.
	 *
	 * @return void
	 */
	public static function register_woocommerce_bypass() {
		add_filter(
			'woocommerce_coming_soon_exclude',
			static function ( $exclude ) {
				if ( $exclude || ! is_user_logged_in() ) {
					return $exclude;
				}

				$user = wp_get_current_user();
				if ( ! $user instanceof \WP_User ) {
					return $exclude;
				}

				$doublescale_roles = array_keys( self::get_roles() );
				if ( array_intersect( $doublescale_roles, (array) $user->roles ) ) {
					return true;
				}

				return $exclude;
			}
		);

		// WC's `WC_Admin::prevent_admin_access` redirects any user without
		// `edit_posts` / `manage_woocommerce` / `view_admin_dashboard` to
		// `/my-account/` on every admin pageload. CRM Manager has full
		// edit_posts via WP, but Support Agent / Support Manager (and any
		// future read-only CRM role) do not — they'd otherwise get bounced
		// straight back to the storefront. Short-circuit that filter for
		// every DoubleScale role.
		add_filter(
			'woocommerce_prevent_admin_access',
			static function ( $prevent ) {
				if ( ! $prevent || ! is_user_logged_in() ) {
					return $prevent;
				}

				$user = wp_get_current_user();
				if ( ! $user instanceof \WP_User ) {
					return $prevent;
				}

				$doublescale_roles = array_keys( self::get_roles() );
				if ( array_intersect( $doublescale_roles, (array) $user->roles ) ) {
					return false;
				}

				return $prevent;
			}
		);

		// Symmetric fix for WC's admin bar suppression. Same caps check as
		// {@see prevent_admin_access}, but applied to the toolbar that
		// shows site-wide at the top of every page. Without this, a Support
		// Agent / Manager sees no admin bar on the frontend even though
		// they have wp-admin access.
		add_filter(
			'show_admin_bar',
			static function ( $show ) {
				if ( $show || ! is_user_logged_in() ) {
					return $show;
				}

				$user = wp_get_current_user();
				if ( ! $user instanceof \WP_User ) {
					return $show;
				}

				$doublescale_roles = array_keys( self::get_roles() );
				if ( array_intersect( $doublescale_roles, (array) $user->roles ) ) {
					return true;
				}

				return $show;
			},
			999
		);
	}
}
