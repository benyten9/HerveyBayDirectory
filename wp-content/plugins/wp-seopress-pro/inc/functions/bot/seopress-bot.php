<?php // phpcs:ignore
/**
 * SEOPress PRO Bot class.
 *
 * @package SEOPress PRO
 * @subpackage Bot
 */

namespace SEOPressPRO;

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Bot class.
 */
class Bot {
	/**
	 * Holds the class object.
	 *
	 * @since 1.0.0
	 *
	 * @var object
	 */
	public static $instance;
	/**
	 * Unique plugin slug identifier.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $plugin_slug = 'seopress-bot-batch';
	/**
	 * Plugin file.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $file = __FILE__;
	/**
	 * Plugin menu hook.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $hook = false;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Load the plugin.
		add_action( 'init', array( $this, 'init' ), 0 );
	}

	/**
	 * Loads the plugin into WordPress.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ), 22 );
	}

	/**
	 * Loads the admin menu item under the SEOPress menu.
	 *
	 * @since 1.0.0
	 */
	public function menu() {
		if ( '1' == seopress_get_toggle_option( 'bot' ) ) {
			add_submenu_page( 'seopress-option', __( 'Audit', 'wp-seopress-pro' ), __( 'Audit', 'wp-seopress-pro' ), seopress_capability( 'manage_options', 'bot' ), $this->plugin_slug, array( $this, 'menu_cb' ), 9 );
		}
	}

	/**
	 * Outputs the menu view.
	 *
	 * Falls back to a "WordPress X+ required" notice on installs older than
	 * `\SEOPressPro\Helpers\DataViewsAssets::MIN_WP_VERSION` — the React app
	 * relies on `@wordpress/dataviews@14`, which crashes on private-API
	 * mismatches against older `@wordpress/components` builds.
	 *
	 * @since 1.0.0
	 */
	public function menu_cb() {
		if ( ! \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
			seopress_pro_render_dataviews_unsupported_notice( __( 'Site Audit', 'wp-seopress-pro' ) );
			return;
		}
		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Site Audit', 'wp-seopress-pro' ); ?></h1>
			<div id="seopress-site-audit-root">
				<noscript>
					<p><?php esc_html_e( 'Site Audit requires JavaScript to be enabled.', 'wp-seopress-pro' ); ?></p>
				</noscript>
			</div>
		</div>
		<?php
	}
}

$seopress_bot_batch = new Bot();
