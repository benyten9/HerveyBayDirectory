<?php
/**
 * Plugin Name: SEOPress PRO
 * Plugin URI: https://www.seopress.org/wordpress-seo-plugins/pro/
 * Description: The PRO version of SEOPress. SEOPress required (free).
 * Version: 10.2
 * Author: The SEO Guys at SEOPress
 * Author URI: https://www.seopress.org/wordpress-seo-plugins/pro/
 * License: GPLv3 or later
 * Text Domain: wp-seopress-pro
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.5
 *
 * @package SEOPressPRO
 */

/*
	Copyright 2016 - 2026 - Benjamin Denis  (email : contact@seopress.org)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 3, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// To prevent calling the plugin directly.
defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Define constants
 */
define( 'SEOPRESS_PRO_VERSION', '10.2' );
define( 'SEOPRESS_PRO_AUTHOR', 'Benjamin Denis' );
define( 'STORE_URL_SEOPRESS', 'https://www.seopress.org' );
define( 'ITEM_ID_SEOPRESS', 113 );
define( 'ITEM_NAME_SEOPRESS', 'SEOPress PRO' );
define( 'SEOPRESS_LICENSE_PAGE', 'seopress-license' );
define( 'SEOPRESS_PRO_PLUGIN_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEOPRESS_PRO_PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ) );
define( 'SEOPRESS_PRO_ASSETS_DIR', SEOPRESS_PRO_PLUGIN_DIR_URL . 'assets' );
define( 'SEOPRESS_PRO_PUBLIC_URL', SEOPRESS_PRO_PLUGIN_DIR_URL . 'public' );
define( 'SEOPRESS_PRO_PUBLIC_PATH', SEOPRESS_PRO_PLUGIN_DIR_PATH . 'public' );
define( 'SEOPRESS_PRO_TEMPLATE_DIR', SEOPRESS_PRO_PLUGIN_DIR_PATH . 'templates' );
define( 'SEOPRESS_PRO_TEMPLATE_JSON_SCHEMAS', SEOPRESS_PRO_TEMPLATE_DIR . '/json-schemas' );
define( 'SEOPRESS_PRO_TEMPLATE_STOP_WORDS', SEOPRESS_PRO_TEMPLATE_DIR . '/stop-words' );

/**
 * Kernel
 */
use SEOPressPro\Core\Kernel;
require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'seopress-autoload.php';

// The runtime (functions, kernel/services, scoped classes) is only available when both
// the Pro vendor autoload and the Free autoload are present. During a plugin update the
// directory is replaced in stages, so this can momentarily be false even on a live site.
// Runtime hooks below must be gated on this constant; the activation/deactivation and
// self-deactivation safety hooks (which only use core functions) stay unconditional.
define( 'SEOPRESS_PRO_RUNTIME_READY', file_exists( SEOPRESS_PRO_PLUGIN_DIR_PATH . '/vendor/autoload.php' ) && file_exists( WP_PLUGIN_DIR . '/wp-seopress/seopress-autoload.php' ) );

/**
 * Whether the free plugin is actually running, not merely installed.
 *
 * SEOPRESS_PRO_RUNTIME_READY proves the files are on disk, which is a different
 * question. The free plugin can be deactivated, or have its directory swapped
 * mid-update, while its autoloader still exists. Pro then boots against an API
 * that was never loaded, and every callback reaching into the free plugin
 * raises an `Error`. Pro does deactivate itself in that situation, but
 * `deactivate_plugins()` only takes effect from the next request: the hooks of
 * the current one are already armed and still fire.
 *
 * Load order between the two plugins is not guaranteed, so this can only be
 * answered from `plugins_loaded` onwards, never at file scope.
 *
 * @since 10.2.0
 *
 * @return bool
 */
function seopress_pro_is_free_runtime_available() {
	$available = function_exists( 'seopress_get_service' ) && function_exists( 'seopress_capability' );

	/**
	 * Filter whether Pro considers the free plugin runtime usable.
	 *
	 * @since 10.2.0
	 *
	 * @param bool $available Whether the free plugin API is loaded.
	 */
	return (bool) apply_filters( 'seopress_pro_free_runtime_available', $available );
}

if ( SEOPRESS_PRO_RUNTIME_READY ) {
	require_once WP_PLUGIN_DIR . '/wp-seopress/seopress-autoload.php';
	require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . '/seopress-pro-functions.php';
	require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . '/inc/admin/cron.php';

	Kernel::execute(
		array(
			'file'      => __FILE__,
			'slug'      => 'wp-seopress-pro',
			'main_file' => 'seopress-pro',
			'root'      => __DIR__,
		)
	);

	add_action( 'plugins_loaded', 'seopress_pro_plugins_loaded', 999 );
}

/**
 * CRON
 *
 * @return void
 */
function seopress_pro_cron() {
	// CRON - 404 cleaning.
	if ( ! wp_next_scheduled( 'seopress_404_cron_cleaning' ) ) {
		wp_schedule_event( time(), 'daily', 'seopress_404_cron_cleaning' );
	}

	// CRON - GA stats in dashboard.
	if ( ! wp_next_scheduled( 'seopress_google_analytics_cron' ) ) {
		wp_schedule_event( time(), 'hourly', 'seopress_google_analytics_cron' );
	}

	// CRON - Matomo stats in dashboard.
	if ( ! wp_next_scheduled( 'seopress_matomo_analytics_cron' ) ) {
		wp_schedule_event( time(), 'hourly', 'seopress_matomo_analytics_cron' );
	}

	// CRON - Page Speed Insights.
	if ( ! wp_next_scheduled( 'seopress_page_speed_insights_cron' ) ) {
		wp_schedule_event( time(), 'daily', 'seopress_page_speed_insights_cron' );
	}

	// CRON - 404 errors Email Alerts.
	if ( ! wp_next_scheduled( 'seopress_404_email_alerts_cron' ) ) {
		wp_schedule_event( time(), 'weekly', 'seopress_404_email_alerts_cron' );
	}

	// CRON - Insights from GSC.
	if ( ! wp_next_scheduled( 'seopress_insights_gsc_cron' ) ) {
		wp_schedule_event( time(), 'daily', 'seopress_insights_gsc_cron' );
	}

	// CRON - SEO Alerts.
	if ( ! wp_next_scheduled( 'seopress_alerts_cron' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'seopress_alerts_cron' );
	}
}

/**
 * Loaded
 *
 * @return void
 */
function seopress_pro_loaded() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		return;
	}

	if ( ! is_plugin_active( 'wp-seopress/seopress.php' ) ) {// If SEOPress Free NOT activated.
		deactivate_plugins( 'wp-seopress-pro/seopress-pro.php' );
		add_action( 'admin_notices', 'seopress_pro_admin_notices' );
	}
}
add_action( 'plugins_loaded', 'seopress_pro_loaded' );

/**
 * Install plugins
 *
 * @param string $plugin_slug The plugin slug.
 * @throws Exception If the plugin installation fails.
 * @return void
 */
function seopress_pro_install_plugin( $plugin_slug ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	WP_Filesystem();

	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new WP_Upgrader( $skin );

	if ( ! empty( $plugin_slug ) ) {
		ob_start();

		try {
			$plugin_information = plugins_api(
				'plugin_information',
				array(
					'slug'   => $plugin_slug,
					'fields' => array(
						'short_description' => false,
						'sections'          => false,
						'requires'          => false,
						'rating'            => false,
						'ratings'           => false,
						'downloaded'        => false,
						'last_updated'      => false,
						'added'             => false,
						'tags'              => false,
						'homepage'          => false,
						'donate_link'       => false,
						'author_profile'    => false,
						'author'            => false,
					),
				)
			);

			if ( is_wp_error( $plugin_information ) ) {
				throw new Exception( $plugin_information->get_error_message() );
			}

			$package  = $plugin_information->download_link;
			$download = $upgrader->download_package( $package );

			if ( is_wp_error( $download ) ) {
				throw new Exception( $download->get_error_message() );
			}

			$working_dir = $upgrader->unpack_package( $download, true );

			if ( is_wp_error( $working_dir ) ) {
				throw new Exception( $working_dir->get_error_message() );
			}

			$result = $upgrader->install_package(
				array(
					'source'                      => $working_dir,
					'destination'                 => WP_PLUGIN_DIR,
					'clear_destination'           => false,
					'abort_if_destination_exists' => false,
					'clear_working'               => true,
					'hook_extra'                  => array(
						'type'   => 'plugin',
						'action' => 'install',
					),
				)
			);

			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}

			$activate = true;
		} catch ( Exception $e ) {
			$e->getMessage();
		}

		ob_end_clean();
	}

	wp_clean_plugins_cache();
}

/**
 * Hooks activation
 *
 * @return void
 */
function seopress_pro_activation() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! function_exists( 'activate_plugins' ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		return;
	}

	$plugins = get_plugins();
	if ( empty( $plugins['wp-seopress/seopress.php'] ) ) { // If SEOPress Free is NOT installed.
		seopress_pro_install_plugin( 'wp-seopress' );
		activate_plugins( 'wp-seopress/seopress.php' );
	}

	if ( ! empty( $plugins['wp-seopress/seopress.php'] ) ) { // If SEOPress Free is installed.
		if ( ! is_plugin_active( 'wp-seopress/seopress.php' ) ) { // If SEOPress Free is not activated.
			activate_plugins( 'wp-seopress/seopress.php' );
		}
		add_option( 'seopress_pro_activated', 'yes', '', false );

		// Turn the AI Assistant on unless this site already has a preference.
		// The assistant stays inert until a provider is configured, so this only
		// removes a step for people who go on to set one up.
		if ( function_exists( 'seopress_pro_set_ai_assistant_default' ) ) {
			seopress_pro_set_ai_assistant_default();
		}

		// Force an immediate language pack install on the next admin request.
		set_transient( 'seopress_pro_install_langpack', 1, 5 * MINUTE_IN_SECONDS );

		flush_rewrite_rules( false );

		seopress_pro_cron();
	}

	// Add Redirections caps to user with "manage_options" capability.
	$roles = get_editable_roles();
	if ( ! empty( $roles ) ) {
		foreach ( $GLOBALS['wp_roles']->role_objects as $key => $role ) {
			if ( isset( $roles[ $key ] ) && $role->has_cap( 'manage_options' ) ) {
				$role->add_cap( 'edit_redirection' );
				$role->add_cap( 'edit_redirections' );
				$role->add_cap( 'edit_others_redirections' );
				$role->add_cap( 'publish_redirections' );
				$role->add_cap( 'read_redirection' );
				$role->add_cap( 'read_private_redirections' );
				$role->add_cap( 'delete_redirection' );
				$role->add_cap( 'delete_redirections' );
				$role->add_cap( 'delete_others_redirections' );
				$role->add_cap( 'delete_published_redirections' );
			}
			if ( isset( $roles[ $key ] ) && $role->has_cap( 'manage_options' ) ) {
				$role->add_cap( 'edit_schema' );
				$role->add_cap( 'edit_schemas' );
				$role->add_cap( 'edit_others_schemas' );
				$role->add_cap( 'publish_schemas' );
				$role->add_cap( 'read_schema' );
				$role->add_cap( 'read_private_schemas' );
				$role->add_cap( 'delete_schema' );
				$role->add_cap( 'delete_schemas' );
				$role->add_cap( 'delete_others_schemas' );
				$role->add_cap( 'delete_published_schemas' );
			}
		}
	}

	do_action( 'seopress_pro_activation' );
}
register_activation_hook( __FILE__, 'seopress_pro_activation' );

/**
 * Hooks deactivation
 *
 * @return void
 */
function seopress_pro_deactivation() {
	delete_option( 'seopress_pro_activated' );
	flush_rewrite_rules( false );
	wp_clear_scheduled_hook( 'seopress_404_cron_cleaning' );
	wp_clear_scheduled_hook( 'seopress_google_analytics_cron' );
	wp_clear_scheduled_hook( 'seopress_page_speed_insights_cron' );
	wp_clear_scheduled_hook( 'seopress_404_email_alerts_cron' );
	wp_clear_scheduled_hook( 'seopress_insights_gsc_cron' );
	wp_clear_scheduled_hook( 'seopress_matomo_analytics_cron' );
	wp_clear_scheduled_hook( 'seopress_404_background_cleanup' ); // Clear 404 limit cleanup cron.
	wp_clear_scheduled_hook( 'seopress_broken_links_run_task_cron' );
	do_action( 'seopress_pro_deactivation' );
}
register_deactivation_hook( __FILE__, 'seopress_pro_deactivation' );

/**
 * Loads the SEOPress PRO admin + core + API
 *
 * @return void
 */
function seopress_pro_plugins_loaded() {
	// The free plugin can be switched off, or have its directory swapped
	// mid-update, while Pro stays loaded for the rest of this request. Every
	// value below is read through the free plugin's service container.
	if ( ! seopress_pro_is_free_runtime_available() ) {
		return;
	}

	// CRON.
	seopress_pro_cron();

	global $pagenow;

	if ( ! function_exists( 'seopress_capability' ) ) {
		return;
	}

	$plugin_dir = plugin_dir_path( __FILE__ );

	if ( is_admin() || is_network_admin() ) {
		require_once $plugin_dir . '/inc/admin/admin.php';
		require_once $plugin_dir . '/inc/admin/ajax.php';

		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			require_once $plugin_dir . '/inc/admin/metaboxes/admin-metaboxes.php';
		}

		if ( 'index.php' === $pagenow || ( isset( $_GET['page'] ) && 'seopress-option' === $_GET['page'] ) ) {
			require_once $plugin_dir . '/inc/admin/wp-dashboard/google-analytics.php';
			require_once $plugin_dir . '/inc/admin/wp-dashboard/matomo.php';
		}

		// CSV Import.
		if ( wp_doing_ajax() || ( isset( $_GET['page'] ) && 'seopress_csv_importer' === $_GET['page'] ) ) {
			include_once $plugin_dir . '/inc/admin/import/class-csv-wizard.php';
		}

		// Setup wizard data injection (Pro license CTA, etc.).
		require_once $plugin_dir . '/inc/admin/wizard/wizard.php';

		// Bot.
		require_once $plugin_dir . '/inc/admin/bot/bot.php';
		require_once $plugin_dir . '/inc/functions/bot/seopress-bot.php';
	}

	// Watchers.
	if ( ! isset( $_GET['bricks'] ) ) {
		require_once $plugin_dir . '/inc/admin/watchers/index.php';
	}

	// Redirections.
	// Loaded in admin, REST, and frontend so that the CPT + taxonomy are always
	// registered — needed by REST endpoints (/wp-json/seopress/v1/redirections/*)
	// and by the frontend redirection handler.
	if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( '404' ) ) {
		require_once $plugin_dir . '/inc/admin/redirections/redirections.php';
	}

	// Bricks.
	if ( ! isset( $_GET['bricks'] ) ) {
		require_once $plugin_dir . '/inc/functions/options.php';
	}

	require_once $plugin_dir . '/inc/admin/admin-bar/admin-bar.php';

	// AI Assistant.
	require_once $plugin_dir . '/inc/admin/ai-assistant/ai-assistant.php';

	// Elementor.
	if ( did_action( 'elementor/loaded' ) ) {
		require_once $plugin_dir . '/inc/admin/page-builders/elementor/elementor-widgets.php';
	}

	// TranslationsPress.
	// Load in every context (admin, CLI, cron) so the site_transient_update_plugins
	// filter that injects our packages.translationspress.com entries also runs
	// during WP-Cron / WP-CLI / MainWP background flows. Without this, the entry
	// only exists during admin pageloads, gets wiped by background
	// wp_update_plugins() calls, and Language_Pack_Upgrader reports
	// "Translations are up to date" without ever installing the .mo. See #1357.
	if ( ! class_exists( 'SEOPRESS_Language_Packs' ) ) {
		require_once $plugin_dir . '/inc/admin/updater/t15s-registry.php';
	}

	// Register the PRO pack with the free plugin's on-demand language pack installer.
	require_once $plugin_dir . '/inc/functions/language-packs.php';

	// Blocks registration.
	require_once $plugin_dir . '/inc/functions/blocks.php';
}

/**
 * Loads the SEOPress PRO i18n.
 *
 * @return void
 */
function seopress_pro_init_t15s() {
	// i18n.
	load_plugin_textdomain( 'wp-seopress-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	if ( class_exists( 'SEOPRESS_Language_Packs' ) ) {
		$t15s_updater = new SEOPRESS_Language_Packs(
			'wp-seopress-pro',
			'https://packages.translationspress.com/seopress/wp-seopress-pro/packages.json'
		);
	}
}
add_action( 'init', 'seopress_pro_init_t15s' );

/**
 * Loads the JS/CSS in admin.
 *
 * @return void
 */
function seopress_pro_admin_scripts() {
	// The free plugin can be switched off, or have its directory swapped
	// mid-update, while Pro stays loaded for the rest of this request. Every
	// value below is read through the free plugin's service container.
	if ( ! seopress_pro_is_free_runtime_available() ) {
		return;
	}

	$prefix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

	if ( seopress_get_service( 'ToggleOption' )->getToggleAi() !== '1' ) {
		return;
	}

	$seopress_ai_generate_seo_meta = array(
		'seopress_nonce'                => wp_create_nonce( 'seopress_ai_generate_seo_meta_nonce' ),
		'seopress_ai_generate_seo_meta' => admin_url( 'admin-ajax.php' ),
		'ai_logs_url'                   => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_ai' ),
		'i18n'                          => array(
			'alt_text_not_found'        => __( 'Alternative text input could not be found.', 'wp-seopress-pro' ),
			'bulk_token_expired'        => __( 'Your security token has expired. Please reload the page and run the bulk action again.', 'wp-seopress-pro' ),
			'bulk_request_error'        => __( 'The bulk AI generation could not be completed. Please try again.', 'wp-seopress-pro' ),
			/* translators: %s: reason returned by the AI provider */
			'bulk_request_error_reason' => __( 'The bulk AI generation could not be completed: %s', 'wp-seopress-pro' ),
			'check_logs'                => __( 'Check out logs', 'wp-seopress-pro' ),
		),
	);

	wp_enqueue_script( 'seopress-pro-ai', plugins_url( 'assets/js/seopress-pro-ai' . $prefix . '.js', __FILE__ ), array( 'jquery' ), SEOPRESS_PRO_VERSION, true );
	wp_localize_script( 'seopress-pro-ai', 'seopressAjaxAIMetaSEO', $seopress_ai_generate_seo_meta );
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'seopress_seo_metabox_init', 'seopress_pro_admin_scripts' );
}

/**
 * Loads Google Page Speed Insights scripts.
 *
 * @return void
 */
function seopress_pro_admin_ps_scripts() {
	$prefix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

	wp_enqueue_script( 'seopress-page-speed', plugins_url( 'assets/js/seopress-page-speed' . $prefix . '.js', __FILE__ ), array( 'jquery', 'jquery-ui-accordion' ), SEOPRESS_PRO_VERSION, true );

	$seopress_request_page_speed = array(
		'seopress_nonce'              => wp_create_nonce( 'seopress_request_page_speed_nonce' ),
		'seopress_request_page_speed' => admin_url( 'admin-ajax.php' ),
	);
	wp_localize_script( 'seopress-page-speed', 'seopressAjaxRequestPageSpeed', $seopress_request_page_speed );

	$seopress_clear_page_speed_cache = array(
		'seopress_nonce'                  => wp_create_nonce( 'seopress_clear_page_speed_cache_nonce' ),
		'seopress_clear_page_speed_cache' => admin_url( 'admin-ajax.php' ),
	);
	wp_localize_script( 'seopress-page-speed', 'seopressAjaxClearPageSpeedCache', $seopress_clear_page_speed_cache );
}

/**
 * Enqueues scripts for the SEOPRESS PRO options page
 *
 * @param string $hook The current admin screen.
 * @return void
 */
function seopress_pro_add_admin_options_scripts( $hook ) {
	// The free plugin can be switched off, or have its directory swapped
	// mid-update, while Pro stays loaded for the rest of this request. Every
	// value below is read through the free plugin's service container.
	if ( ! seopress_pro_is_free_runtime_available() ) {
		return;
	}

	$prefix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
	global $typenow;
	global $pagenow;

	wp_register_style( 'seopress-pro-admin', plugins_url( 'assets/css/seopress-pro' . $prefix . '.css', __FILE__ ), array(), SEOPRESS_PRO_VERSION );

	// Scope the Pro admin stylesheet to the screens that use it instead of
	// loading it on every wp-admin page: SEOPress pages and SEOPress CPT screens
	// (via is_seopress_page()), plus the post & term editors / list tables that
	// render the schema, rich-snippets, redirections and SEO-column markup. The
	// Pro GA/Matomo Dashboard widget does not use this file (its markup is styled
	// by seopress-pro-dashboard.css and the free seopress.css), so the WP
	// Dashboard is intentionally excluded.
	$pro_css_hooks   = array( 'post.php', 'post-new.php', 'edit.php', 'edit-tags.php', 'term.php' );
	$needs_pro_style = ( function_exists( 'is_seopress_page' ) && is_seopress_page() ) || in_array( $hook, $pro_css_hooks, true );

	/**
	 * Filter whether the SEOPress PRO admin stylesheet should load on the current screen.
	 * Lets add-ons force-load it on extra screens they render Pro markup on.
	 *
	 * @param bool   $needs_style Whether to enqueue the stylesheet.
	 * @param string $hook        Current admin page hook suffix.
	 */
	$needs_pro_style = (bool) apply_filters( 'seopress_pro_enqueue_admin_style', $needs_pro_style, $hook );

	if ( $needs_pro_style ) {
		wp_enqueue_style( 'seopress-pro-admin' );
	}

	// AI in post types list.
	if ( 'edit.php' === $hook ) {
		seopress_pro_admin_scripts();
	}

	// AI in the post/term editors (media-modal button from the SEO metabox
	// Social tab). The universal (React) metabox no longer renders the classic
	// metabox for regular post types, so seopress_seo_metabox_init never fires
	// there; the term metabox never fired it either. Without this, the
	// media-modal AI script (which defines the global SEOPRESSMedia used by the
	// button's onclick) is missing and the button throws a ReferenceError.
	// Enqueue it directly on the editor screens so the button keeps working.
	// See wp-seopress-pro#1409.
	if ( in_array( $hook, array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php' ), true ) ) {
		seopress_pro_admin_scripts();
	}

	// SEO Dashboard.
	wp_register_style( 'seopress-ga-dashboard-widget', plugins_url( 'assets/css/seopress-pro-dashboard' . $prefix . '.css', __FILE__ ), array(), SEOPRESS_PRO_VERSION );
	if ( isset( $_GET['page'] ) && 'seopress-option' === $_GET['page'] ) {
		wp_enqueue_style( 'seopress-ga-dashboard-widget' );
	}

	// Dashboard GA.
	if ( isset( get_current_screen()->id ) && get_current_screen()->id === 'dashboard' || ( isset( $_GET['page'] ) && 'seopress-option' === $_GET['page'] ) ) {
		if ( ( current_user_can( seopress_capability( 'manage_options', 'cron' ) ) || seopress_advanced_security_ga_widget_check() === true ) ) {
			if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'google-analytics' ) ) {
				$service = seopress_pro_get_service( 'GoogleAnalyticsWidgetsOptionPro' );

				if ( ! empty( $service ) && method_exists( $service, 'getGA4DashboardWidget' ) && '1' !== $service->getGA4DashboardWidget() ) {
					wp_enqueue_style( 'seopress-ga-dashboard-widget' );

					// GA API.
					wp_enqueue_script( 'seopress-pro-ga-embed', plugins_url( 'assets/js/chart.bundle.min.js', __FILE__ ), array(), SEOPRESS_PRO_VERSION, true );

					wp_enqueue_script( 'seopress-pro-ga', plugins_url( 'assets/js/seopress-pro-ga' . $prefix . '.js', __FILE__ ), array( 'jquery', 'jquery-ui-tabs' ), SEOPRESS_PRO_VERSION, true );

					$seopress_request_google_analytics = array(
						'seopress_nonce' => wp_create_nonce( 'seopress_request_google_analytics_nonce' ),
						'seopress_request_google_analytics' => admin_url( 'admin-ajax.php' ),
					);
					wp_localize_script( 'seopress-pro-ga', 'seopressAjaxRequestGoogleAnalytics', $seopress_request_google_analytics );
				}
			}
		}
	}

	// Dashboard Matomo.
	if ( isset( get_current_screen()->id ) && get_current_screen()->id === 'dashboard' || ( isset( $_GET['page'] ) && 'seopress-option' === $_GET['page'] ) ) {
		if ( ( current_user_can( seopress_capability( 'manage_options', 'cron' ) ) || seopress_advanced_security_matomo_widget_check() === true ) ) {
			if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'google-analytics' ) ) {
				$service = seopress_pro_get_service( 'GoogleAnalyticsWidgetsOptionPro' );
				if ( ! empty( $service ) && method_exists( $service, 'getMatomoDashboardWidget' ) && '1' !== $service->getMatomoDashboardWidget() ) {
					wp_enqueue_style( 'seopress-ga-dashboard-widget' );

					// Matomo API.
					wp_enqueue_script( 'seopress-pro-ga-embed', plugins_url( 'assets/js/chart.bundle.min.js', __FILE__ ), array(), SEOPRESS_PRO_VERSION, true );

					wp_enqueue_script( 'seopress-pro-matomo', plugins_url( 'assets/js/seopress-pro-matomo' . $prefix . '.js', __FILE__ ), array( 'jquery', 'jquery-ui-tabs' ), SEOPRESS_PRO_VERSION, true );

					$seopress_request_matomo_analytics = array(
						'seopress_nonce' => wp_create_nonce( 'seopress_request_matomo_analytics_nonce' ),
						'seopress_request_matomo_analytics' => admin_url( 'admin-ajax.php' ),
					);
					wp_localize_script( 'seopress-pro-matomo', 'seopressAjaxRequestMatomoAnalytics', $seopress_request_matomo_analytics );
				}
			}
		}
	}

	// Local Business widget.
	if ( 'widgets.php' === $pagenow ) {
		wp_enqueue_script( 'seopress-pro-lb-widget', plugins_url( 'assets/js/seopress-pro-lb-widget' . $prefix . '.js', __FILE__ ), array( 'jquery', 'jquery-ui-tabs' ), SEOPRESS_PRO_VERSION, true );

		$seopress_pro_lb_widget = array(
			'seopress_nonce'         => wp_create_nonce( 'seopress_pro_lb_widget_nonce' ),
			'seopress_pro_lb_widget' => admin_url( 'admin-ajax.php' ),
		);
		wp_localize_script( 'seopress-pro-lb-widget', 'seopressAjaxLocalBusinessOrder', $seopress_pro_lb_widget );
	}

	// .htaccess: Network Admin page now uses React (HtaccessTab component handles AJAX save).

	// Google Page Speed.
	if ( 'edit.php' === $hook ) {
		seopress_pro_admin_ps_scripts();
	}

	// Media Library.
	if ( 'upload.php' === $pagenow || 'attachment' === $typenow ) {
		$active = seopress_get_service( 'ToggleOption' )->getToggleAi();

		if ( '1' === $active ) {
			$seopress_ai_generate_seo_meta = array(
				'seopress_nonce'                => wp_create_nonce( 'seopress_ai_generate_seo_meta_nonce' ),
				'seopress_ai_generate_seo_meta' => admin_url( 'admin-ajax.php' ),
				'ai_logs_url'                   => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_ai' ),
				'i18n'                          => array(
					'alt_text_not_found'        => __( 'Alternative text input could not be found.', 'wp-seopress-pro' ),
					'bulk_token_expired'        => __( 'Your security token has expired. Please reload the page and run the bulk action again.', 'wp-seopress-pro' ),
					'bulk_request_error'        => __( 'The bulk AI generation could not be completed. Please try again.', 'wp-seopress-pro' ),
					/* translators: %s: reason returned by the AI provider */
					'bulk_request_error_reason' => __( 'The bulk AI generation could not be completed: %s', 'wp-seopress-pro' ),
					'check_logs'                => __( 'Check out logs', 'wp-seopress-pro' ),
				),
			);

			wp_enqueue_script( 'seopress-pro-ai', plugins_url( 'assets/js/seopress-pro-ai' . $prefix . '.js', __FILE__ ), array( 'jquery' ), SEOPRESS_PRO_VERSION, true );

			wp_localize_script( 'seopress-pro-ai', 'seopressAjaxAIMetaSEO', $seopress_ai_generate_seo_meta );
		}
	}

	// Video xml sitemap.
	if ( isset( $_GET['page'] ) && 'seopress-import-export' === $_GET['page'] ) {
		wp_enqueue_script( 'seopress-pro-video-sitemap-ajax', plugins_url( 'assets/js/seopress-pro-video-sitemap' . $prefix . '.js', __FILE__ ), array( 'jquery' ), SEOPRESS_PRO_VERSION, true );

		// Force regenerate video xml sitemap.
		$seopress_video_regenerate = array(
			'seopress_nonce'            => wp_create_nonce( 'seopress_video_regenerate_nonce' ),
			'seopress_video_regenerate' => admin_url( 'admin-ajax.php' ),
			'i18n'                      => array(
				'video' => __( 'Regeneration completed!', 'wp-seopress-pro' ),
			),
		);
		wp_localize_script( 'seopress-pro-video-sitemap-ajax', 'seopressAjaxVdeoRegenerate', $seopress_video_regenerate );
	}

	// Chatbot.
	if ( function_exists( 'is_seopress_page' ) && is_seopress_page() ) {
		$livechat         = seopress_pro_get_service( 'AdvancedOptionPro' )->getAppearanceDashboardLiveChat();
		$is_seopress_page = 0;
		$display_livechat = 1;

		if ( '1' === $livechat ) {
			$display_livechat = 0;
		}

		if ( 'seopress_404' !== get_current_screen()->post_type && 'seopress_bot' !== get_current_screen()->post_type && 'seopress_schemas' !== get_current_screen()->post_type ) {
			$is_seopress_page = 1;
		}
		if ( 1 === $display_livechat && 1 === $is_seopress_page ) {
			wp_enqueue_script( 'seopress-pro-chatbot', plugins_url( 'assets/js/seopress-pro-chatbot.min.js', __FILE__ ), array(), SEOPRESS_PRO_VERSION, true );
			wp_add_inline_style( 'seopress-pro-admin', '#chatbase-bubble-button::after {content: " ' . esc_html__( 'Help?', 'wp-seopress-pro' ) . '";}' );
		}
	}
}

if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'admin_enqueue_scripts', 'seopress_pro_add_admin_options_scripts', 10, 1 );
}

/**
 * Get WordPress 7.0+ connector keys availability for AI providers.
 *
 * @since 9.7.0
 *
 * @return array Associative array of SEOPress provider IDs with available connector keys.
 */
function seopress_pro_get_wp_connector_keys() {
	$map = array(
		'openai'  => 'openai',
		'gemini'  => 'google',
		'claude'  => 'anthropic',
	);

	$result = array();

	if ( ! function_exists( 'wp_get_connector' ) ) {
		return $result;
	}

	foreach ( $map as $seopress_id => $connector_id ) {
		$connector = wp_get_connector( $connector_id );

		if ( null === $connector ) {
			continue;
		}

		$auth = isset( $connector['authentication'] ) ? $connector['authentication'] : array();

		if ( 'api_key' !== ( isset( $auth['method'] ) ? $auth['method'] : '' ) || empty( $auth['setting_name'] ) ) {
			continue;
		}

		$key = get_option( $auth['setting_name'], '' );

		if ( ! empty( $key ) ) {
			$result[ $seopress_id ] = true;
		}
	}

	return $result;
}

/**
 * Register PRO page in the free plugin's SPA via filters.
 */
function seopress_pro_register_react_page() {
	// Add PRO to supported pages.
	add_filter( 'seopress_settings_supported_pages', function ( $pages ) {
		$pages['seopress-pro-page'] = array(
			'type'   => 'pro',
			'option' => 'seopress_pro_option_name',
		);
		$pages['seopress-network-option'] = array(
			'type'   => 'network-admin',
			'option' => 'seopress_pro_mu_option_name',
		);
		// The License page is action-driven (activate / deactivate / reset /
		// debug), not a single options blob: no `option` and, deliberately, no
		// entry in `seopress_settings_api_endpoints` so the shell renders it
		// without the generic settings fetch. It talks to /seopress/v1/license.
		$pages['seopress-license'] = array(
			'type'   => 'license',
			'option' => null,
		);
		return $pages;
	} );

	// Add PRO to navigation.
	add_filter( 'seopress_settings_all_pages', function ( $pages ) {
		$pages[] = array(
			'slug'    => 'seopress-pro-page',
			'type'    => 'pro',
			'feature' => null,
			'label'   => __( 'PRO', 'wp-seopress-pro' ),
			'url'     => admin_url( 'admin.php?page=seopress-pro-page' ),
		);
		$pages[] = array(
			'slug'    => 'seopress-network-option',
			'type'    => 'network-admin',
			'feature' => null,
			'label'   => __( 'SEO Network settings', 'wp-seopress-pro' ),
			'url'     => admin_url( 'admin.php?page=seopress-network-option' ),
		);
		$pages[] = array(
			'slug'    => 'seopress-license',
			'type'    => 'license',
			'feature' => null,
			'label'   => __( 'License', 'wp-seopress-pro' ),
			'url'     => admin_url( 'admin.php?page=seopress-license' ),
		);
		return $pages;
	} );

	// Mark PRO as React-ready.
	add_filter( 'seopress_settings_react_ready_pages', function ( $pages ) {
		$pages[] = 'pro';
		$pages[] = 'network-admin';
		$pages[] = 'license';
		return $pages;
	} );

	// Register REST endpoints for SettingsContext.
	add_filter( 'seopress_settings_api_endpoints', function ( $endpoints ) {
		$endpoints['pro']           = '/seopress/v1/options/pro-settings';
		$endpoints['network-admin'] = '/seopress/v1/options/pro-mu-settings';
		return $endpoints;
	} );

	// Register PRO feature toggle keys so they appear in SEOPRESS_SETTINGS_DATA.FEATURE_TOGGLES.
	add_filter( 'seopress_settings_feature_toggle_keys', function ( $features ) {
		$pro_features = array(
			'404', 'rich-snippets', 'local-business', 'woocommerce', 'edd',
			'dublin-core', 'robots', 'llms', 'news', 'inspect-url',
			'breadcrumbs', 'white-label', 'ai', 'ai-assistant', 'alerts', 'agent-ready',
		);
		return array_merge( $features, $pro_features );
	} );
}
add_action( 'plugins_loaded', 'seopress_pro_register_react_page' );

/**
 * Enqueue PRO React settings bundle on SEOPress settings pages.
 * Loads after the free plugin's settings JS so the extension registry is available.
 */
function seopress_pro_enqueue_react_settings( $hook ) {
	// This callback is registered at file scope rather than through the kernel,
	// so it survives the guard there and has to answer the same question: it
	// reads a dozen values through the free plugin's service container, which
	// does not exist when the free plugin is not running.
	if ( ! seopress_pro_is_free_runtime_available() ) {
		return;
	}

	// Match by page slug suffix -- the hook prefix varies depending on the parent menu slug
	// (e.g. seopress_page_, seo_page_, or custom white-label prefix).
	//
	// seopress-option (Dashboard) is included because free 9.9.1+ hosts
	// the Dashboard inside the unified Settings React shell. A user
	// landing there can SPA-navigate to any PRO page (Tools, Schemas,
	// Redirections...) without a reload, so the PRO settings bundle that
	// registers those pages and tabs into window.seopressExtensions must
	// already be on the page.
	$page_slugs = array(
		'seopress-option',
		'seopress-titles',
		'seopress-xml-sitemap',
		'seopress-social',
		'seopress-google-analytics',
		'seopress-instant-indexing',
		'seopress-advanced',
		'seopress-import-export',
		'seopress-pro-page',
		'seopress-network-option',
		'seopress-license',
	);

	$is_settings_page = false;
	$matched_slug     = '';
	foreach ( $page_slugs as $slug ) {
		if ( str_ends_with( $hook, '_page_' . $slug ) || 'toplevel_page_' . $slug === $hook ) {
			$is_settings_page = true;
			$matched_slug     = $slug;
			break;
		}
	}

	if ( ! $is_settings_page ) {
		return;
	}

	$asset_file = plugin_dir_path( __FILE__ ) . 'public/admin/settings/index.asset.php';
	$asset      = file_exists( $asset_file ) ? require $asset_file : array( 'dependencies' => array(), 'version' => SEOPRESS_PRO_VERSION );

	// Only depend on the free unified Settings shell when it is registered,
	// to avoid a _doing_it_wrong notice (WP 6.9.1+) listing an unregistered
	// dependency.
	$settings_deps = $asset['dependencies'];
	if ( wp_script_is( 'seopress-admin-settings', 'registered' ) ) {
		$settings_deps[] = 'seopress-admin-settings';
	}

	wp_enqueue_script(
		'seopress-pro-admin-settings',
		plugins_url( 'public/admin/settings/index.js', __FILE__ ),
		$settings_deps,
		$asset['version'],
		true
	);

	// Load translations for the PRO React settings bundle.
	// Point to wp-content/languages/plugins/ where TranslationsPress language packs are stored.
	wp_set_script_translations( 'seopress-pro-admin-settings', 'wp-seopress-pro', WP_LANG_DIR . '/plugins' );

	// The settings bundle is code-split, and wp_set_script_translations() only
	// loads the JSON matching the entry file. Merge the chunk JSONs into it.
	\SEOPressPro\Helpers\ScriptTranslations::merge_chunks( 'seopress-pro-admin-settings', 'wp-seopress-pro' );

	// Localize PRO tools data on all SEOPress settings pages (needed for SPA navigation).
	{
		$is_redirections_enabled = function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( '404' );

		$is_video_sitemap_enabled = false;
		if ( function_exists( 'seopress_get_toggle_option' )
			&& '1' === seopress_get_toggle_option( 'xml-sitemap' )
			&& function_exists( 'seopress_get_service' )
			&& '1' === seopress_get_service( 'SitemapOption' )->isEnabled()
			&& function_exists( 'seopress_pro_get_service' )
			&& method_exists( seopress_pro_get_service( 'SitemapOptionPro' ), 'getSitemapVideoEnable' )
			&& '1' === seopress_pro_get_service( 'SitemapOptionPro' )->getSitemapVideoEnable()
		) {
			$is_video_sitemap_enabled = true;
		}

		$docs = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : array();

		wp_localize_script( 'seopress-pro-admin-settings', 'SEOPRESS_PRO_TOOLS_DATA', array(
			'ADMIN_URL'                => admin_url(),
			'GA_OAUTH_NONCE'           => wp_create_nonce( 'seopress_ga_oauth_nonce' ),
			'GA_REDIRECT_URI'          => admin_url( 'admin.php?page=seopress-google-analytics' ),
			'CSV_IMPORTER_URL'         => admin_url( 'admin.php?page=seopress_csv_importer' ),
			'IS_REDIRECTIONS_ENABLED'  => $is_redirections_enabled,
			'IS_VIDEO_SITEMAP_ENABLED' => $is_video_sitemap_enabled,
			'VIDEO_NONCE'              => wp_create_nonce( 'seopress_video_regenerate_nonce' ),
			'CSV_EXPORT_NONCE'         => wp_create_nonce( 'seopress_export_csv_metadata_nonce' ),
			'PRO_PAGE_URL'             => admin_url( 'admin.php?page=seopress-pro-page' ),
			'SITEMAP_PAGE_URL'         => admin_url( 'admin.php?page=seopress-xml-sitemap' ),
			'VIDEO_SITEMAP_URL'        => get_option( 'home' ) . '/video1.xml',
			'REDIRECTS_NONCES'         => array(
				'import_redirections'         => wp_create_nonce( 'seopress_import_redirections_nonce' ),
				'import_redirections_plugin'  => wp_create_nonce( 'seopress_import_redirections_plugin_nonce' ),
				'import_yoast_redirections'   => wp_create_nonce( 'seopress_import_yoast_redirections_nonce' ),
				'import_rk_redirections'      => wp_create_nonce( 'seopress_import_rk_redirections_nonce' ),
				'import_rk_csv_redirections'  => wp_create_nonce( 'seopress_import_rk_csv_redirections_nonce' ),
				'import_slimseo_redirections' => wp_create_nonce( 'seopress_import_slimseo_redirections_nonce' ),
				'import_aioseo_redirections'  => wp_create_nonce( 'seopress_import_aioseo_redirections_nonce' ),
				'import_smartcrawl_redirections' => wp_create_nonce( 'seopress_import_smartcrawl_redirections_nonce' ),
				'export_redirections'         => wp_create_nonce( 'seopress_export_redirections_nonce' ),
				'export_redirections_htaccess' => wp_create_nonce( 'seopress_export_redirections_htaccess_nonce' ),
				'export_slug_changes'         => wp_create_nonce( 'seopress_export_slug_changes_nonce' ),
				'export_404'                  => wp_create_nonce( 'seopress_export_404_nonce' ),
				'clean_404'                   => wp_create_nonce( 'seopress_clean_404_nonce' ),
				'clean_counters'              => wp_create_nonce( 'seopress_clean_counters_nonce' ),
				'clean_all'                   => wp_create_nonce( 'seopress_clean_all_nonce' ),
			),
			'REDIRECTIONS_LIST_URL'    => admin_url( 'admin.php?page=seopress-redirections' ),
			'DOCS_REDIRECTS_QUERY'     => isset( $docs['redirects']['query'] ) ? $docs['redirects']['query'] : '',
			'CSV_EXAMPLE_URL'          => isset( $docs['redirects']['csv_example'] ) ? $docs['redirects']['csv_example'] : '',
			'CSV_IMPORT_RESULT'        => ( function () {
				$key    = 'seopress_csv_import_result_' . get_current_user_id();
				$result = get_transient( $key );
				if ( false !== $result ) {
					delete_transient( $key );
					return $result;
				}
				return null;
			} )(),
		) );
	}

	// Localize License page data on all SEOPress settings pages (needed for SPA navigation).
	{
		if ( ! function_exists( 'seopress_pro_get_license_state' ) ) {
			require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/admin/callbacks/License.php';
		}

		// Reuse the docs array already built for the Tools data localize above
		// (PHP has no block scope) instead of rebuilding it a second time.
		$license_docs = isset( $docs ) && is_array( $docs )
			? $docs
			: ( function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : array() );

		wp_localize_script( 'seopress-pro-admin-settings', 'SEOPRESS_LICENSE_DATA', array(
			'STATE'       => seopress_pro_get_license_state(),
			'REST_URL'    => esc_url_raw( rest_url( 'seopress/v1/license' ) ),
			'PAGE_URL'    => admin_url( 'admin.php?page=seopress-license' ),
			'ACCOUNT_URL' => isset( $license_docs['license']['account'] ) ? $license_docs['license']['account'] : '',
			'DEFINE_URL'  => isset( $license_docs['license']['license_define'] ) ? $license_docs['license']['license_define'] : '',
			'ERRORS_URL'  => isset( $license_docs['license']['license_errors'] ) ? $license_docs['license']['license_errors'] : '',
			'SUPPORT_URL' => isset( $license_docs['support'] ) ? $license_docs['support'] : '',
		) );
	}

	// Localize PRO settings data on the PRO page.
	if ( 'seopress-pro-page' === $matched_slug ) {
		$pro_options = get_option( 'seopress_pro_option_name', array() );

		// Read .htaccess content and determine status.
		$htaccess_content = '';
		$htaccess_status  = 'ok';
		$htaccess_path    = ABSPATH . '.htaccess';

		if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT === true )
			|| ( defined( 'SEOPRESS_BLOCK_HTACCESS' ) && SEOPRESS_BLOCK_HTACCESS === true ) ) {
			$htaccess_status = 'blocked';
		} elseif ( ! is_network_admin() && is_multisite() ) {
			$htaccess_status = 'multisite';
		} elseif ( isset( $_SERVER['SERVER_SOFTWARE'] ) && 0 === stripos( $_SERVER['SERVER_SOFTWARE'], 'nginx' ) ) {
			$htaccess_status = 'nginx';
		} elseif ( ! file_exists( $htaccess_path ) || ! is_writable( $htaccess_path ) ) {
			$htaccess_status = 'not_writable';
		} else {
			$htaccess_content = file_get_contents( $htaccess_path ); // phpcs:ignore
		}

		// Get post types for Google News and breadcrumbs.
		// Reuse the free plugin's WordPressData::getPostTypes() so the shared
		// blocklist (bricks_template, elementor_library, ct_template, seopress_*,
		// cuar_*, etc.) and the `seopress_post_types` filter both apply — every
		// other settings screen relies on this list.
		$post_types = function_exists( 'seopress_get_service' )
			? seopress_get_service( 'WordPressData' )->getPostTypes()
			: get_post_types( array( 'public' => true ), 'objects' );
		$pt_list    = array();
		foreach ( $post_types as $pt ) {
			$pt_list[] = array(
				'name'  => $pt->name,
				'label' => $pt->label,
			);
		}

		// Get taxonomies for breadcrumbs.
		// Same reasoning as the post-types block above: reuse WordPressData
		// so the taxonomies blocklist (seopress_bl_competitors, template_tag,
		// template_bundle) and the `seopress_get_taxonomies_list` filter apply.
		$taxonomies = function_exists( 'seopress_get_service' )
			? seopress_get_service( 'WordPressData' )->getTaxonomies()
			: get_taxonomies( array( 'public' => true ), 'objects' );
		$tax_list   = array();
		foreach ( $taxonomies as $tax ) {
			$tax_list[] = array(
				'name'  => $tax->name,
				'label' => $tax->label,
			);
		}

		// Get local business types.
		$local_business_types = array();
		if ( class_exists( 'SEOPressPro\Helpers\Settings\LocalBusinessHelper' ) ) {
			$local_business_types = \SEOPressPro\Helpers\Settings\LocalBusinessHelper::getListTypes();
		}

		// Get docs links.
		$docs_links = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : array();

		wp_localize_script( 'seopress-pro-admin-settings', 'SEOPRESS_PRO_DATA', array(
			// WordPress 7.0+ connector keys availability.
			'WP_CONNECTOR_KEYS'       => seopress_pro_get_wp_connector_keys(),

			// Tab visibility conditions.
			'IS_WOOCOMMERCE_ACTIVE'   => is_plugin_active( 'woocommerce/woocommerce.php' ),
			'IS_EDD_ACTIVE'           => is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' ),
			'IS_MULTISITE'            => is_multisite(),
			'IS_SUBDOMAIN_INSTALL'    => defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL,
			'IS_SUBDIRECTORY_INSTALL' => defined( 'SUBDOMAIN_INSTALL' ) && ! SUBDOMAIN_INSTALL,
			'IS_ELEMENTOR_ACTIVE'     => did_action( 'elementor/loaded' ) > 0,

			// Feature toggle states.
			'IS_404_ENABLED'          => function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( '404' ),
			'IS_RICH_SNIPPETS_ENABLED' => function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'rich-snippets' ),
			'IS_BOT_ENABLED'          => function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'bot' ),

			// URLs.
			'ADMIN_URL'               => admin_url(),
			'AJAX_URL'                => admin_url( 'admin-ajax.php' ),
			'SCHEMAS_URL'             => admin_url( 'admin.php?page=seopress-schemas' ),
			'REDIRECTS_URL'           => admin_url( 'admin.php?page=seopress-redirections' ),
			'SITE_URL'                => get_home_url(),
			'LLMS_URL'                => function_exists( 'seopress_llms_get_txt_url' ) ? seopress_llms_get_txt_url() : get_home_url() . '/llms.txt',
			'WIDGETS_URL'             => admin_url( 'widgets.php' ),

			// WooCommerce country list (ISO 3166-1 alpha-2 code => localized name)
			// for the return policy applicableCountry picker.
			'WC_COUNTRIES'            => ( function_exists( 'WC' ) && WC()->countries ) ? array_map( 'html_entity_decode', WC()->countries->get_countries() ) : array(),

			// Nonces.
			'NONCES'                  => array(
				'htaccess'       => wp_create_nonce( 'seopress_save_htaccess_nonce' ),
				'pagespeed'      => wp_create_nonce( 'seopress_pagespeed_nonce' ),
				'pagespeed_run'  => wp_create_nonce( 'seopress_request_page_speed_nonce' ),
				'pagespeed_clear' => wp_create_nonce( 'seopress_clear_page_speed_cache_nonce' ),
				'gsc'            => wp_create_nonce( 'seopress_gsc_nonce' ),
				'gsc_bot'        => wp_create_nonce( 'seopress_nonce_search_console' ),
				'robots'         => wp_create_nonce( 'seopress_robots_nonce' ),
				'ai_check'       => wp_create_nonce( 'seopress_ai_check_license_key_nonce' ),
				'ai_credits'     => wp_create_nonce( 'seopress_ai_credits_nonce' ),
				'ga_oauth'       => wp_create_nonce( 'seopress_ga_oauth_nonce' ),
			),

			// Data for tabs.
			'POST_TYPES'              => $pt_list,
			'TAXONOMIES'              => $tax_list,
			'LOCAL_BUSINESS_TYPES'    => $local_business_types,
			'OPENING_HOURS_DAYS'     => class_exists( 'SEOPress\Helpers\OpeningHoursHelper' )
				? \SEOPress\Helpers\OpeningHoursHelper::getDays()
				: array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
			'DOCS_LINKS'              => $docs_links,

			// Pre-loaded content.
			'ROBOTS_FILE_CONTENT'     => isset( $pro_options['seopress_robots_file'] ) ? $pro_options['seopress_robots_file'] : '',
			'HTACCESS_FILE_CONTENT'   => $htaccess_content,
			'HTACCESS_STATUS'         => $htaccess_status,

			// PageSpeed Insights cached results.
			'PAGESPEED_MOBILE'        => get_transient( 'seopress_results_page_speed' ) ? json_decode( get_transient( 'seopress_results_page_speed' ), true ) : null,
			'PAGESPEED_DESKTOP'       => get_transient( 'seopress_results_page_speed_desktop' ) ? json_decode( get_transient( 'seopress_results_page_speed_desktop' ), true ) : null,

			// Google Search Console.
			'GSC_BATCH_SIZE'          => apply_filters( 'seopress_search_console_batch_process', 20 ),

			// AI Logs.
			'AI_LOGS'                 => get_transient( 'seopress_pro_ai_logs' ) ? json_decode( get_transient( 'seopress_pro_ai_logs' ), true ) : null,
			// AI API key constants defined in wp-config.php. A constant defined
			// but left empty is ignored by Usage::getLicenseKey(), so it must
			// not lock the field here either.
			'AI_CONSTANTS'            => call_user_func( function () {
				$constants = array();

				foreach ( array_keys( seopress_ai_get_providers() ) as $key ) {
					$constants[ $key ] = seopress_ai_provider_key_is_constant( $key );
				}

				return $constants;
			} ),
			'AI_KEY_HINTS'            => call_user_func( function () {
				$hints   = array();
				$options = get_option( 'seopress_pro_option_name' );

				foreach ( array_keys( seopress_ai_get_providers() ) as $key ) {
					$raw = '';
					if ( seopress_ai_provider_key_is_constant( $key ) ) {
						$raw = constant( seopress_ai_get_provider_constant( $key ) );
					} elseif ( ! empty( $options[ 'seopress_ai_' . $key . '_api_key' ] ) ) {
						$raw = $options[ 'seopress_ai_' . $key . '_api_key' ];
					}

					if ( ! empty( $raw ) && strlen( $raw ) > 4 ) {
						$hints[ $key ] = '••••...' . substr( $raw, -4 );
					} elseif ( ! empty( $raw ) ) {
						$hints[ $key ] = str_repeat( '•', strlen( $raw ) );
					} else {
						$hints[ $key ] = '';
					}
				}

				return $hints;
			} ),
			'AI_CREDITS_PROXY_URL'    => defined( 'SEOPRESS_AI_PROXY_URL' ) ? SEOPRESS_AI_PROXY_URL : 'https://api.seopress.org',
		) );
	}

	// Localize data on the Network Admin page.
	if ( 'seopress-network-option' === $matched_slug ) {
		// Read .htaccess content and determine status.
		$htaccess_content = '';
		$htaccess_status  = 'ok';
		$htaccess_path    = ABSPATH . '.htaccess';

		if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT === true )
			|| ( defined( 'SEOPRESS_BLOCK_HTACCESS' ) && SEOPRESS_BLOCK_HTACCESS === true ) ) {
			$htaccess_status = 'blocked';
		} elseif ( isset( $_SERVER['SERVER_SOFTWARE'] ) && 0 === stripos( $_SERVER['SERVER_SOFTWARE'], 'nginx' ) ) {
			$htaccess_status = 'nginx';
		} elseif ( ! file_exists( $htaccess_path ) || ! is_writable( $htaccess_path ) ) {
			$htaccess_status = 'not_writable';
		} else {
			$htaccess_content = file_get_contents( $htaccess_path ); // phpcs:ignore
		}

		$docs_links = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : array();

		wp_localize_script( 'seopress-pro-admin-settings', 'SEOPRESS_PRO_DATA', array(
			'IS_MULTISITE'            => is_multisite(),
			'IS_NETWORK_ADMIN'        => is_network_admin(),
			'IS_SUBDOMAIN_INSTALL'    => defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL,
			'IS_SUBDIRECTORY_INSTALL' => defined( 'SUBDOMAIN_INSTALL' ) && ! SUBDOMAIN_INSTALL,

			'ADMIN_URL'               => admin_url(),
			'AJAX_URL'                => admin_url( 'admin-ajax.php' ),
			'SITE_URL'                => get_home_url(),
			'LLMS_URL'                => function_exists( 'seopress_llms_get_txt_url' ) ? seopress_llms_get_txt_url() : get_home_url() . '/llms.txt',

			'NONCES'                  => array(
				'htaccess' => wp_create_nonce( 'seopress_save_htaccess_nonce' ),
			),

			'DOCS_LINKS'              => $docs_links,
			'HTACCESS_FILE_CONTENT'   => $htaccess_content,
			'HTACCESS_STATUS'         => $htaccess_status,
			'ROBOTS_FILE_CONTENT'     => '',

			'PAGE_TYPE'               => 'network-admin',
		) );
	}
}
add_action( 'admin_enqueue_scripts', 'seopress_pro_enqueue_react_settings', 20, 1 );

/**
 * Enqueue the PRO Site overview tabs bundle on every SEOPress admin page.
 *
 * The bundle registers four overview tab renderers (PageSpeed, GA,
 * Matomo, Search Console) via `window.seopressDashboardExtensions.
 * registerOverviewTab()`. It must run BEFORE the Dashboard React
 * component renders.
 *
 * Free 9.9.1+ hosts the Dashboard inside the unified Settings React
 * shell, so a user landing on a Settings page (e.g. Titles) can
 * SPA-navigate to the Dashboard section without a reload. To keep PRO
 * Site overview tabs available in that flow, this bundle must be
 * enqueued on every SEOPress admin page (not only on seopress-option).
 *
 * The localized payload (`SEOPRESS_PRO_DASHBOARD_DATA`) ships
 * everything the tab renderers need (PageSpeed scores + CWV, GA /
 * Matomo configuration flags, Search Console top queries + popular
 * posts) so the React tabs render without a single client-side REST
 * round-trip.
 *
 * @param string $hook The current admin page hook.
 */
function seopress_pro_enqueue_react_dashboard( $hook ) {
	// The free plugin can be switched off, or have its directory swapped
	// mid-update, while Pro stays loaded for the rest of this request. Every
	// value below is read through the free plugin's service container.
	if ( ! seopress_pro_is_free_runtime_available() ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 0 !== strpos( $page, 'seopress-' ) && 'seopress-option' !== $page ) {
		return;
	}

	if ( ! current_user_can( seopress_capability( 'manage_options', 'dashboard' ) ) ) {
		return;
	}

	$asset_file = plugin_dir_path( __FILE__ ) . 'public/admin/dashboard/index.asset.php';
	$asset      = file_exists( $asset_file ) ? require $asset_file : array(
		'dependencies' => array( 'wp-element', 'wp-i18n', 'wp-components' ),
		'version'      => SEOPRESS_PRO_VERSION,
	);

	// Depend on the free unified Settings shell only when it is actually
	// registered on this screen. The dashboard bundle is enqueued on every
	// SEOPress admin page, but `seopress-admin-settings` is not registered
	// everywhere (e.g. the License screen), and listing an unregistered
	// dependency triggers a _doing_it_wrong notice in WP 6.9.1+.
	$dashboard_deps = $asset['dependencies'];
	if ( wp_script_is( 'seopress-admin-settings', 'registered' ) ) {
		$dashboard_deps[] = 'seopress-admin-settings';
	}

	wp_enqueue_script(
		'seopress-pro-dashboard',
		plugins_url( 'public/admin/dashboard/index.js', __FILE__ ),
		$dashboard_deps,
		$asset['version'],
		true
	);

	// No `merge_chunks()` here: the dashboard bundle is a single `index.js`
	// with no lazy chunk, so the handle's own JSON already carries everything.
	wp_set_script_translations( 'seopress-pro-dashboard', 'wp-seopress-pro', WP_LANG_DIR . '/plugins' );

	$style_file = plugin_dir_path( __FILE__ ) . 'public/admin/dashboard/index.css';
	if ( file_exists( $style_file ) ) {
		$style_version = SEOPRESS_PRO_VERSION;
		if ( '10.2' === $style_version || empty( $style_version ) ) {
			$style_version = substr( md5_file( $style_file ), 0, 12 );
		}
		wp_enqueue_style(
			'seopress-pro-dashboard',
			plugins_url( 'public/admin/dashboard/index.css', __FILE__ ),
			array(),
			$style_version
		);
	}

	wp_localize_script(
		'seopress-pro-dashboard',
		'SEOPRESS_PRO_DASHBOARD_DATA',
		seopress_pro_get_dashboard_overview_data()
	);
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'admin_enqueue_scripts', 'seopress_pro_enqueue_react_dashboard', 20, 1 );
}

/**
 * Build the localized payload consumed by the PRO Site overview tabs.
 *
 * Reads the same transients / services the legacy `insights.php` block
 * used: PageSpeed (mobile + desktop) + CWV, GA / Matomo configuration,
 * Search Console top queries + popular posts.
 *
 * @return array
 */
function seopress_pro_get_dashboard_overview_data() {
	$ga_enabled     = '1' === seopress_get_toggle_option( 'google-analytics' );
	$ga_ws_hidden   = method_exists( seopress_pro_get_service( 'GoogleAnalyticsWidgetsOptionPro' ), 'getGA4DashboardWidget' )
		&& '1' === seopress_pro_get_service( 'GoogleAnalyticsWidgetsOptionPro' )->getGA4DashboardWidget();
	$ga_id          = method_exists( seopress_get_service( 'GoogleAnalyticsOption' ), 'getGA4PropertId' )
		? (string) seopress_get_service( 'GoogleAnalyticsOption' )->getGA4PropertId()
		: '';

	$ga_stats     = array();
	$ga_series    = array();
	$ga_has_data  = false;
	$ga_has_error = false;
	$ga_cache     = get_transient( 'seopress_results_google_analytics' );
	if ( is_string( $ga_cache ) ) {
		$ga_cache = json_decode( $ga_cache, true );
	}
	if ( is_array( $ga_cache ) ) {
		if ( isset( $ga_cache['error'] ) && ! empty( $ga_cache['error'] ) ) {
			$ga_has_error = true;
		} elseif ( isset( $ga_cache['sessions'] ) || isset( $ga_cache['users'] ) || isset( $ga_cache['pageviews'] ) ) {
			$ga_stats = array(
				'sessions'           => isset( $ga_cache['sessions'] ) ? (int) $ga_cache['sessions'] : 0,
				'users'              => isset( $ga_cache['users'] ) ? (int) $ga_cache['users'] : 0,
				'pageviews'          => isset( $ga_cache['pageviews'] ) ? (int) $ga_cache['pageviews'] : 0,
				'avgSessionDuration' => isset( $ga_cache['avgSessionDuration'] ) ? (string) $ga_cache['avgSessionDuration'] : '',
			);
			if ( isset( $ga_cache['periods'] ) && is_array( $ga_cache['periods'] ) ) {
				foreach ( $ga_cache['periods'] as $period_key => $period_payload ) {
					if ( ! is_array( $period_payload ) ) {
						continue;
					}
					$metrics_in = isset( $period_payload['metrics'] ) && is_array( $period_payload['metrics'] ) ? $period_payload['metrics'] : array();
					$metrics    = array();
					foreach ( $metrics_in as $metric_key => $series ) {
						if ( is_array( $series ) ) {
							$metrics[ $metric_key ] = array_map( 'floatval', $series );
						}
					}
					$totals_in                         = isset( $period_payload['totals'] ) && is_array( $period_payload['totals'] ) ? $period_payload['totals'] : array();
					$totals                            = array(
						'sessions'           => isset( $totals_in['sessions'] ) ? (int) $totals_in['sessions'] : 0,
						'users'              => isset( $totals_in['users'] ) ? (int) $totals_in['users'] : 0,
						'pageviews'          => isset( $totals_in['pageviews'] ) ? (int) $totals_in['pageviews'] : 0,
						'avgSessionDuration' => isset( $totals_in['avgSessionDuration'] ) ? (string) $totals_in['avgSessionDuration'] : '',
					);
					$ga_series[ (string) $period_key ] = array(
						'labels'  => isset( $period_payload['labels'] ) && is_array( $period_payload['labels'] ) ? array_map( 'strval', $period_payload['labels'] ) : array(),
						'metrics' => $metrics,
						'totals'  => $totals,
					);
				}
			}
			$ga_has_data = true;
		}
	}

	// "Configured" must mean genuinely connected, not just a property ID typed
	// in: requiring the OAuth token too means the dashboard shows the Connect
	// call-to-action instead of misleading all-zero cards when auth is missing.
	$ga_has_token = method_exists( seopress_pro_get_service( 'GoogleAnalyticsOptionPro' ), 'getAccessToken' )
		&& ! empty( seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getAccessToken() );

	$ga             = array(
		'enabled'      => $ga_enabled && ! $ga_ws_hidden,
		'configured'   => '' !== $ga_id && $ga_has_token,
		'hasData'      => $ga_has_data,
		'hasError'     => $ga_has_error,
		'stats'        => $ga_stats,
		'series'       => $ga_series,
		'profileLabel' => $ga_id,
		'settingsUrl'  => admin_url( 'admin.php?page=seopress-google-analytics' ),
		// "View full report" should open the GA4 reports for this property on
		// the Google Analytics site, not the SEOPress settings page. Fall back
		// to the settings page only when no property ID is available.
		'viewUrl'      => '' !== $ga_id
			? 'https://analytics.google.com/analytics/web/#/p' . rawurlencode( $ga_id ) . '/reports/intelligenthome'
			: admin_url( 'admin.php?page=seopress-google-analytics#tab=tab_seopress_google_analytics_reports' ),
		'sync'         => array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => 'seopress_request_google_analytics',
			'nonce'  => wp_create_nonce( 'seopress_request_google_analytics_nonce' ),
		),
	);

	$matomo_id      = method_exists( seopress_get_service( 'GoogleAnalyticsOption' ), 'getMatomoId' )
		? (string) seopress_get_service( 'GoogleAnalyticsOption' )->getMatomoId()
		: '';
	$matomo_site_id = method_exists( seopress_get_service( 'GoogleAnalyticsOption' ), 'getMatomoSiteId' )
		? (string) seopress_get_service( 'GoogleAnalyticsOption' )->getMatomoSiteId()
		: '';
	$matomo_url     = '' !== $matomo_id ? 'https://' . $matomo_id : '';
	$matomo_hidden  = method_exists( seopress_pro_get_service( 'GoogleAnalyticsWidgetsOptionPro' ), 'getMatomoDashboardWidget' )
		&& '1' === seopress_pro_get_service( 'GoogleAnalyticsWidgetsOptionPro' )->getMatomoDashboardWidget();

	$matomo_stats    = array();
	$matomo_chart    = null;
	$matomo_series   = array();
	$matomo_has_data = false;
	$matomo_cache    = get_transient( 'seopress_results_matomo' );
	if ( is_string( $matomo_cache ) ) {
		$matomo_cache = json_decode( $matomo_cache, true );
	}
	if ( is_array( $matomo_cache ) && isset( $matomo_cache['all'] ) && is_array( $matomo_cache['all'] ) ) {
		$all          = $matomo_cache['all'];
		$matomo_stats = array(
			'visits'          => isset( $all['nb_visits'] ) ? (int) $all['nb_visits'] : 0,
			'uniqueVisitors'  => isset( $all['nb_uniq_visitors'] ) ? (int) $all['nb_uniq_visitors'] : 0,
			'actionsPerVisit' => isset( $all['nb_actions_per_visit'] ) ? (float) $all['nb_actions_per_visit'] : 0,
			'avgTimeOnSite'   => isset( $all['avg_time_on_site'] ) ? (int) $all['avg_time_on_site'] : 0,
			'bounceRate'      => isset( $all['bounce_rate'] ) ? (float) $all['bounce_rate'] : 0,
		);
		if ( isset( $matomo_cache['sessions_graph_labels'], $matomo_cache['sessions_graph_data'] )
			&& is_array( $matomo_cache['sessions_graph_labels'] )
			&& is_array( $matomo_cache['sessions_graph_data'] )
		) {
			$matomo_chart = array(
				'labels' => array_map( 'strval', $matomo_cache['sessions_graph_labels'] ),
				'data'   => array_map( 'floatval', $matomo_cache['sessions_graph_data'] ),
				'title'  => isset( $matomo_cache['sessions_graph_title'] ) ? (string) $matomo_cache['sessions_graph_title'] : '',
			);
		}
		if ( isset( $matomo_cache['periods'] ) && is_array( $matomo_cache['periods'] ) ) {
			foreach ( $matomo_cache['periods'] as $period_key => $period_payload ) {
				if ( ! is_array( $period_payload ) ) {
					continue;
				}
				$metrics_in = isset( $period_payload['metrics'] ) && is_array( $period_payload['metrics'] ) ? $period_payload['metrics'] : array();
				$metrics    = array();
				foreach ( $metrics_in as $metric_key => $series ) {
					if ( is_array( $series ) ) {
						$metrics[ $metric_key ] = array_map( 'floatval', $series );
					}
				}
				$totals_in = isset( $period_payload['totals'] ) && is_array( $period_payload['totals'] ) ? $period_payload['totals'] : array();
				$totals    = array(
					'visits'          => isset( $totals_in['nb_visits'] ) ? (int) $totals_in['nb_visits'] : 0,
					'uniqueVisitors'  => isset( $totals_in['nb_uniq_visitors'] ) ? (int) $totals_in['nb_uniq_visitors'] : 0,
					'actionsPerVisit' => isset( $totals_in['nb_actions_per_visit'] ) ? (float) $totals_in['nb_actions_per_visit'] : 0,
					'avgTimeOnSite'   => isset( $totals_in['avg_time_on_site'] ) ? (int) $totals_in['avg_time_on_site'] : 0,
					'bounceRate'      => isset( $totals_in['bounce_rate'] ) ? (float) $totals_in['bounce_rate'] : 0,
				);
				$matomo_series[ (string) $period_key ] = array(
					'labels'  => isset( $period_payload['labels'] ) && is_array( $period_payload['labels'] ) ? array_map( 'strval', $period_payload['labels'] ) : array(),
					'metrics' => $metrics,
					'totals'  => $totals,
				);
			}
		}
		$matomo_has_data = true;
	}

	$matomo         = array(
		'enabled'     => $ga_enabled && ! $matomo_hidden && '' !== $matomo_id && '' !== $matomo_site_id,
		'configured'  => '' !== $matomo_id && '' !== $matomo_site_id,
		'hasData'     => $matomo_has_data,
		'stats'       => $matomo_stats,
		'chart'       => $matomo_chart,
		'series'      => $matomo_series,
		'siteLabel'   => $matomo_url,
		'settingsUrl' => admin_url( 'admin.php?page=seopress-google-analytics#tab=tab_seopress_matomo' ),
		'viewUrl'     => $matomo_url,
		'sync'        => array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => 'seopress_request_matomo_analytics',
			'nonce'  => wp_create_nonce( 'seopress_request_matomo_analytics_nonce' ),
		),
	);

	$ps_mobile      = json_decode( get_transient( 'seopress_results_page_speed' ), true );
	$ps_desktop     = json_decode( get_transient( 'seopress_results_page_speed_desktop' ), true );
	$mobile_score   = null;
	$desktop_score  = null;
	$cwv            = null;
	$has_data       = false;
	if ( is_array( $ps_mobile ) && isset( $ps_mobile['lighthouseResult']['categories']['performance']['score'] ) ) {
		$mobile_score = (int) round( $ps_mobile['lighthouseResult']['categories']['performance']['score'] * 100 );
		$cwv          = seopress_pro_get_cwv_score( $ps_mobile );
		$has_data     = true;
	}
	if ( is_array( $ps_desktop ) && isset( $ps_desktop['lighthouseResult']['categories']['performance']['score'] ) ) {
		$desktop_score = (int) round( $ps_desktop['lighthouseResult']['categories']['performance']['score'] * 100 );
		$has_data      = true;
	}
	$pagespeed      = array(
		'enabled'       => true,
		'hasData'       => $has_data,
		'mobileScore'   => $mobile_score,
		'desktopScore'  => $desktop_score,
		'cwv'           => $cwv,
		'viewUrl'       => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_page_speed' ),
	);

	$gsc_options    = get_option( 'seopress_instant_indexing_option_name' );
	$gsc_key        = isset( $gsc_options['seopress_instant_indexing_google_api_key'] ) ? $gsc_options['seopress_instant_indexing_google_api_key'] : '';
	$gsc_enabled    = '1' === seopress_get_toggle_option( 'inspect-url' );
	$gsc_queries    = array();
	$gsc_posts      = array();
	if ( $gsc_enabled && '' !== $gsc_key && method_exists( seopress_pro_get_service( 'SearchConsole' ), 'getKeywords' ) ) {
		$keywords = seopress_pro_get_service( 'SearchConsole' )->getKeywords();
		if ( ! empty( $keywords['data'] ) ) {
			foreach ( $keywords['data'] as $entry ) {
				$gsc_queries[] = array(
					'keyword'  => isset( $entry['keyword'] ) ? (string) $entry['keyword'] : '',
					'clicks'   => isset( $entry['clicks'] ) ? (int) $entry['clicks'] : 0,
					'position' => isset( $entry['position'] ) ? round( $entry['position'], 1 ) : 0,
				);
			}
		}

		global $wpdb;
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s ORDER BY CAST(meta_value AS UNSIGNED) DESC LIMIT %d",
				'_seopress_search_console_analysis_clicks',
				5
			)
		);
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$post_id = (int) $row->post_id;
				$gsc_posts[] = array(
					'id'     => $post_id,
					'title'  => (string) get_the_title( $post_id ),
					'url'    => (string) get_permalink( $post_id ),
					'clicks' => (int) $row->meta_value,
				);
			}
		}
	}
	$gsc            = array(
		'enabled'       => $gsc_enabled,
		'authenticated' => '' !== $gsc_key,
		'queries'       => $gsc_queries,
		'posts'         => $gsc_posts,
		'authUrl'       => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_inspect_url' ),
		'viewUrl'       => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_inspect_url' ),
	);

	return array(
		'GA'        => $ga,
		'MATOMO'    => $matomo,
		'PAGESPEED' => $pagespeed,
		'GSC'       => $gsc,
	);
}

/**
 * Register PRO REST endpoints for the React settings SettingsContext.
 *
 * Maps the page types added by the free plugin's ModuleSettings to the
 * matching PRO REST routes so that GET/POST settings traffic works.
 *
 * @param array $endpoints Existing endpoint map (page type => REST path).
 * @return array
 */
function seopress_pro_register_settings_api_endpoints( $endpoints ) {
	$endpoints['network-admin'] = '/seopress/v1/options/pro-mu-settings';
	return $endpoints;
}
add_filter( 'seopress_settings_api_endpoints', 'seopress_pro_register_settings_api_endpoints' );

/**
 * Bounce the React DataViews admin pages to their classic CPT screens on
 * WordPress versions too old to run `@wordpress/dataviews@14`.
 *
 * Hooked on `admin_init` so the redirect happens BEFORE `admin-header.php`
 * emits any output — `wp_safe_redirect()` can't run from the render
 * callback (headers already sent by then). Pages without a CPT fallback
 * (broken links, site audit) are not in the map and instead fall through
 * to their notice render callback.
 *
 * @since 9.8.0
 *
 * @return void
 */
function seopress_pro_dataviews_unsupported_redirect() {
	if ( \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no state mutation.
	if ( empty( $_GET['page'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = sanitize_key( wp_unslash( $_GET['page'] ) );

	$fallbacks = array(
		'seopress-redirections' => admin_url( 'edit.php?post_type=seopress_404' ),
		'seopress-schemas'      => admin_url( 'edit.php?post_type=seopress_schemas' ),
	);

	if ( ! isset( $fallbacks[ $page ] ) ) {
		return;
	}

	wp_safe_redirect( $fallbacks[ $page ] );
	exit;
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'admin_init', 'seopress_pro_dataviews_unsupported_redirect' );
}

/**
 * Render the Redirections DataViews admin page.
 *
 * Older WordPress installs are bounced to the classic `seopress_404` CPT
 * screen by `seopress_pro_dataviews_unsupported_redirect()` on `admin_init`,
 * so the notice branch below is purely defensive.
 *
 * @return void
 */
function seopress_redirections_page_render() {
	if ( ! \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
		seopress_pro_render_dataviews_unsupported_notice( __( 'Redirections', 'wp-seopress-pro' ) );
		return;
	}
	// The empty `.wp-header-end` gives wp-admin/js/common.js somewhere to put
	// admin notices. Without it, it falls back to the first h1 or h2 inside
	// `.wrap`, which on a bare React root is whatever heading the app renders
	// first: notices landed inside the sidebar card.
	echo '<div class="wrap"><hr class="wp-header-end"><div id="seopress-redirections-root"></div></div>';
}

/**
 * Enqueue Redirections DataViews assets.
 *
 * @param string $hook The current admin page hook.
 *
 * @return void
 */
function seopress_pro_enqueue_redirections_assets( $hook ) {
	// The free plugin can be switched off, or have its directory swapped
	// mid-update, while Pro stays loaded for the rest of this request. Every
	// value below is read through the free plugin's service container.
	if ( ! seopress_pro_is_free_runtime_available() ) {
		return;
	}

	// Skip the DataViews bundle on WP versions older than the minimum required
	// for `@wordpress/dataviews@14`. The submenu render callback redirects to
	// the classic `seopress_404` CPT screen instead.
	if ( ! \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
		return;
	}

	// Surface the global "default Query Parameters mode" option to the React
	// redirections editor so newly created redirects pre-fill with the user's
	// preferred default. Falls back to 'exact_match' if the service is missing.
	$default_param = 'exact_match';
	if ( function_exists( 'seopress_pro_get_service' ) ) {
		$default_param = seopress_pro_get_service( 'OptionPro' )->get404DefaultParam();
	}

	// Inline the persisted "categories panel visible?" preference so the React
	// listing renders in its final layout on the first paint. Without it the
	// panel state is only known after the async view-preferences fetch, which
	// would show the panel, then hide it, shifting the table sideways. Passed
	// as '1' / '0' strings because `wp_localize_script` stringifies booleans
	// ( true → '1', false → '' ), which would make the "hidden" case ambiguous.
	$show_categories_sidebar = true;
	$view_prefs              = get_user_meta( get_current_user_id(), 'seopress_redirections_view', true );
	if ( is_array( $view_prefs ) && isset( $view_prefs['showCategoriesSidebar'] ) ) {
		$show_categories_sidebar = (bool) $view_prefs['showCategoriesSidebar'];
	}

	\SEOPressPro\Helpers\DataViewsAssets::enqueue(
		array(
			'name'            => 'redirections',
			'handle'          => 'seopress-pro-redirections',
			'page_hook_match' => 'seopress-redirections',
			'hook'            => $hook,
			'plugin_file'     => __FILE__,
			'plugin_version'  => SEOPRESS_PRO_VERSION,
			'localize_object' => 'SEOPRESS_REDIRECTIONS_DATA',
			'localize_data'   => array(
				'DEFAULT_PARAM'           => $default_param,
				'SITE_URL'                => get_home_url(),
				'SHOW_CATEGORIES_SIDEBAR' => $show_categories_sidebar ? '1' : '0',
			),
			// Inline the first page so the React app can render the listing
			// without waiting for a network round-trip. Mirrors the defaults
			// hard-coded in `RedirectionsDataView.js#DEFAULT_VIEW`.
			'preload'         => array(
				array(
					'global' => 'SEOPRESS_REDIRECTIONS_PRELOADED',
					'route'  => '/seopress/v1/redirections',
					'params' => array(
						'page'     => 1,
						'per_page' => 20,
						'orderby'  => 'date',
						'order'    => 'desc',
						'view'     => 'redirects',
					),
				),
			),
		)
	);
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'admin_enqueue_scripts', 'seopress_pro_enqueue_redirections_assets', 20, 1 );
}

/**
 * Terms for the Term Taxonomy filter in the schema rules builder.
 *
 * Served lazily over `/seopress/v1/schemas/editor-data`, not inlined into the
 * page. The picker is a searchable combobox, so this list only has to be
 * complete, not short: the user types instead of scrolling.
 *
 * Two details are load-bearing.
 *
 * The payload is a *list*, not a map keyed by term ID. `Object.entries()`
 * enumerates integer-like keys in ascending numeric order, so a map hands the
 * interface term IDs in creation order and throws away whatever ordering was
 * built here.
 *
 * And the ordering inside a taxonomy is the one `get_terms()` returned, which
 * is the database collation's. Re-sorting it in PHP with `strcasecmp()` would
 * compare bytes and push every accented name past `Z` — `Écologie` after
 * `Zèbre` — which is the same "my term is not in the list" symptom this whole
 * function exists to remove. Only the taxonomy groups are ordered here.
 *
 * @since 10.2.0
 *
 * @return array {
 *     @type array[] $terms     List of { value, label, taxonomy }.
 *     @type bool    $truncated Whether any taxonomy hit the limit.
 *     @type int     $limit     The limit in force.
 * }
 */
function seopress_pro_schema_rule_terms() {
	/**
	 * How many terms per taxonomy reach the picker.
	 *
	 * The whole list is sent in one response and filtered client-side, so it
	 * cannot be unbounded. Raise it on a site with a very large taxonomy.
	 *
	 * @since 10.2.0
	 *
	 * @param int $limit Terms per taxonomy.
	 */
	$limit = (int) apply_filters( 'seopress_pro_schema_rules_terms_limit', 2000 );
	$limit = max( 1, $limit );

	$grouped     = array();
	$truncated   = false;
	$public_taxs = get_taxonomies( array( 'public' => true ), 'objects' );

	foreach ( $public_taxs as $tax ) {
		$found = get_terms(
			array(
				'taxonomy'   => $tax->name,
				'hide_empty' => false,
				// One over the limit, so hitting it can be told from landing
				// exactly on it. The extra entry is dropped below.
				'number'     => $limit + 1,
				'orderby'    => 'name',
				'order'      => 'ASC',
				// Names are all this needs; hydrating full term objects for
				// thousands of rows is pure overhead.
				'fields'     => 'id=>name',
			)
		);

		if ( is_wp_error( $found ) || empty( $found ) ) {
			continue;
		}

		if ( count( $found ) > $limit ) {
			$truncated = true;
			$found     = array_slice( $found, 0, $limit, true );
		}

		$tax_label = html_entity_decode( $tax->label, ENT_QUOTES | ENT_SUBSTITUTE );

		foreach ( $found as $term_id => $term_name ) {
			$grouped[ $tax_label ][] = array(
				'value'    => (string) $term_id,
				'label'    => html_entity_decode( $term_name, ENT_QUOTES | ENT_SUBSTITUTE ),
				'taxonomy' => $tax_label,
			);
		}
	}

	// Taxonomy labels are short and site-authored; ordering them naturally is
	// enough, and it leaves each group's own ordering untouched.
	uksort( $grouped, 'strnatcasecmp' );

	return array(
		'terms'     => $grouped ? array_merge( ...array_values( $grouped ) ) : array(),
		'truncated' => $truncated,
		'limit'     => $limit,
	);
}

/**
 * Render the Schemas DataViews admin page.
 *
 * Older WordPress installs are bounced to the classic `seopress_schemas` CPT
 * screen by `seopress_pro_dataviews_unsupported_redirect()` on `admin_init`,
 * so the notice branch below is purely defensive.
 *
 * @return void
 */
function seopress_schemas_page_render() {
	if ( ! \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
		seopress_pro_render_dataviews_unsupported_notice( __( 'Schemas', 'wp-seopress-pro' ) );
		return;
	}
	// The empty `.wp-header-end` gives wp-admin/js/common.js somewhere to put
	// admin notices. Without it, it falls back to the first h1 or h2 inside
	// `.wrap`, which on a bare React root is whatever heading the app renders
	// first: notices landed inside the sidebar card.
	echo '<div class="wrap"><hr class="wp-header-end"><div id="seopress-schemas-root"></div></div>';
}

/**
 * Enqueue Schemas DataViews assets.
 *
 * @param string $hook The current admin page hook.
 *
 * @return void
 */
function seopress_pro_enqueue_schemas_assets( $hook ) {
	// The free plugin can be switched off, or have its directory swapped
	// mid-update, while Pro stays loaded for the rest of this request. Every
	// value below is read through the free plugin's service container.
	if ( ! seopress_pro_is_free_runtime_available() ) {
		return;
	}

	// Bail out early before doing any of the heavy data collection below if we
	// are not on the Schemas screen.
	if ( false === strpos( $hook, 'seopress-schemas' ) ) {
		return;
	}

	// Skip the DataViews bundle on WP versions older than the minimum required
	// for `@wordpress/dataviews@14`. The submenu render callback redirects to
	// the classic `seopress_schemas` CPT screen instead.
	if ( ! \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
		return;
	}

	// Build post type labels map.
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	$pt_labels  = array();
	foreach ( $post_types as $pt ) {
		$pt_labels[ $pt->name ] = $pt->label;
	}

	// Terms for the Term Taxonomy picker are NOT built here. They are served
	// lazily over `/seopress/v1/schemas/editor-data`, so a site with a large
	// taxonomy does not pay for them on the listing screen, where the picker
	// does not exist.

	// Taxonomy slugs (lightweight — no DB query).
	$taxonomy_slugs = array_keys( get_taxonomies( array( 'public' => true ) ) );

	// Contextual flags for schema editor notices.
	$has_publisher_logo = false;
	if ( function_exists( 'seopress_pro_get_service' ) ) {
		$option_pro = seopress_pro_get_service( 'OptionPro' );
		if ( $option_pro && method_exists( $option_pro, 'getArticlesPublisherLogo' ) ) {
			$has_publisher_logo = '' !== $option_pro->getArticlesPublisherLogo();
		}
	}

	$wc_default_schema_enabled = true;
	if ( function_exists( 'seopress_pro_get_service' ) ) {
		$option_pro = seopress_pro_get_service( 'OptionPro' );
		if ( $option_pro && method_exists( $option_pro, 'getWCDisableSchemaOutput' ) ) {
			$wc_default_schema_enabled = '1' !== $option_pro->getWCDisableSchemaOutput();
		}
	}

	// Whether structured data is actually output on the front end. The
	// builder renders a client-side preview regardless, so surface a notice
	// when this master toggle is off to avoid the "valid preview, empty
	// front end" confusion.
	$rich_snippets_enabled = false;
	if ( function_exists( 'seopress_pro_get_service' ) ) {
		$option_pro = seopress_pro_get_service( 'OptionPro' );
		if ( $option_pro && method_exists( $option_pro, 'getRichSnippetEnable' ) ) {
			$rich_snippets_enabled = '1' === $option_pro->getRichSnippetEnable();
		}
	}

	// Schemas doc links.
	$docs_faq_acf     = '';
	$docs_google_urls = array();
	if ( function_exists( 'seopress_get_docs_links' ) ) {
		$docs_links       = seopress_get_docs_links();
		$docs_faq_acf     = isset( $docs_links['schemas']['faq_acf'] ) ? $docs_links['schemas']['faq_acf'] : '';
		$docs_google_urls = isset( $docs_links['schemas']['google'] ) ? $docs_links['schemas']['google'] : array();
	}

	$show_preview_pref = get_user_meta( get_current_user_id(), 'seopress_schemas_show_preview', true );
	if ( '' === $show_preview_pref ) {
		$show_preview_pref = '1';
	}

	\SEOPressPro\Helpers\DataViewsAssets::enqueue(
		array(
			'name'            => 'schemas',
			'handle'          => 'seopress-pro-schemas',
			'page_hook_match' => 'seopress-schemas',
			'hook'            => $hook,
			'plugin_file'     => __FILE__,
			'plugin_version'  => SEOPRESS_PRO_VERSION,
			'before_enqueue'  => 'wp_enqueue_media',
			'localize_object' => 'SEOPRESS_SCHEMAS_DATA',
			'localize_data'   => array(
				'editUrl'                => admin_url( 'post.php?action=edit&post=' ),
				'addNewUrl'              => admin_url( 'post-new.php?post_type=seopress_schemas' ),
				'adminUrl'               => admin_url(),
				'postTypes'              => $pt_labels,
				'taxonomySlugs'          => $taxonomy_slugs,
				'restUrl'                => rest_url( 'seopress/v1/schemas/editor-data' ),
				'restNonce'              => wp_create_nonce( 'wp_rest' ),
				'hasPublisherLogo'       => $has_publisher_logo,
				'isWooCommerceActive'    => is_plugin_active( 'woocommerce/woocommerce.php' ),
				'wcDefaultSchemaEnabled' => $wc_default_schema_enabled,
				'richSnippetsEnabled'    => $rich_snippets_enabled,
				'perPostOpeningHours'    => seopress_pro_has_per_post_opening_hours_support(),
				'settingsUrl'            => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_rich_snippets' ),
				'docsFaqAcf'             => $docs_faq_acf,
				'googleDocs'             => $docs_google_urls,
				'preferences'            => array(
					'showPreview' => '1' === (string) $show_preview_pref,
				),
			),
			// Inline the first page so the React app can render the listing
			// without waiting for a network round-trip. Mirrors the defaults
			// hard-coded in `SchemasDataView.js#DEFAULT_VIEW`.
			'preload'         => array(
				array(
					'global' => 'SEOPRESS_SCHEMAS_PRELOADED',
					'route'  => '/seopress/v1/schemas',
					'params' => array(
						'page'     => 1,
						'per_page' => 20,
						'orderby'  => 'title',
						'order'    => 'asc',
					),
				),
			),
		)
	);
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'admin_enqueue_scripts', 'seopress_pro_enqueue_schemas_assets', 20, 1 );
}

/**
 * Display SEOPress PRO notices.
 *
 * @return void
 */
function seopress_pro_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! is_plugin_active( 'wp-seopress/seopress.php' ) ) {
		?>
		<div class="notice error">
			<p>
				<?php echo wp_kses_post( __( 'Please enable <strong>SEOPress</strong> in order to use SEOPRESS PRO.', 'wp-seopress-pro' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=wp-seopress&TB_iframe=true&width=600&height=550' ) ); ?>" class="thickbox btn btnPrimary" target="_blank">
					<?php esc_html_e( 'Enable / Download now!', 'wp-seopress-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// The PRO license-activation reminder is rendered by the SEOPress React
	// settings shell (LicenseNotice, fed by SEOPRESS_SETTINGS_DATA) so it uses
	// the native WordPress Notice design and shows on every settings page.
}
add_action( 'admin_notices', 'seopress_pro_admin_notices' );

/**
 * Add plugin action links
 *
 * @param array  $links The plugin action links.
 * @param string $file The plugin file.
 * @return array The plugin action links.
 */
function seopress_pro_plugin_action_links( $links, $file ) {
	// Runs on the Plugins screen, which is exactly where the free plugin gets
	// deactivated or updated, and reads the white-label settings through the
	// free service container. Returning the links untouched keeps the row
	// rendering instead of taking the whole screen down.
	if ( ! seopress_pro_is_free_runtime_available() ) {
		return $links;
	}

	static $this_plugin;

	if ( ! $this_plugin ) {
		$this_plugin = plugin_basename( __FILE__ );
	}

	if ( $file === $this_plugin ) {
		$docs_links    = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : array();
		$support_url   = isset( $docs_links['support'] ) ? $docs_links['support'] : '';
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=seopress-pro-page' ) ) . '">' . esc_html__( 'Settings', 'wp-seopress-pro' ) . '</a>';
		$website_link  = '<a href="' . esc_url( $support_url ) . '" target="_blank">' . esc_html__( 'Support', 'wp-seopress-pro' ) . '</a>';

		// Combine license link logic to reduce function calls.
		$license_status = get_option( 'seopress_pro_license_status' );
		$license_link   = '';

		if ( ! is_multisite() ) {
			$license_link  = '<a href="' . esc_url( admin_url( 'admin.php?page=seopress-license' ) ) . '">';
			$license_link .= ( 'valid' !== $license_status ) ? '<span style="color:red;font-weight:bold">' . esc_html__( 'Activate your license', 'wp-seopress-pro' ) . '</span>' : esc_html__( 'License', 'wp-seopress-pro' );
			$license_link .= '</a>';
		}

		// Simplify condition checks.
		$is_white_label = is_plugin_active( 'wp-seopress-pro/seopress-pro.php' ) &&
						method_exists( seopress_get_service( 'ToggleOption' ), 'getToggleWhiteLabel' ) &&
						'1' === seopress_get_service( 'ToggleOption' )->getToggleWhiteLabel() &&
						'1' === seopress_pro_get_service( 'OptionPro' )->getWhiteLabelHelpLinks();

		if ( $is_white_label ) {
			array_unshift( $links, $settings_link );
		} else {
			array_unshift( $links, $settings_link, $website_link, $license_link );
		}
	}

	return $links;
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_filter( 'plugin_action_links', 'seopress_pro_plugin_action_links', 10, 2 );
}

/**
 * SEOPress PRO Updater
 *
 * @return void
 */
if ( ! class_exists( 'SEOPRESS_Updater' ) ) {
	// Load our custom updater.
	require_once plugin_dir_path( __FILE__ ) . 'inc/admin/updater/plugin-updater.php';
	require_once plugin_dir_path( __FILE__ ) . 'inc/admin/updater/plugin-upgrader.php';
	require_once plugin_dir_path( __FILE__ ) . 'inc/admin/updater/site-audit-table-migrations.php';
}

// Kept outside the updater guard above: this one only reads and writes an
// option, and must run whether or not the updater class is already loaded.
require_once plugin_dir_path( __FILE__ ) . 'inc/admin/updater/ai-assistant-default.php';

/**
 * SEOPress PRO Updater.
 *
 * @return void
 */
function SEOPRESS_Updater() { //phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	// To support auto-updates, this needs to run during the wp_version_check cron job for privileged users.
	// Also allow WP-CLI context so `wp plugin update` works without --user flag.
	$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;
	$doing_cli  = defined( 'WP_CLI' ) && WP_CLI;
	if ( ! current_user_can( 'manage_options' ) && ! $doing_cron && ! $doing_cli ) {
		return;
	}

	// Retrieve our license key from the DB.
	$license_key = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY ) ? SEOPRESS_LICENSE_KEY : trim( (string) get_option( 'seopress_pro_license_key' ) );

	// Setup the updater.
	$edd_updater = new SEOPRESS_Updater(
		STORE_URL_SEOPRESS,
		__FILE__,
		array(
			'version' => SEOPRESS_PRO_VERSION,
			'license' => $license_key,
			'item_id' => ITEM_ID_SEOPRESS,
			'author'  => SEOPRESS_PRO_AUTHOR,
			'url'     => esc_url( get_option( 'home' ) ),
			'beta'    => false,
		)
	);
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'init', 'SEOPRESS_Updater', 0 );
}

/**
 * Highlight Current menu when Editing Post Type.
 *
 * @param string $current_menu The current menu.
 * @return string
 */
function seopress_submenu_current( $current_menu ) {
	global $pagenow, $typenow;
	if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
		if ( 'seopress_404' === $typenow || 'seopress_bot' === $typenow || 'seopress_backlinks' === $typenow || 'seopress_schemas' === $typenow ) {
			global $plugin_page;
			$plugin_page = 'seopress-option';
		}
	}

	return $current_menu;
}
add_filter( 'parent_file', 'seopress_submenu_current' );

/**
 * Render an admin notice telling the user the page needs a newer WordPress
 * to function, used as a fallback on installs older than
 * `\SEOPressPro\Helpers\DataViewsAssets::MIN_WP_VERSION`.
 *
 * @since 9.8.0
 *
 * @param string $title H1 shown above the notice (page-specific label).
 *
 * @return void
 */
function seopress_pro_render_dataviews_unsupported_notice( $title ) {
	$min_version = \SEOPressPro\Helpers\DataViewsAssets::MIN_WP_VERSION;
	?>
	<style>
		/*
		 * The Free plugin renders `.seopress-notice` (the license / activation
		 * banner) with a legacy `position: relative; top: 85px; left: 25px;`
		 * offset designed to overlay the old jQuery admin header. The shared
		 * DataViews stylesheet resets that offset on React admin pages — but
		 * here we don't enqueue the DataViews bundle, so without an inline
		 * reset the banner shifts down and visually swallows the page title.
		 */
		.seopress-notice {
			top: 0;
			left: 0;
			width: auto;
			margin: 16px 20px;
		}
	</style>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
		<hr class="wp-header-end">
		<div class="notice notice-warning inline" style="margin-top:16px;">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: minimum WordPress version (e.g. 6.8). */
						__( 'This feature requires WordPress %s or higher. Please update WordPress to use it.', 'wp-seopress-pro' ),
						$min_version
					)
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">
					<?php esc_html_e( 'Update WordPress', 'wp-seopress-pro' ); ?>
				</a>
			</p>
		</div>
	</div>
	<?php
}

/**
 * Render the Broken Links DataViews admin page.
 *
 * Falls back to a "WordPress %s+ required" notice on WordPress versions too
 * old for `@wordpress/dataviews@14` — this page has no classic CPT
 * equivalent to redirect to.
 *
 * @since 9.8.0
 *
 * @return void
 */
function seopress_broken_links_page_render() {
	if ( ! \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
		seopress_pro_render_dataviews_unsupported_notice( __( 'Broken links', 'wp-seopress-pro' ) );
		return;
	}
	// The empty `.wp-header-end` gives wp-admin/js/common.js somewhere to put
	// admin notices. Without it, it falls back to the first h1 or h2 inside
	// `.wrap`, which on a bare React root is whatever heading the app renders
	// first: notices landed inside the sidebar card.
	echo '<div class="wrap"><hr class="wp-header-end"><div id="seopress-broken-links-root"></div></div>';
}

/**
 * Enqueue Broken Links DataViews assets.
 *
 * @since 9.8.0
 *
 * @param string $hook The current admin page hook.
 *
 * @return void
 */
function seopress_pro_enqueue_broken_links_assets( $hook ) {
	// Skip the DataViews bundle on WP versions older than the minimum required
	// for `@wordpress/dataviews@14`. The submenu render callback shows a
	// "WP X+ required" notice instead — there is no legacy CPT screen to fall
	// back to for this page.
	if ( ! \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
		return;
	}

	\SEOPressPro\Helpers\DataViewsAssets::enqueue(
		array(
			'name'            => 'broken-links',
			'handle'          => 'seopress-pro-broken-links',
			'page_hook_match' => 'seopress-broken-links',
			'hook'            => $hook,
			'plugin_file'     => __FILE__,
			'plugin_version'  => SEOPRESS_PRO_VERSION,
			// Inline the first page so the React app can render the listing
			// without waiting for a network round-trip. Mirrors the defaults
			// hard-coded in `BrokenLinksDataView.js#DEFAULT_VIEW`.
			'preload'         => array(
				array(
					'global' => 'SEOPRESS_BROKEN_LINKS_PRELOADED',
					'route'  => '/seopress/v1/broken-links',
					'params' => array(
						'page'     => 1,
						'per_page' => 25,
						'orderby'  => 'date',
						'order'    => 'desc',
					),
				),
			),
		)
	);
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'admin_enqueue_scripts', 'seopress_pro_enqueue_broken_links_assets', 20, 1 );
}

/**
 * Enqueue Site Audit React app assets on the `seopress-bot-batch` admin page.
 *
 * Mirrors seopress_pro_enqueue_broken_links_assets(): loads the compiled
 * bundle + its asset manifest, bundles the DataViews stylesheet (not shipped
 * as a registered WP style handle), and wires translations.
 *
 * @since 9.8.0
 *
 * @param string $hook The current admin page hook suffix.
 *
 * @return void
 */
function seopress_pro_enqueue_site_audit_assets( $hook ) {
	// Skip the DataViews bundle on WP versions older than the minimum required
	// for `@wordpress/dataviews@14`. The audit submenu render callback shows
	// a "WP X+ required" notice instead — there is no legacy screen to fall
	// back to for this page.
	if ( ! \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
		return;
	}

	\SEOPressPro\Helpers\DataViewsAssets::enqueue(
		array(
			'name'            => 'audit',
			'handle'          => 'seopress-pro-site-audit',
			'page_hook_match' => 'seopress-bot-batch',
			'hook'            => $hook,
			'plugin_file'     => __FILE__,
			'plugin_version'  => SEOPRESS_PRO_VERSION,
			// Inline the Overview payload (counters + health score + top
			// issues + scan status) so the React landing tab can paint the
			// big numbers immediately. History, priority URLs and per-panel
			// view-prefs stay on their normal fetch paths since they back
			// optional / collapsible UI sections.
			'preload'         => array(
				array(
					'global' => 'SEOPRESS_AUDIT_OVERVIEW_PRELOADED',
					'route'  => '/seopress/v1/site-audit/overview',
				),
			),
		)
	);
}
if ( SEOPRESS_PRO_RUNTIME_READY ) {
	add_action( 'admin_enqueue_scripts', 'seopress_pro_enqueue_site_audit_assets', 20, 1 );
}