<?php
/**
 * Class WooCommerceCompat
 *
 * Bridges DoubleScale CRM roles (CRM Manager / Sales Manager / Sales Rep) with
 * WooCommerce features that gate behavior on the canonical store-manager
 * capabilities (`manage_woocommerce`, `edit_posts`). Without this bridge, CRM
 * users get treated as "customers" by WooCommerce and are locked out of the
 * pieces of WordPress they need to actually run the CRM:
 *
 *   1. Coming Soon mode hides the entire frontend behind "Pardon our dust! …"
 *      unless `manage_woocommerce` is present.
 *      See {@see \Automattic\WooCommerce\Internal\ComingSoon\ComingSoonRequestHandler::should_show_coming_soon()}
 *      (line 143 — `if ( current_user_can( 'manage_woocommerce' ) ) return false;`).
 *
 *   2. The WordPress admin bar is stripped from the frontend for any user that
 *      lacks `edit_posts` or `manage_woocommerce`.
 *      See {@see wc_disable_admin_bar()} in `wc-user-functions.php` (line 34).
 *      Without the admin bar, a Sales Rep landing on the frontend has no
 *      visible way back into the DoubleScale admin.
 *
 *   3. `prevent_admin_access()` redirects users that don't have `edit_posts`,
 *      `manage_woocommerce`, or `view_admin_dashboard` away from `/wp-admin/`.
 *      That one is handled by granting `view_admin_dashboard` to CRM roles
 *      (see {@see UserRoles::get_capabilities()} common bucket) — it doesn't
 *      need a runtime filter.
 *
 * This class addresses 1 and 2 via official WooCommerce filters, without
 * granting CRM users any actual WooCommerce store-manager privileges.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core\UserRoles;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerceCompat class
 *
 * Singleton service registered from {@see \DoubleScale\Core\CoreModule::boot()}.
 */
final class WooCommerceCompat {

	/**
	 * Class Instance.
	 *
	 * @var WooCommerceCompat|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return WooCommerceCompat
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		add_filter( 'woocommerce_coming_soon_exclude', array( $this, 'exclude_crm_users_from_coming_soon' ), 10, 1 );
		// `wc_disable_admin_bar` hooks `show_admin_bar` at priority 10. Run after
		// so we can restore the bar for CRM users that WC just stripped it from.
		add_filter( 'show_admin_bar', array( $this, 'restore_admin_bar_for_crm_users' ), 20, 1 );
	}

	/**
	 * Bypass WooCommerce Coming Soon mode for logged-in CRM users.
	 *
	 * @param bool $is_excluded Existing exclusion state from upstream filters.
	 * @return bool True to bypass Coming Soon, otherwise the unchanged input.
	 */
	public function exclude_crm_users_from_coming_soon( $is_excluded ) {
		if ( $is_excluded ) {
			return true;
		}

		return $this->current_user_has_crm_role() ? true : (bool) $is_excluded;
	}

	/**
	 * Restore the WordPress admin bar on the frontend for CRM users.
	 *
	 * WooCommerce's `wc_disable_admin_bar()` forces `show_admin_bar` to false
	 * for any user that lacks `edit_posts` or `manage_woocommerce`. CRM roles
	 * have neither, so the bar disappears and users can't get back to
	 * `/wp-admin/`. We re-enable it for CRM users specifically, respecting the
	 * per-user `show_admin_bar_front` meta where present.
	 *
	 * @param bool $show_admin_bar Current decision from upstream filters.
	 * @return bool Possibly overridden decision.
	 */
	public function restore_admin_bar_for_crm_users( $show_admin_bar ) {
		if ( $show_admin_bar ) {
			return $show_admin_bar;
		}

		if ( ! $this->current_user_has_crm_role() ) {
			return $show_admin_bar;
		}

		$user_pref = get_user_option( 'show_admin_bar_front', get_current_user_id() );
		if ( 'false' === $user_pref ) {
			return $show_admin_bar;
		}

		return true;
	}

	/**
	 * Does the current user hold any DoubleScale CRM role?
	 *
	 * @return bool
	 */
	private function current_user_has_crm_role(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || empty( $user->roles ) ) {
			return false;
		}

		$crm_roles = array(
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
		);

		foreach ( (array) $user->roles as $role ) {
			if ( in_array( $role, $crm_roles, true ) ) {
				return true;
			}
		}

		return false;
	}
}
