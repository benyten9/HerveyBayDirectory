<?php
/**
 * Companion-owned administration screen.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Admin;

use DirectoristAIPro\Traits\Singleton;

/**
 * Registers and renders the administration application.
 */
class Dashboard {
	use Singleton;

	/**
	 * WordPress screen hook.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register the menu hook.
	 */
	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		// Menu access checks run before admin_init and reject the retired slug.
		add_action( 'admin_menu', array( $this, 'redirect_legacy_page' ), 0 );
	}

	/** Preserve existing beta bookmarks without retaining the public Pro slug. */
	public function redirect_legacy_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bookmark navigation.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( \DirectoristAIPro\Compatibility\PluginIdentity::LEGACY_SLUG !== $page || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$args = array( 'post_type' => 'at_biz_dir', 'page' => \DirectoristAIPro\Compatibility\PluginIdentity::SLUG );
		foreach ( array( 'tab', 'section' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve navigation only.
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) );
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ), 302 );
		exit;
	}

	/**
	 * Add the companion's screen to the Directorist menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'edit.php?post_type=at_biz_dir',
			'Directorist AI',
			'Directorist AI',
			'manage_options',
			\DirectoristAIPro\Compatibility\PluginIdentity::SLUG,
			array( $this, 'render' ),
			13
		);
		add_action( 'load-' . $this->hook_suffix, array( $this, 'prepare_screen' ) );
	}

	/**
	 * Remove the optional Elementor installation promotion on this settings screen.
	 * Existing-addon compatibility errors and other administration notices remain.
	 *
	 * @return void
	 */
	public function prepare_screen(): void {
		// Preserve old import bookmarks while placing the workflow beside native listings.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation from an existing bookmark.
		if ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) && 'import' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type'             => 'at_biz_dir',
						'directorist_ai_import' => '1',
					),
					admin_url( 'edit.php' )
				)
			);
			exit;
		}
		add_filter(
			'admin_body_class',
			static function ( $classes ) {
				return $classes . ' directorist-ai-pro-settings-page';
			}
		);
		global $wp_filter;
		if ( ! isset( $wp_filter['admin_notices'] ) ) {
			return;
		}
		foreach ( $wp_filter['admin_notices']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$callback = $registered['function'];
				if ( ! is_array( $callback ) || ! is_object( $callback[0] ) || ! is_a( $callback[0], 'AazzTech\\Directorist\\Elementor\\DeprecatedNotice' ) || 'maybe_show_notice_for_required_plugins' !== $callback[1] ) {
					continue;
				}
				if ( false === $callback[0]->is_installed && false === $callback[0]->is_active ) {
					remove_action( 'admin_notices', $callback, $priority );
				}
			}
		}
	}

	/**
	 * Return the exact screen hook for asset scoping.
	 *
	 * @return string
	 */
	public function get_hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Render the application mount point.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'directorist-ai' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap directorist-ai-pro-admin-wrap"><div id="directorist-ai-pro-dashboard-root">';
		echo '<h1>Directorist AI</h1><p>' . esc_html__( 'Loading…', 'directorist-ai' ) . '</p>';
		echo '</div><noscript>' . esc_html__( 'Enable JavaScript to use this screen.', 'directorist-ai' ) . '</noscript></div>';
	}
}
