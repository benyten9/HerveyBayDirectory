<?php
/**
 * AI entry points limited to the native Directory Builder.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\UI;

use DirectoristAIPro\Directory\DirectorySchema;

/** Native Directory Builder AI integration. */
final class DirectoryAssistant {
	/** Register only the native administrator screen mount. */
	public static function hooks(): void {
		add_action( 'admin_footer', array( self::class, 'render' ) );
	}

	/** Render a dedicated assistant without touching setup or legacy AI handlers. */
	public static function render(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen selection only.
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $page, array( 'atbdp-directory-types', 'atbdp-layout-builder' ), true ) || get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
			return; }
		$path = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'apps/dashboard/build/';
		if ( ! file_exists( $path . 'directory-assistant.asset.php' ) ) {
			return; }
		$asset = require $path . 'directory-assistant.asset.php';
		$url   = plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . 'apps/dashboard/build/';
		wp_enqueue_script( 'directorist-ai-directory-assistant', $url . 'directory-assistant.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'directorist-ai-directory-assistant', $url . 'directory-assistant.css', array( 'wp-components' ), $asset['version'] );
		wp_print_styles( array( 'wp-components', 'directorist-ai-directory-assistant' ) );
		$config = array(
			'apiPath'        => '/directorist-ai/v1',
			'siteId'         => get_current_blog_id(),
			'userId'         => get_current_user_id(),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'keys'           => array_merge( DirectorySchema::SECTIONS, DirectorySchema::SETTINGS ),
			'multiDirectory' => directorist_is_multi_directory_enabled(),
		);
		echo '<div id="directorist-ai-directory" data-directorist-ai-directory="' . esc_attr( wp_json_encode( $config ) ) . '"></div>';
	}
}
