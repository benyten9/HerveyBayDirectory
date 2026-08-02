<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

// Shared helpers (YouTube detection + Data API fetch with caching).
require_once __DIR__ . '/helpers-youtube-video.php';

/**
 * Import / Export tool.
 *
 * @return void
 */
function seopress_pro_enable() {
	if ( is_admin() ) {
		if ( is_plugin_active( 'wp-seopress/seopress.php' ) && defined( 'SEOPRESS_VERSION' ) ) {
			require_once __DIR__ . '/options-import-export.php'; // Import Export.
		}
	}
}
add_action( 'init', 'seopress_pro_enable', 999 );

// Local Business.
if ( '1' == seopress_get_toggle_option( 'local-business' ) ) {
	/**
	 * Register Local Business widget.
	 *
	 * @return void
	 */
	function seopress_pro_lb_register_widget() {
		require_once __DIR__ . '/options-local-business-widget.php'; // Local Business.
		register_widget( 'Local_Business_Widget' );
	}
	add_action( 'widgets_init', 'seopress_pro_lb_register_widget' );
}

// WooCommerce.
if ( '1' == seopress_get_toggle_option( 'woocommerce' ) ) {
	/**
	 * Display WooCommerce sitemap options.
	 *
	 * @return void
	 */
	function seopress_pro_woocommerce_sitemap() {
		if ( ! is_admin() ) {
			require_once __DIR__ . '/options-woocommerce-sitemap.php'; // WooCommerce sitemap.
		} else {
			require_once __DIR__ . '/options-woocommerce-admin.php'; // WooCommerce in admin.
		}
	}
	add_action( 'init', 'seopress_pro_woocommerce_sitemap', 0 );

	/**
	 * Display WooCommerce options.
	 *
	 * @return void
	 */
	function seopress_pro_woocommerce() {
		if ( ! is_admin() ) {
			require_once __DIR__ . '/options-woocommerce.php'; // WooCommerce.
		}
	}
	add_action( 'wp_head', 'seopress_pro_woocommerce', 0 );
}

// EDD.
if ( '1' == seopress_get_toggle_option( 'edd' ) ) {
	/**
	 * Display EDD options.
	 *
	 * @return void
	 */
	function seopress_pro_edd() {
		if ( ! is_admin() ) {
			require_once __DIR__ . '/options-edd.php'; // EDD.
		}
	}
	add_action( 'wp_head', 'seopress_pro_edd', 0 );
}

// Dublin Core.
if ( '1' == seopress_get_toggle_option( 'dublin-core' ) ) {
	/**
	 * Display Dublin Core options.
	 *
	 * @return void
	 */
	function seopress_pro_dublin_core() {
		if ( ! is_admin() ) {
			if ( ( function_exists( 'is_wpforo_page' ) && is_wpforo_page() ) || ( class_exists( 'Ecwid_Store_Page' ) && \Ecwid_Store_Page::is_store_page() ) ) { // Disable on wpForo pages to avoid conflicts.
				// Do nothing.
			} else {
				require_once __DIR__ . '/options-dublin-core.php'; // Dublin Core.
			}
		}
	}
	add_action( 'wp_head', 'seopress_pro_dublin_core', 0 );
}

// Rich Snippets.
if ( '1' == seopress_get_toggle_option( 'rich-snippets' ) ) {
	/**
	 * Display Rich Snippets options.
	 *
	 * @return void
	 */
	function seopress_pro_rich_snippets() {
		if ( ! is_admin() ) {
			require_once __DIR__ . '/options-automatic-rich-snippets.php'; // Automatic Rich Snippets.
		}
	}
	add_action( 'wp_head', 'seopress_pro_rich_snippets', 2 ); // Must be !=0.

	/**
	 * Display Schemas options.
	 *
	 * @return void
	 */
	function seopress_load_schemas_options() {
		require_once dirname( __DIR__ ) . '/admin/schemas/schemas.php'; // Schemas.
	}
	add_action( 'init', 'seopress_load_schemas_options', 9 );

	/**
	 * Display Schemas admin notice.
	 *
	 * @return void
	 */
	function seopress_pro_schemas_notice() {
		global $typenow;
		if ( current_user_can( seopress_capability( 'manage_options', 'notice' ) ) && ( isset( $typenow ) && 'seopress_schemas' === $typenow ) ) {
			if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getRichSnippetEnable() ) {
				?>
<div class="seopress-notice is-warning">
	<p>
				<?php echo wp_kses_post( __( 'Your schemas will <strong>not be output on the front end</strong> until you enable <strong>Enable Structured Data Types</strong> in <strong>SEO &rsaquo; PRO &rsaquo; Structured Data Types</strong>.', 'wp-seopress-pro' ) ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_rich_snippets' ) ); ?>"
			class="btn btnPrimary"><?php esc_html_e( 'Enable Structured Data Types', 'wp-seopress-pro' ); ?></a>
	</p>
</div>
				<?php
			}
		}
	}
	add_action( 'admin_notices', 'seopress_pro_schemas_notice' );
}

// Redirections.
if ( '1' === seopress_get_toggle_option( '404' ) ) {
	require_once __DIR__ . '/redirections/redirections.php';
}

// Breadcrumbs.
if ( '1' == seopress_get_toggle_option( 'breadcrumbs' ) ) {
	if ( '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsJsonEnable() ) {
		/**
		 * Display WooCommerce compatibility.
		 *
		 * @return void
		 */
		function seopress_pro_compatibility_wc() {
			// If WooCommerce, disable default JSON-LD Breadcrumbs to avoid conflicts.
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
				add_action( 'woocommerce_structured_data_breadcrumblist', '__return_false', 10, 2 );
				remove_action( 'storefront_before_content', 'woocommerce_breadcrumb', 10 );
			}
		}
		add_action( 'init', 'seopress_pro_compatibility_wc' );
	}
	require_once __DIR__ . '/options-breadcrumbs.php'; // Breadcrumbs.
}

/**
 * Display RSS options.
 *
 * @return void
 */
function seopress_pro_rss() {
	if ( ! is_admin() ) {
		require_once __DIR__ . '/options-rss.php'; // RSS.
	}
}
add_action( 'init', 'seopress_pro_rss', 0 );

// Rewrite.
if ( '1' === seopress_get_toggle_option( 'advanced' ) ) {
	/**
	 * Display Rewrite options.
	 *
	 * @return void
	 */
	function seopress_pro_rewrite() {
		require_once __DIR__ . '/options-rewrite.php'; // Rewrite.
	}
	add_action( 'init', 'seopress_pro_rewrite', 0 );
}

// White Label.
if ( method_exists( seopress_get_service( 'ToggleOption' ), 'getToggleWhiteLabel' ) && '1' === seopress_get_service( 'ToggleOption' )->getToggleWhiteLabel() ) {
	if ( is_admin() || is_network_admin() ) {
		require_once __DIR__ . '/options-white-label.php'; // White Label.
	}
}

// Robots.
if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'robots' ) ) {
	require_once __DIR__ . '/options-robots-txt.php'; // Robots.txt.
}

// LLMS.
if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'llms' ) ) {
	require_once __DIR__ . '/options-llms-txt.php'; // LLMS.txt.
}

// Agent readiness (Link headers, Content-Signal, markdown negotiation, .well-known endpoints).
// Opt-in via the "Agent Readiness" feature toggle on the SEO dashboard; filterable for programmatic control.
$seopress_agent_ready_toggle  = function_exists( 'seopress_get_toggle_option' ) ? seopress_get_toggle_option( 'agent-ready' ) : null;
$seopress_agent_ready_enabled = apply_filters( 'seopress_agent_ready_enabled', '1' === $seopress_agent_ready_toggle );
if ( $seopress_agent_ready_enabled ) {
	require_once __DIR__ . '/options-agent-ready.php'; // Agent readiness.
}

// Video XML sitemaps.
if ( '1' === seopress_get_toggle_option( 'xml-sitemap' ) && '1' === seopress_get_service( 'SitemapOption' )->isEnabled() && '1' === seopress_pro_get_service( 'SitemapOptionPro' )->getSitemapVideoEnable() ) {
	/**
	 * Display Video XML sitemap options.
	 *
	 * @param int    $post_id The post ID.
	 * @param object $post The post object.
	 * @param string $update The update.
	 * @return void
	 */
	function seopress_pro_video_xml_sitemap( $post_id, $post, $update = '' ) {
		// Use require (not require_once) so the detection runs on every call: the regenerate tool loops this callback over a batch of posts in a single request.
		require __DIR__ . '/options-video-sitemap.php'; // Video XML sitemap.
	}
	add_action( 'save_post', 'seopress_pro_video_xml_sitemap', 10, 3 );
}

// AI.
if ( '1' == seopress_get_toggle_option( 'ai' ) ) {
	/**
	 * Display AI options.
	 *
	 * @return void
	 */
	function seopress_pro_ai() {
		if ( is_admin() ) {
			require_once __DIR__ . '/options-ai-admin.php'; // AI admin.
		}
		require_once __DIR__ . '/options-ai.php'; // AI.
	}
	add_action( 'init', 'seopress_pro_ai', 11 );
}

/**
 * Display GA4 Ecommerce options.
 *
 * @return void
 */
function seopress_pro_ga_ecommerce() {
	if ( ! is_admin() ) {
		require_once __DIR__ . '/options-google-ecommerce.php'; // GA4 Ecommerce.
	}
}
add_action( 'seopress_ga4_before_sending_data', 'seopress_pro_ga_ecommerce' );
