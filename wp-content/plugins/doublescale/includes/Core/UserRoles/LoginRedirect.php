<?php

/**
 * Class LoginRedirect
 *
 * Handles login redirection for CRM user roles
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\UserRoles;

defined( 'ABSPATH' ) || exit;

/**
 * LoginRedirect class
 *
 * Redirects CRM Manager and Sales Rep users to the CRM dashboard after login
 */
final class LoginRedirect {

	/**
	 * CRM dashboard page slug
	 */
	public const CRM_DASHBOARD_SLUG = 'doublescale';

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var LoginRedirect
	 */
	private static $instance;

	/**
	 * LoginRedirect Instance.
	 *
	 * Instantiates or reuses an instance of LoginRedirect.
	 *
	 * @since 1.0.0
	 *
	 * @return LoginRedirect
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
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Late priority (999) so we win over WooCommerce / Memberships plugins
		// that point logins at frontend pages like `/my-account/`. Without
		// this, a customer-facing role on the user (Subscriber, Customer,
		// PMPro Member, etc.) makes those plugins hijack the redirect first.
		add_filter( 'login_redirect', array( $this, 'redirect_crm_users' ), 999, 3 );

		// WooCommerce's `/my-account/` login form NEVER fires `login_redirect`.
		// It calls `wp_signon()` from `WC_Form_Handler::process_login()` (on
		// `wp_loaded` priority 20) and then redirects through its own filter
		// `woocommerce_login_redirect`. Hook that filter too so CRM/Support
		// users land in the admin regardless of which login form they used.
		add_filter( 'woocommerce_login_redirect', array( $this, 'redirect_crm_users_wc' ), 999, 2 );
	}

	/**
	 * Redirect CRM users to CRM dashboard after login
	 *
	 * @since 1.0.0
	 *
	 * @param string             $redirect_to           The redirect destination URL.
	 * @param string             $requested_redirect_to The requested redirect destination URL passed as a parameter.
	 * @param \WP_User|\WP_Error $user                  WP_User object if login was successful, WP_Error object otherwise.
	 * @return string The redirect URL.
	 */
	/**
	 * WooCommerce-specific override.
	 *
	 * WC's `process_login()` (on `wp_loaded` priority 20) applies the
	 * `woocommerce_login_redirect` filter with `( $redirect, $user )` — note
	 * the different signature from WP's `login_redirect`. Without this hook
	 * a customer-facing user (Subscriber/Customer/PMPro Member) who also has
	 * a CRM or Support role keeps landing on `/my-account/` after logging in
	 * through the WooCommerce form.
	 *
	 * @param string   $redirect WC's chosen redirect (typically the My Account permalink).
	 * @param \WP_User $user     User who just authenticated.
	 * @return string Admin URL for CRM/Support users; original $redirect otherwise.
	 */
	public function redirect_crm_users_wc( $redirect, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $redirect;
		}
		if ( $this->user_has_crm_role( $user ) ) {
			return $this->get_redirect_url_for_user( $user );
		}
		return $redirect;
	}

	public function redirect_crm_users( $redirect_to, $requested_redirect_to, $user ) {
		// Check if user logged in successfully.
		if ( ! $user instanceof \WP_User ) {
			return $redirect_to;
		}

		// CRM / Support roles ALWAYS land in the admin, no matter what
		// WooCommerce / Memberships / themes asked for. Without this override
		// a stacked Subscriber/Customer role gets the user pushed to
		// `/my-account/` and they never see the DoubleScale admin.
		if ( $this->user_has_crm_role( $user ) ) {
			return $this->get_redirect_url_for_user( $user );
		}

		// If a specific redirect was requested (not default admin), honor it.
		if ( ! empty( $requested_redirect_to ) && $requested_redirect_to !== admin_url() ) {
			return $redirect_to;
		}

		return $redirect_to;
	}

	/**
	 * Check if user has a CRM-specific role
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_User $user The user object.
	 * @return bool True if user has a CRM role.
	 */
	private function user_has_crm_role( \WP_User $user ) {
		$crm_roles = array(
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
			UserRoles::SUPPORT_MANAGER,
			UserRoles::SUPPORT_AGENT,
		);

		// Check if any of the user's roles match CRM roles.
		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $crm_roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the appropriate redirect URL based on user role
	 *
	 * Sales Rep and Sales Manager are redirected to the pipeline page.
	 * CRM Manager is redirected to the CRM dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_User $user The user object.
	 * @return string The redirect URL.
	 */
	private function get_redirect_url_for_user( \WP_User $user ) {
		$support_roles = array(
			UserRoles::SUPPORT_MANAGER,
			UserRoles::SUPPORT_AGENT,
		);

		// Support roles land directly in the Support inbox — that's the only
		// area they have access to.
		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $support_roles, true ) ) {
				return admin_url( 'admin.php?page=' . self::CRM_DASHBOARD_SLUG . '&path=support' );
			}
		}

		$sales_roles = array(
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
		);

		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $sales_roles, true ) ) {
				if ( function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'deals' ) ) {
					return admin_url( 'admin.php?page=' . self::CRM_DASHBOARD_SLUG . '&path=sales-pipeline' );
				}

				return admin_url( 'admin.php?page=' . self::CRM_DASHBOARD_SLUG . '&path=sales' );
			}
		}

		return admin_url( 'admin.php?page=' . self::CRM_DASHBOARD_SLUG );
	}
}
