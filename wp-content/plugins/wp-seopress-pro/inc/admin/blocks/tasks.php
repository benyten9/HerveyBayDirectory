<?php
/**
 * SEOPress PRO Tasks block.
 *
 * @package SEOPress PRO
 * @subpackage Blocks
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Check if Schemas feature is correctly enabled by the user.
 *
 * @return string The task status.
 */
function seopress_tasks_schemas() {
	$options = get_option( 'seopress_pro_option_name' );
	if ( isset( $options['seopress_rich_snippets_enable'] ) && '1' === seopress_get_toggle_option( 'rich-snippets' ) ) {
		return 'done';
	}
}

/**
 * Filter Tasks from SEO dashboard.
 *
 * @param array $tasks The tasks.
 * @return array The tasks.
 */
function seopress_pro_dashboard_tasks( $tasks ) {
	$tasks = array(
		array(
			'done'  => ( 'valid' === get_option( 'seopress_pro_license_status' ) && ! is_multisite() ) ? 'done' : '',
			'link'  => admin_url( 'admin.php?page=seopress-license' ),
			'label' => __( 'Activate your license key', 'wp-seopress-pro' ),
		),
		array(
			'done'  => seopress_tasks_sitemaps(),
			'link'  => admin_url( 'admin.php?page=seopress-xml-sitemap' ),
			'label' => __( 'Generate XML sitemaps', 'wp-seopress-pro' ),
		),
		array(
			'done'  => seopress_tasks_social_networks(),
			'link'  => admin_url( 'admin.php?page=seopress-social' ),
			'label' => __( 'Be social', 'wp-seopress-pro' ),
		),
		array(
			'done'  => ( seopress_get_toggle_option( 'local-business' ) === '1' ) ? 'done' : '',
			'link'  => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_local_business' ),
			'label' => __( 'Improve Local SEO', 'wp-seopress-pro' ),
		),
		array(
			'done'  => seopress_tasks_schemas(),
			'link'  => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_rich_snippets' ),
			'label' => __( 'Add Structured Data Types to increase visibility in SERPs', 'wp-seopress-pro' ),
		),
	);

	return $tasks;
}
add_filter( 'seopress_dashboard_tasks', 'seopress_pro_dashboard_tasks' );
