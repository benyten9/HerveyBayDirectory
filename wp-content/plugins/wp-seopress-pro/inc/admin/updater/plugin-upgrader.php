<?php
/**
 * SEOPress PRO Updater.
 *
 * @package SEOPress PRO
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/*
 * MIGRATE / UPGRADE
 */

add_action( 'admin_init', 'seopress_pro_upgrader' );
/**
 * Tell WP what to do when admin is loaded aka upgrader.
 */
function seopress_pro_upgrader() {
	$versions       = get_option( 'seopress_versions' );
	$actual_version = isset( $versions['pro'] ) ? $versions['pro'] : 0;

	// You can hook the upgrader to trigger any action when seopress is upgraded.
	// First install.
	if ( ! $actual_version ) {
		/*
		 * Allow to prevent plugin first install hooks to fire.
		 *
		 * @param (bool) $prevent True to prevent triggering first install hooks. False otherwise.
		 */
		if ( ! apply_filters( 'seopress_pro_prevent_first_install', false ) ) {
			/*
			 * Fires on the plugin first install.
			 *
			 */
			do_action( 'seopress_pro_first_install' );
		}
	}

	if ( SEOPRESS_PRO_VERSION !== $actual_version ) {
		/*
		 * Add Redirections caps to user with "manage_options" capability.
		 */
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
				if ( isset( $roles[ $key ] ) && $role->has_cap( 'manage_options' ) ) {
					$role->add_cap( 'edit_broken_link' );
					$role->add_cap( 'edit_broken_links' );
					$role->add_cap( 'edit_others_broken_links' );
					$role->add_cap( 'publish_broken_links' );
					$role->add_cap( 'read_broken_link' );
					$role->add_cap( 'read_private_broken_links' );
					$role->add_cap( 'delete_broken_link' );
					$role->add_cap( 'delete_broken_links' );
					$role->add_cap( 'delete_others_broken_links' );
					$role->add_cap( 'delete_published_broken_links' );
				}
			}
		}

		/*
		 * Fires when seopress Pro is upgraded.
		 *
		 * @param (string) $new_pro_version    The version being upgraded to.
		 * @param (string) $actual_pro_version The previous version.
		 */
		do_action( 'seopress_pro_upgrade', SEOPRESS_PRO_VERSION, $actual_version );
	}

	// If any upgrade has been done, we flush and update version.
	if ( did_action( 'seopress_pro_first_install' ) || did_action( 'seopress_pro_upgrade' ) ) {
		// Do not use seopress_get_option() here.

		$options = get_option( 'seopress_versions' );
		$options = is_array( $options ) ? $options : array();

		$options['pro'] = SEOPRESS_PRO_VERSION;
		if ( is_multisite() ) {
			// We must pass these parameters for performance reasons.
			$sites = get_sites(
				array(
					'update_site_cache'      => false,
					'update_site_meta_cache' => false,
					'number'                 => 9999,
				)
			);
			foreach ( $sites as $site ) {
				update_blog_option( $site->blog_id, 'seopress_versions', $options );
			}
		} else {
			update_option( 'seopress_versions', $options );
		}
	}
}

add_action( 'seopress_pro_upgrade', 'seopress_pro_new_upgrade', 10, 2 );

/**
 * What to do when seopress is updated, depending on versions.
 *
 * @param (string) $seopress_version The version being upgraded to.
 * @param (string) $actual_version   The previous version.
 */
function seopress_pro_new_upgrade( $seopress_version, $actual_version ) {
}
