<?php
/**
 * SEOPress Pro Admin Bar functions.
 *
 * @package SEOPress Pro
 * @subpackage Admin_Bar
 */

defined( 'ABSPATH' ) || exit( 'Please don’t call the plugin directly. Thanks :)' );

/**
 * Add SEOPress Pro admin bar items.
 */
function seopress_pro_admin_bar_items() {
	global $wp_admin_bar;

	$parent_id = 'seopress';
	$wp_admin_bar->add_menu(
		array(
			'parent' => $parent_id,
			'id'     => 'seopress_custom_sub_menu_pro',
			'title'  => __( 'PRO', 'wp-seopress-pro' ),
			'href'   => admin_url( 'admin.php?page=seopress-pro-page' ),
		)
	);

	if ( '1' == seopress_get_toggle_option( 'bot' ) ) {
		$wp_admin_bar->add_menu(
			array(
				'parent' => $parent_id,
				'id'     => 'seopress_custom_sub_menu_bot',
				'title'  => __( 'Audit', 'wp-seopress-pro' ),
				'href'   => admin_url( 'admin.php?page=seopress-bot-batch' ),
			)
		);
		$wp_admin_bar->add_menu(
			array(
				'parent' => $parent_id,
				'id'     => 'seopress_custom_sub_menu_broken_links',
				'title'  => __( 'Broken Links', 'wp-seopress-pro' ),
				'href'   => admin_url( 'admin.php?page=seopress-broken-links' ),
			)
		);
	}

	if ( '1' == seopress_get_toggle_option( 'rich-snippets' ) ) {
		$wp_admin_bar->add_menu(
			array(
				'parent' => $parent_id,
				'id'     => 'seopress_custom_sub_menu_schemas',
				'title'  => __( 'Schemas', 'wp-seopress-pro' ),
				'href'   => admin_url( 'admin.php?page=seopress-schemas' ),
			)
		);
	}

	if ( '1' == seopress_get_toggle_option( '404' ) ) {
		$wp_admin_bar->add_menu(
			array(
				'parent' => $parent_id,
				'id'     => 'seopress_custom_sub_menu_404',
				'title'  => __( 'Redirections', 'wp-seopress-pro' ),
				'href'   => admin_url( 'admin.php?page=seopress-redirections' ),
			)
		);
	}

	$wp_admin_bar->add_menu(
		array(
			'parent' => $parent_id,
			'id'     => 'seopress_custom_sub_menu_license',
			'title'  => __( 'License', 'wp-seopress-pro' ),
			'href'   => admin_url( 'admin.php?page=seopress-license' ),
		)
	);
}
add_action( 'seopress_admin_bar_items', 'seopress_pro_admin_bar_items', 100 );
