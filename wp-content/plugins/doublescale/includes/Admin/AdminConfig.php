<?php

/**
 * Builds `window.doublescaleConfig` for the React admin shell.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Admin;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;
use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Modules\Automations\Services\TriggersManager;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Services\GoalsManager;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Contacts\ImportExport\Importers\Manager as Importers_Manager;
use DoubleScale\Core\ListPreferences\ListPreferencesManager;
use DoubleScale\Modules\Contacts\Rest\Controllers\RestContactController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Website\License;

/**
 * Admin SPA configuration emitter.
 *
 * @since 1.0.0
 */
final class AdminConfig {

	/**
	 * Set admin config
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function set_admin_config() {
		// Admin email address.
		$admin_email = get_option( 'admin_email' );
		$ajax_url    = admin_url( 'admin-ajax.php' );
		$nonce       = wp_create_nonce( 'doublescale-admin' );

		// Get current user capabilities for role-based access control.
		//
		// Two buckets are exposed to the SPA:
		//
		// 1. CRM role flags — used by `useCapabilities()` for role-based UI
		// (sidebar grouping, settings tab gating, etc.). These already
		// collapse Administrator into CRM Manager — see
		// {@see Permissions::is_crm_manager()}.
		// 2. Booking module caps — every cap from
		// {@see BookingCapabilities::get_all_capabilities()} resolved via
		// WP's `current_user_can()`. Lets `requiredCapability:` arrays in
		// `registerAdminPage` reference booking caps directly.
		// Support role flags use role MEMBERSHIP (not the single highest role)
		// so capabilities merge: a user who is e.g. Sales Rep + Support Manager
		// still gets the Support Manager flags (settings access, manage-all
		// tickets) on top of their sales permissions.
		$user_capabilities = array(
			'doublescale_access'                  => current_user_can( 'doublescale_access' ),
			'doublescale_crm_manager'             => Permissions::is_crm_manager(),
			'doublescale_sales_manager'           => Permissions::user_has_role( UserRoles::SALES_MANAGER ),
			'doublescale_sales_rep'               => Permissions::user_has_role( UserRoles::SALES_REP ),
			// Server-computed Settings gate — prefer this in the SPA so
			// CRM Manager + Sales Rep multi-role users never get locked to
			// Mailbox/Notifications by composing membership flags in JS.
			'doublescale_limited_settings'        => Permissions::has_limited_settings_access(),
			'doublescale_support_manager'         => Permissions::user_has_role( UserRoles::SUPPORT_MANAGER ),
			'doublescale_support_agent'           => Permissions::user_has_role( UserRoles::SUPPORT_AGENT ),
			'doublescale_booking_manager'         => Permissions::user_has_role( UserRoles::BOOKING_MANAGER ),
			'doublescale_booking_agent'           => Permissions::user_has_role( UserRoles::BOOKING_AGENT ),
			'doublescale_project_manager'         => Permissions::user_has_role( UserRoles::PROJECT_MANAGER ),
			'doublescale_project_member'          => Permissions::user_has_role( UserRoles::PROJECT_MEMBER ),
			'doublescale_is_project_only'         => Permissions::is_project_only(),
			'doublescale_view_support'            => Permissions::has_support_access(),
			'doublescale_manage_all_tickets'      => Permissions::can_manage_all_tickets(),
			// Mailbox/settings gate (manager-tier): admins, CRM Managers, and
			// Support Managers. Support Agents + Sales roles are excluded.
			'doublescale_manage_support_settings' => Permissions::can_access_support_settings(),
		);

		if ( class_exists( \DoubleScale\Modules\Booking\Capabilities::class ) ) {
			foreach ( \DoubleScale\Modules\Booking\Capabilities::get_all_capabilities() as $booking_cap ) {
				$user_capabilities[ $booking_cap ] = current_user_can( $booking_cap );
			}
		}

		if ( class_exists( \DoubleScale\Modules\Sales\Capabilities::class ) ) {
			foreach ( \DoubleScale\Modules\Sales\Capabilities::get_sales_capability_slugs() as $sales_cap ) {
				$user_capabilities[ $sales_cap ] = current_user_can( $sales_cap );
			}
		}

		if ( class_exists( \DoubleScale\Pro\Modules\Projects\Capabilities::class ) ) {
			foreach ( \DoubleScale\Pro\Modules\Projects\Capabilities::get_all_capabilities() as $project_cap ) {
				$user_capabilities[ $project_cap ] = current_user_can( $project_cap );
			}
		}

		$user_capabilities['doublescale_manage'] = current_user_can( 'doublescale_manage' );
		$user_capabilities['doublescale_can_assign_project_owner'] = Permissions::can_assign_project_owner();
		$user_capabilities['doublescale_can_assign_task_assignee'] = Permissions::can_assign_task_assignee();
		$user_capabilities['doublescale_can_assign_sales_rep']    = \DoubleScale\Modules\Sales\Capabilities::can_assign_sales_rep();

		$current_wp_user = wp_get_current_user();
		$current_user    = array(
			'id'           => $current_wp_user->ID,
			'display_name' => $current_wp_user->display_name,
			'email'        => $current_wp_user->user_email,
		);

		$url_doublescale_pro = DOUBLESCALE_PRO_PRICE_URL;

		// Get smtp connection info
		$smtp_info = self::get_smtp_connection_info();

		// Get license info
		$license_info = License::instance()->get_license_info();

		// Get pro plugin data
		$pro_plugin_data = self::get_pro_plugin_data();

		// FormsManager may finish resolving after the first `doublescale_ready` pass; refresh
		// trigger catalog so the admin SPA receives form integration rows for automations.
		if ( class_exists( TriggersManager::class ) ) {
			TriggersManager::instance()->sync_form_trigger_sources();
		}

		$config = apply_filters(
			'doublescale_admin_config',
			array(
				'blogName'            => get_bloginfo( 'name' ),
				'adminUrl'            => admin_url(),
				'menuSlug'            => apply_filters( 'doublescale_admin_menu_slug', 'doublescale' ),
				'adminEmail'          => $admin_email,
				'ajaxUrl'             => $ajax_url,
				'nonce'               => $nonce,
				'pluginDirUrl'        => DOUBLESCALE_PLUGIN_URL,
				/** Base URL for DoubleScale Pro plugin assets (optional; empty when Pro is not active). */
				'proPluginDirUrl'     => defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ? DOUBLESCALE_PRO_PLUGIN_URL : '',
				'siteUrl'             => site_url(),
				/** WordPress timezone, so date/time controls can default to the site's own zone instead of UTC. */
				'siteTimezone'        => wp_timezone_string(),
				/** All selectable timezone identifiers, grouped by region. */
				'timezones'           => Utils::get_timezone_options(),
				'forms'               => class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' )
					? \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_options()
					: array(),
				'filtersGroups'       => FiltersManager::instance()->get_groups(),
				'customFieldsTypes'   => class_exists( \DoubleScale\Pro\Modules\CustomFields\CustomFieldsManager::class ) ? \DoubleScale\Pro\Modules\CustomFields\CustomFieldsManager::instance()->get_options() : array(),
				'contactFieldsGroups' => Utils::get_contact_fields(),
				'integrations'        => IntegrationsManager::instance()->get_options(),
				'automationTriggers'  => TriggersManager::instance()->get_sources(),
				'automationActions'   => ActionsManager::instance()->get_sources(),
				'automationGoals'     => GoalsManager::instance()->get_sources(),
				'automationRules'     => class_exists( 'DoubleScale\Modules\Automations\Services\RulesManager' ) ? RulesManager::instance()->get_groups() : array(),
				'isWoocommerceActive' => (bool) doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
				'isEddActive'         => defined( 'EDD_PLUGIN_FILE' ),
				'isSurecartActive'    => defined( 'SURECART_PLUGIN_FILE' ),
				'isLmsActive'         => (bool) ( doublescale_is_plugin_active( 'sfwd-lms/sfwd_lms.php' ) || doublescale_is_plugin_active( 'tutor/tutor.php' ) || doublescale_is_plugin_active( 'lifterlms/lifterlms.php' ) || doublescale_is_plugin_active( 'learnpress/learnpress.php' ) ),
				'isMemberpressActive' => defined( 'MEPR_PLUGIN_NAME' ),
				'isPmproActive'       => defined( 'PMPRO_VERSION' ),
				'mergeTags'           => MergeTagsManager::instance()->get_groups(),
				'importers'           => Importers_Manager::instance()->get_options(),
				'userCapabilities'    => $user_capabilities,
				'currentUser'         => $current_user,
				'listPreferences'       => ListPreferencesManager::get_all( $current_wp_user->ID ),
				'contactsListPreferences' => array(
					'column_visibility' => RestContactController::get_list_column_visibility( $current_wp_user->ID ),
				),
				'defaultStages'       => class_exists( 'DoubleScale\Pro\Modules\Deals\Services\PipelineManager' )
					? \DoubleScale\Pro\Modules\Deals\Services\PipelineManager::instance()->get_default_stages()
					: array(),
				'dealPriorities'      => class_exists( 'DoubleScale\Pro\Modules\Deals\Services\DealManager' )
					? \DoubleScale\Pro\Modules\Deals\Services\DealManager::instance()->get_deal_priorities()
					: array(),
				'smtpInfo'            => $smtp_info,
				'currency'            => Settings::get_currency(),
				'urlDoubleScalePro'   => $url_doublescale_pro,
				'license'             => $license_info ? $license_info : false,
				'planLevels'          => self::get_plan_levels(),
				'proPluginData'       => $pro_plugin_data,
				'addons'              => self::get_addons_status(),
				'storeNonce'          => wp_create_nonce( 'doublescale-admin' ),
				'aiConfigured'        => self::is_ai_configured(),
				'aiAssistantEnabled'  => self::is_ai_assistant_enabled(),
				'modules'             => self::get_modules_config(),
				/** Temporary release gate for Sales proposals/invoices — see doublescale_sales_documents_ready(). */
				'salesDocumentsReady' => doublescale_sales_documents_ready(),
				/** Pro approval workflow toggle — see SalesSettings::get( 'approval_workflow_enabled' ). */
				'salesApprovalWorkflowEnabled' => class_exists( \DoubleScale\Modules\Sales\Services\SalesSettings::class )
					? (bool) \DoubleScale\Modules\Sales\Services\SalesSettings::get( 'approval_workflow_enabled', false )
					: false,
				'business'            => self::get_business_branding_config(),
				'calendarWeekStartsOn' => Settings::get_calendar_week_starts_on(),
			)
		);

		wp_add_inline_script(
			'doublescale-admin',
			'window.doublescaleConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Business branding for document templates and admin UI.
	 *
	 * @return array{business_name: string, business_address: string, business_logo: string}
	 */
	private static function get_business_branding_config(): array {
		if ( class_exists( \DoubleScale\Modules\Documents\Services\DocumentPdf::class ) ) {
			return \DoubleScale\Modules\Documents\Services\DocumentPdf::resolved_business_settings();
		}

		$business = Settings::get( 'business', array() );
		if ( ! is_array( $business ) ) {
			$business = array();
		}

		return array(
			'business_name'    => (string) ( $business['business_name'] ?? '' ),
			'business_address' => (string) ( $business['business_address'] ?? '' ),
			'business_logo'    => (string) ( $business['business_logo'] ?? '' ),
		);
	}

	/**
	 * Get plan levels keyed by plan slug.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, level: int}>
	 */
	private static function get_plan_levels() {
		$plans  = \DoubleScale\Website\License::instance()->get_plans();
		$result = array();
		foreach ( $plans as $slug => $plan ) {
			$result[ $slug ] = array(
				'label' => $plan['label'],
				'level' => $plan['level'],
			);
		}
		return $result;
	}

	/**
	 * Get addon plugins status.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{slug: string, label: string, description: string, is_installed: bool, is_active: bool, plugin_file: string}>
	 */
	private static function get_addons_status() {
		$store_addons = \DoubleScale\Website\Store::instance()->get_all_addons();
		$result       = array();

		foreach ( $store_addons as $slug => $addon ) {
			$result[ $slug ] = array(
				'slug'         => $slug,
				'label'        => $addon['name'],
				'description'  => $addon['description'],
				'plugin_file'  => $addon['plugin_file'],
				'image'        => $addon['image'],
				'plan'         => $addon['plan'] ?? 'basic',
				'is_installed' => $addon['is_installed'],
				'is_active'    => $addon['is_active'],
			);
		}

		return $result;
	}

	/**
	 * Build module metadata for the frontend config.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_modules_config() {
		$registry = \DoubleScale\Core\PluginKernel::instance()->get_module_registry();

		return doublescale_build_modules_list_payload( $registry->all() );
	}

	/**
	 * Check whether the AI provider is configured.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when a provider and Api key (or custom provider) are set.
	 */
	private static function is_ai_configured() {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return false;
		}
		$ai = Settings::get( 'ai', array() );
		return ! empty( $ai['provider'] )
			&& ( 'custom' === $ai['provider'] || ! empty( $ai['api_key'] ) );
	}

	/**
	 * Check whether the AI Assistant addon is active and the current user has access.
	 *
	 * The addon hooks `doublescale_admin_config` to flip this to true when
	 * it loads, but we also detect it eagerly here so the flag is accurate
	 * even if the addon's hook fires after this method runs.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function is_ai_assistant_enabled() {
		if ( ! self::is_ai_configured() ) {
			return false;
		}

		$store = \DoubleScale\Website\Store::instance();
		$addon = $store->get_addon( 'ai-assistant' );

		if ( ! $addon || empty( $addon['is_active'] ) ) {
			return false;
		}

		$result = Permissions::has_ai_access();
		return true === $result;
	}

	/**
	 * Get pro plugin data
	 *
	 * @since 1.0.0
	 *
	 * @return array Pro plugin data including installation and activation status
	 */
	private static function get_pro_plugin_data() {
		if ( function_exists( 'doublescale_get_pro_plugin_status' ) ) {
			return doublescale_get_pro_plugin_status();
		}

		return array(
			'is_installed' => false,
			'is_active'    => false,
		);
	}

	/**
	 * Option key for global SMTP routing (connections, default route, etc.).
	 * Mirrors {@see \DoubleScale\Pro\Modules\Inbox\Oauth\EmailOauth::smtp_routing_option_name()}
	 * so this works when Pro is not active.
	 *
	 * @return string
	 */
	private static function get_smtp_routing_option_name() {
		$email_oauth_class = 'DoubleScale\\Pro\\Modules\\Inbox\\Oauth\\EmailOauth';
		if ( class_exists( $email_oauth_class, false ) ) {
			return call_user_func( array( $email_oauth_class, 'smtp_routing_option_name' ) );
		}
		return 'doublescale_smtp_settings';
	}

	/**
	 * Whether the bundled SMTP module storage is available.
	 *
	 * @return bool
	 */
	private static function is_smtp_storage_available() {
		$email_oauth_class = 'DoubleScale\\Pro\\Modules\\Inbox\\Oauth\\EmailOauth';
		if ( class_exists( $email_oauth_class, false ) ) {
			return (bool) call_user_func( array( $email_oauth_class, 'smtp_oauth_storage_available' ) );
		}
		// Allow autoload: at admin bootstrap the SMTP module classes may not be loaded yet.
		if ( class_exists( '\\DoubleScale\\Modules\\Smtp\\Module', true )
			|| class_exists( '\\DoubleScale\\Modules\\Smtp\\Settings', true ) ) {
			return true;
		}
		// Settings may exist on disk even if classes are not loaded this request.
		$stored = get_option( 'doublescale_smtp_settings', null );
		return is_array( $stored );
	}

	/**
	 * Admin URL to configure the bundled SMTP module.
	 *
	 * @return string
	 */
	private static function get_smtp_config_admin_url() {
		$slug = apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );
		return admin_url( 'admin.php?page=' . rawurlencode( $slug ) . '&path=smtp%2Fsettings' );
	}

	/**
	 * Get smtp connection information
	 *
	 * @since 1.0.0
	 *
	 * @return array smtp connection info including verified senders
	 */
	private static function get_smtp_connection_info() {
		if ( ! self::is_smtp_storage_available() ) {
			$slug = apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );
			return array(
				'configured' => false,
				'config_url' => admin_url(
					'admin.php?page=' . rawurlencode( $slug ) . '&path=settings%2Fmodules'
				),
			);
		}

		$settings    = get_option( self::get_smtp_routing_option_name(), array() );
		$connections = isset( $settings['connections'] ) && is_array( $settings['connections'] ) ? $settings['connections'] : array();

		$config_url = self::get_smtp_config_admin_url();

		// If no connections configured
		if ( empty( $connections ) ) {
			return array(
				'configured' => false,
				'config_url' => $config_url,
			);
		}

		// Extract verified senders from connections
		$verified_senders = array();
		foreach ( $connections as $connection_id => $connection ) {
			if ( ! empty( $connection['from_email'] ) && is_email( $connection['from_email'] ) ) {
				$verified_senders[] = array(
					'email'         => $connection['from_email'],
					'name'          => isset( $connection['from_name'] ) ? $connection['from_name'] : '',
					'connection_id' => $connection_id,
				);
			}
		}

		// Connections exist but no valid From address yet — treat as not configured for the picker.
		if ( empty( $verified_senders ) ) {
			return array(
				'configured' => false,
				'config_url' => $config_url,
			);
		}

		return array(
			'configured'       => true,
			'verified_senders' => $verified_senders,
			'config_url'       => $config_url,
		);
	}
}
