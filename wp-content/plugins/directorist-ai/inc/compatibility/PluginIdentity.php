<?php
/** Preserve the beta installation while adopting the Directorist AI identity. */

namespace DirectoristAIPro\Compatibility;

final class PluginIdentity {

	public const SLUG = 'directorist-ai';
	public const CANONICAL_BASENAME = 'directorist-ai/directorist-ai.php';
	public const LEGACY_SLUG = 'directorist-ai-pro';
	public const LEGACY_BASENAME = 'directorist-ai-pro/directorist-ai-pro.php';

	/**
	 * Boot one runtime only. Activating the canonical plugin retires the old
	 * activation through WordPress; it never uninstalls or deletes its data.
	 */
	public static function bootstrap( string $canonical_file, string $entry_file ): bool {
		$canonical = plugin_basename( $canonical_file );
		$legacy    = self::LEGACY_BASENAME;
		$active    = (array) get_option( 'active_plugins', array() );
		$network   = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		$old_file  = WP_PLUGIN_DIR . '/' . $legacy;
		$old_live  = is_file( $old_file ) && ( in_array( $legacy, $active, true ) || isset( $network[ $legacy ] ) );
		$other_runtime = defined( 'DIRECTORIST_AI_PRO_FILE' ) && realpath( DIRECTORIST_AI_PRO_FILE ) !== realpath( $entry_file );

		$activate = static function ( $network_wide = false ) use ( $legacy ): void {
			$current_network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
			if ( $network_wide || isset( $current_network[ $legacy ] ) ) {
				wp_die( esc_html__( 'Activate Directorist AI separately on each site. Deactivate the earlier network installation before switching.', 'directorist-ai' ) );
			}
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			// Silent deactivation preserves all data and avoids third-party
			// deactivation effects. WordPress activates the new entry afterwards.
			deactivate_plugins( $legacy, true, false );
		};

		if ( $canonical_file !== $entry_file || $old_live || $other_runtime ) {
			register_activation_hook( $canonical_file, $activate );
			add_action(
				'admin_init',
				static function () use ( $canonical, $legacy, $activate ): void {
					$active = (array) get_option( 'active_plugins', array() );
					$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
					if ( ! isset( $network[ $legacy ] ) && current_user_can( 'activate_plugins' ) && in_array( $canonical, $active, true ) && in_array( $legacy, $active, true ) ) {
						$activate( false );
					}
				},
				1
			);
		}

		// The repository keeps its old entry as a development bridge until all
		// consumers move. The customer ZIP contains only the canonical entry.
		if ( $canonical_file !== $entry_file ) {
			return ! $other_runtime;
		}
		return ! $old_live && ! $other_runtime;
	}
}
