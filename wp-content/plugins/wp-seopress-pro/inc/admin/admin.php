<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Admin.
 *
 * @package Admin
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * SEOPress PRO Options.
 *
 * @package SEOPress PRO
 * @subpackage Admin
 */
class seopress_pro_options {

	/**
	 * Holds the values to be used in the fields callbacks.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Start up.
	 */
	public function __construct() {
		// License activation / deactivation / automatic activation.
		require_once __DIR__ . '/callbacks/License.php';

		// Google Analytics OAuth handling (code exchange, logout, AJAX status).
		require_once __DIR__ . '/callbacks/GoogleAnalyticsOAuth.php';

		add_action( 'admin_menu', array( $this, 'add_plugin_page' ), 20 );
		add_action( 'admin_init', array( $this, 'pro_set_default_values' ), 10 );
		add_action( 'network_admin_menu', array( $this, 'add_network_plugin_page' ), 10 );
		add_action( 'admin_init', array( $this, 'load_sections' ), 5 );
		add_action( 'admin_init', array( $this, 'load_callbacks' ), 5 );
		add_action( 'admin_init', array( $this, 'page_init' ) );
		add_action( 'admin_init', array( $this, 'metabox_init' ) );

		add_action( 'admin_init', array( $this, 'feature_save' ), 30 );
		add_action( 'admin_init', array( $this, 'feature_title' ), 20 );
		add_action( 'admin_init', array( $this, 'pre_save_options' ), 50 );
	}

	/**
	 * Feature save.
	 *
	 * @return string
	 */
	public function feature_save() {
		$html = '';
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
			$html .= '<div id="seopress-notice-save" class="sp-components-snackbar-list">';
		} else {
			$html .= '<div id="seopress-notice-save" class="sp-components-snackbar-list" style="display:none">';
		}
		$html .= '<div class="sp-components-snackbar">
                <div class="sp-components-snackbar__content">
                    <span class="dashicons dashicons-yes"></span>
                    ' . __( 'Your settings have been saved.', 'wp-seopress-pro' ) . '
                </div>
            </div>
        </div>';

		return $html;
	}

	/**
	 * Feature title.
	 *
	 * @param string $feature The feature.
	 * @return string
	 */
	public function feature_title( $feature ) {
		global $title;

		$html = '<h1>' . $title;

		if ( null !== $feature ) {
			if ( '1' == seopress_get_toggle_option( $feature ) ) {
				$toggle = '"1"';
			} else {
				$toggle = '"0"';
			}

			$html .= '<input type="checkbox" name="toggle-' . $feature . '" id="toggle-' . $feature . '" class="toggle" data-toggle=' . $toggle . '>';
			$html .= '<label for="toggle-' . $feature . '"></label>';

			$html .= $this->feature_save();

			if ( '1' == seopress_get_toggle_option( $feature ) ) {
				$html .= '<span id="titles-state-default" class="feature-state"><span class="dashicons dashicons-arrow-left-alt"></span>' . __( 'Click to disable this feature', 'wp-seopress-pro' ) . '</span>';
				$html .= '<span id="titles-state" class="feature-state feature-state-off"><span class="dashicons dashicons-arrow-left-alt"></span>' . __( 'Click to enable this feature', 'wp-seopress-pro' ) . '</span>';
			} else {
				$html .= '<span id="titles-state-default" class="feature-state"><span class="dashicons dashicons-arrow-left-alt"></span>' . __( 'Click to enable this feature', 'wp-seopress-pro' ) . '</span>';
				$html .= '<span id="titles-state" class="feature-state feature-state-off"><span class="dashicons dashicons-arrow-left-alt"></span>' . __( 'Click to disable this feature', 'wp-seopress-pro' ) . '</span>';
			}
		}

		$html .= '</h1>';

		return $html;
	}

	/**
	 * Set default values.
	 *
	 * @return void
	 */
	public function pro_set_default_values() {
		if ( defined( 'SEOPRESS_WPMAIN_VERSION' ) ) {
			return;
		}

		$seopress_pro_option_name = get_option( 'seopress_pro_option_name', array() );

		// WooCommerce.
		if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			$seopress_pro_option_name['seopress_woocommerce_cart_page_no_index']             = '1';
			$seopress_pro_option_name['seopress_woocommerce_checkout_page_no_index']         = '1';
			$seopress_pro_option_name['seopress_woocommerce_customer_account_page_no_index'] = '1';
			$seopress_pro_option_name['seopress_woocommerce_product_og_price']               = '1';
			$seopress_pro_option_name['seopress_woocommerce_product_og_currency']            = '1';
			$seopress_pro_option_name['seopress_woocommerce_meta_generator']                 = '1';
		}

		// 404.
		$seopress_pro_option_name['seopress_404_cleaning'] = '1';

		// Structured Data Types: enable the schema metabox on posts, pages and CPTs
		// out of the box, so the already-on "Structured Data Types" feature is usable
		// without an extra manual step. add_option() below only seeds fresh installs,
		// so existing sites keep their saved choice.
		$seopress_pro_option_name['seopress_rich_snippets_enable'] = '1';

		// Check if the value is an array (important!).
		if ( is_array( $seopress_pro_option_name ) ) {
			add_option( 'seopress_pro_option_name', $seopress_pro_option_name );
		}

		// BOT.
		$seopress_bot_option_name = get_option( 'seopress_bot_option_name', array() );

		$seopress_bot_option_name['seopress_bot_scan_settings_post_types']['post']['include'] = '1';
		$seopress_bot_option_name['seopress_bot_scan_settings_post_types']['page']['include'] = '1';
		$seopress_bot_option_name['seopress_bot_scan_settings_404']                           = '1';

		// Check if the value is an array (important!).
		if ( is_array( $seopress_bot_option_name ) ) {
			add_option( 'seopress_bot_option_name', $seopress_bot_option_name );
		}
	}

	/**
	 * Add options page.
	 */
	public function add_network_plugin_page() {
		if ( has_filter( 'seopress_seo_admin_menu' ) ) {
			$sp_seo_admin_menu['icon'] = '';
			$sp_seo_admin_menu['icon'] = apply_filters( 'seopress_seo_admin_menu', $sp_seo_admin_menu['icon'] );
		} else {
			$sp_seo_admin_menu['icon'] = 'data:image/svg+xml;base64,PHN2ZyB2aWV3Qm94PSItMjQgLTI0IDI0MiAyNDIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHBhdGggZD0iTTEyMC42MjYgMTI5LjY4NEMxMjIuODMzIDEyOS43NjMgMTI0LjkyOSAxMzAuNjc1IDEyNi40OTEgMTMyLjIzN0MxMjguMDUzIDEzMy43OTkgMTI4Ljk2NSAxMzUuODk1IDEyOS4wNDQgMTM4LjEwMkMxMjkuMTIyIDE0MC4zMSAxMjguMzYyIDE0Mi40NjUgMTI2LjkxNSAxNDQuMTM0TDEyNi44NTUgMTQ0LjIwMkwxMjYuNzkyIDE0NC4yNjVMODEuOTg1MSAxODkuMDA4TDgxLjkyMDcgMTg5LjA3NEw4MS44NTEzIDE4OS4xMzNDODAuMTgxMSAxOTAuNTczIDc4LjAyODIgMTkxLjMyNyA3NS44MjUgMTkxLjI0NkM3My42MjE1IDE5MS4xNjQgNzEuNTI5OCAxOTAuMjUzIDY5Ljk3MDUgMTg4LjY5NEM2OC40MTExIDE4Ny4xMzUgNjcuNDk4OCAxODUuMDQ0IDY3LjQxNjcgMTgyLjg0QzY3LjMzNDcgMTgwLjYzNyA2OC4wODkgMTc4LjQ4NCA2OS41MjgxIDE3Ni44MTNMNjkuNTg5NiAxNzYuNzQzTDY5LjY1NiAxNzYuNjc2TDExNC40NjUgMTMxLjkzM0wxMTQuNTI3IDEzMS44NzFMMTE0LjU5NCAxMzEuODEzQzExNi4yNjMgMTMwLjM2NiAxMTguNDE4IDEyOS42MDUgMTIwLjYyNiAxMjkuNjg0WiIgZmlsbD0id2hpdGUiLz48cGF0aCBkPSJNNTUuNzI2NiA2NC43ODU2QzU3LjkzNDEgNjQuODY0MyA2MC4wMjk4IDY1Ljc3NjQgNjEuNTkxOCA2Ny4zMzg0QzYzLjE1MzcgNjguOTAwMyA2NC4wNjU5IDcwLjk5NjEgNjQuMTQ0NSA3My4yMDM2QzY0LjIyMzEgNzUuNDExMSA2My40NjI1IDc3LjU2NjggNjIuMDE1NiA3OS4yMzU4TDYxLjk1NyA3OS4zMDMyTDYxLjg5MjYgNzkuMzY2N0wxNy44MTg0IDEyMy4zNjdWMTIzLjQ4M0wxNi45NTYxIDEyNC4yM0MxNS4yODcgMTI1LjY3NyAxMy4xMzEzIDEyNi40MzcgMTAuOTIzOCAxMjYuMzU5QzguNzE2MzEgMTI2LjI4IDYuNjIwNTYgMTI1LjM2OCA1LjA1ODU5IDEyMy44MDZDMy40OTY2MyAxMjIuMjQ0IDIuNTg0NDkgMTIwLjE0OCAyLjUwNTg2IDExNy45NDFDMi40MjcyNiAxMTUuNzMzIDMuMTg3OTEgMTEzLjU3OCA0LjYzNDc3IDExMS45MDlMNC42OTMzNiAxMTEuODQxTDQuNzU2ODQgMTExLjc3OEw0OS41NjU0IDY3LjAzNDdMNDkuNjI3OSA2Ni45NzIyTDQ5LjY5NDMgNjYuOTE0NkM1MS4zNjM1IDY1LjQ2NzcgNTMuNTE5IDY0LjcwNyA1NS43MjY2IDY0Ljc4NTZaIiBmaWxsPSJ3aGl0ZSIvPjxwYXRoIGQ9Ik0xMzMuNDY2IDIuNTk3NjZDMTQ2LjgxMSAxLjc5NzIyIDE1OS45ODYgNS45MTc5OSAxNzAuNDk3IDE0LjE3OTdDMTk0LjIxOCAzMi44MjQ2IDE5OC4zMzMgNjcuMTY4NyAxNzkuNjg4IDkwLjg4OTZDMTYxLjUwNCAxMTQuMDI1IDEyOC4zODUgMTE4LjUxMSAxMDQuNzU0IDEwMS40Mkw0OS41ODIgMTU2LjU5NEw0OS41MTY2IDE1Ni42NTlMNDkuNDQ2MyAxNTYuNzJDNDcuNzc2IDE1OC4xNTkgNDUuNjIyNCAxNTguOTE1IDQzLjQxODkgMTU4LjgzM0M0MS4yMTU2IDE1OC43NTEgMzkuMTI0NiAxNTcuODM5IDM3LjU2NTQgMTU2LjI4QzM2LjAwNjEgMTU0LjcyMSAzNS4wOTM4IDE1Mi42MyAzNS4wMTE3IDE1MC40MjdDMzQuOTI5NyAxNDguMjI0IDM1LjY4NDIgMTQ2LjA3MSAzNy4xMjMgMTQ0LjRMMzcuMTgzNiAxNDQuMzI5TDM3LjI0OSAxNDQuMjY0TDkyLjQyNzcgODkuMDg2OUM4NS4wNzc5IDc4Ljg5NjQgODEuNDQ5MiA2Ni40NjIzIDgyLjIwMTIgNTMuODc1QzgyLjk5NzQgNDAuNTQ2MiA4OC42NDczIDI3Ljk3MDEgOTguMDg0IDE4LjUyMzRDMTA3LjUzMiA5LjA2NDQ2IDEyMC4xMjEgMy4zOTgyIDEzMy40NjYgMi41OTc2NlpNMTYyLjc2OSAzMS4wODk4QzE0OC4zNzYgMTYuNjk5MiAxMjUuMDQzIDE2LjY5NzEgMTEwLjY1MiAzMS4wODg5Qzk2LjI2MTYgNDUuNDgxNiA5Ni4yNTk1IDY4LjgxNTkgMTEwLjY1MSA4My4yMDYxQzEyNS4wNDQgOTcuNTk2NiAxNDguMzc2IDk3LjU5NjUgMTYyLjc2OSA4My4yMDYxTDE2My41ODIgODIuMzkxNkMxNzcuMTcyIDY3LjkzOTcgMTc2Ljg5NCA0NS4yMTM1IDE2Mi43NjkgMzEuMDg5OFoiIGZpbGw9IndoaXRlIi8+PC9zdmc+';
		}

		$sp_seo_admin_menu['title'] = __( 'SEO', 'wp-seopress-pro' );
		if ( has_filter( 'seopress_seo_admin_menu_title' ) ) {
			$sp_seo_admin_menu['title'] = apply_filters( 'seopress_seo_admin_menu_title', $sp_seo_admin_menu['title'] );
		}

		add_menu_page( __( 'SEO Network settings', 'wp-seopress-pro' ), $sp_seo_admin_menu['title'], seopress_capability( 'manage_options', 'menu' ), 'seopress-network-option', array( $this, 'create_network_admin_page' ), $sp_seo_admin_menu['icon'], 90 );
	}

	/**
	 * Add plugin page.
	 *
	 * @return void
	 */
	public function add_plugin_page() {
		add_submenu_page( 'seopress-option', __( 'PRO', 'wp-seopress-pro' ), __( 'PRO', 'wp-seopress-pro' ), seopress_capability( 'manage_options', 'pro' ), 'seopress-pro-page', array( $this, 'seopress_pro_page' ) );
		if ( '1' == seopress_get_toggle_option( 'rich-snippets' ) ) {
			add_submenu_page( 'seopress-option', __( 'Schemas', 'wp-seopress-pro' ), __( 'Schemas', 'wp-seopress-pro' ), seopress_capability( 'edit_schemas', 'menu' ), 'seopress-schemas', 'seopress_schemas_page_render' );
		}
		if ( '1' == seopress_get_toggle_option( '404' ) ) {
			add_submenu_page( 'seopress-option', __( 'Redirections', 'wp-seopress-pro' ), __( 'Redirections', 'wp-seopress-pro' ), seopress_capability( 'edit_redirections', 'menu' ), 'seopress-redirections', 'seopress_redirections_page_render' );
		}
		if ( '1' == seopress_get_toggle_option( 'bot' ) ) {
			add_submenu_page( 'seopress-option', __( 'Broken links', 'wp-seopress-pro' ), __( 'Broken links', 'wp-seopress-pro' ), seopress_capability( 'edit_broken_links', 'menu' ), 'seopress-broken-links', 'seopress_broken_links_page_render' );
		}
		add_submenu_page( 'seopress-option', __( 'License', 'wp-seopress-pro' ), __( 'License', 'wp-seopress-pro' ), seopress_capability( 'manage_options', 'menu' ), 'seopress-license', array( $this, 'seopress_license_page' ) );
	}

	/**
	 * SEOPress PRO page.
	 *
	 * @return void
	 */
	public function seopress_pro_page() {
		require_once __DIR__ . '/admin-pages/Pro.php';
	}

	/**
	 * SEOPress license page.
	 *
	 * @return void
	 */
	public function seopress_license_page() {
		require_once __DIR__ . '/admin-pages/License.php';
	}

	/**
	 * Create network admin page.
	 *
	 * @return void
	 */
	public function create_network_admin_page() {
		require_once __DIR__ . '/admin-pages/NetworkAdmin.php';
	}

	/**
	 * Page init.
	 *
	 * @return void
	 */
	public function page_init() {
		require_once __DIR__ . '/settings/Main.php';
		require_once __DIR__ . '/blocks/features-list.php';
		require_once __DIR__ . '/blocks/dashboard-suite.php';
		if ( version_compare( SEOPRESS_VERSION, '6.3.1', '>' ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG === true ) ) {
			require_once __DIR__ . '/blocks/tasks.php';
		}
		require_once __DIR__ . '/wizard/wizard.php';
		require_once __DIR__ . '/admin-pages/Tools.php';
	}

	/**
	 * Metabox init.
	 *
	 * @return void
	 */
	public function metabox_init() {
		require_once __DIR__ . '/metaboxes/admin-metaboxes-form.php';
		require_once __DIR__ . '/metaboxes/admin-content-analysis-metaboxes-form.php';
	}

	/**
	 * Sanitize.
	 *
	 * @param array $input The input.
	 * @return array
	 */
	public function sanitize( $input ) {
		require_once __DIR__ . '/sanitize/Sanitize.php';

		return seopress_pro_sanitize_options_fields( $input );
	}

	/**
	 * Load sections.
	 *
	 * @return void
	 */
	public function load_sections() {
		require_once __DIR__ . '/ui-components.php';
		require_once __DIR__ . '/sections/Pro.php';
	}

	/**
	 * Load callbacks.
	 *
	 * @return void
	 */
	public function load_callbacks() {
	}

	/**
	 * Pre save options.
	 *
	 * @return void
	 */
	public function pre_save_options() {
		add_filter( 'pre_update_option_seopress_pro_option_name', array( $this, 'pre_seopress_pro_option_name' ), 10, 2 );
	}

	/**
	 * Pre seopress pro option name.
	 *
	 * @param array $new_value The new value.
	 * @param array $old_value The old value.
	 * @return array
	 */
	public function pre_seopress_pro_option_name( $new_value, $old_value ) {
		flush_rewrite_rules( false );
		return $new_value;
	}
}

if ( is_admin() ) {
	$my_settings_page = new seopress_pro_options();
}
