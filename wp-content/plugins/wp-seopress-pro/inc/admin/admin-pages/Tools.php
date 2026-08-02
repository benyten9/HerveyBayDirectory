<?php //phpcs:ignore
/**
 * SEOPress PRO Tools page.
 *
 * Adds Data, Redirections, and Video sitemap tabs to the Tools page.
 * Tab content is rendered by React components registered via the extension registry.
 *
 * @package SEOPress PRO
 * @subpackage Admin_Pages
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Add Data, Redirects and Video tabs to Tools.
 *
 * @param array $plugin_settings_tabs The plugin settings tabs.
 * @return array The plugin settings tabs.
 */
function seopress_pro_tools_tabs( $plugin_settings_tabs ) {
	unset( $plugin_settings_tabs['tab_seopress_tool_settings'] );
	unset( $plugin_settings_tabs['tab_seopress_tool_plugins'] );
	unset( $plugin_settings_tabs['tab_seopress_tool_reset'] );

	$plugin_settings_tabs['tab_seopress_tool_data']      = __( 'Data', 'wp-seopress-pro' );
	$plugin_settings_tabs['tab_seopress_tool_settings']  = __( 'Settings', 'wp-seopress-pro' );
	$plugin_settings_tabs['tab_seopress_tool_plugins']   = __( 'Plugins', 'wp-seopress-pro' );
	$plugin_settings_tabs['tab_seopress_tool_redirects'] = __( 'Redirections', 'wp-seopress-pro' );
	$plugin_settings_tabs['tab_seopress_tool_video']     = __( 'Video sitemap', 'wp-seopress-pro' );
	$plugin_settings_tabs['tab_seopress_tool_reset']     = __( 'Reset', 'wp-seopress-pro' );

	return $plugin_settings_tabs;
}
add_filter( 'seopress_tools_tabs', 'seopress_pro_tools_tabs' );

/**
 * Add "Clean Site Audit" to the Reset tab's extra actions.
 *
 * @param array $actions The extra reset actions.
 * @return array
 */
function seopress_pro_tools_reset_actions( $actions ) {
	$actions[] = array(
		'key'         => 'clean_audit_scans',
		'title'       => __( 'Clean Site Audit', 'wp-seopress-pro' ),
		'label'       => __( 'Clean Site Audit', 'wp-seopress-pro' ),
		'description' => __( 'By clicking Delete SEO audit scans, all SEO issues will be deleted from your database.', 'wp-seopress-pro' ),
		'buttonLabel' => __( 'Delete SEO audit scans', 'wp-seopress-pro' ),
		'path'        => '/seopress/v1/tools/clean-audit-scans',
		'action'      => 'seopress_clean_audit_scans',
		'nonce'       => wp_create_nonce( 'seopress_clean_audit_scans_nonce' ),
	);

	return $actions;
}
add_filter( 'seopress_react_tools_reset_actions', 'seopress_pro_tools_reset_actions' );
