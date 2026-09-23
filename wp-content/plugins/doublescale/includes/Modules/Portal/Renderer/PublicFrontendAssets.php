<?php
/**
 * Shared public frontend bundle (client portal + standalone document/support pages).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `build/renderer/portal/` once so invoice, proposal, contract, and
 * support shortcodes do not ship duplicate webpack copies.
 */
final class PublicFrontendAssets {

	public const HANDLE = 'doublescale-portal-renderer';

	/**
	 * Register the shared script (and style) if needed, then enqueue the script.
	 *
	 * @return string Script handle.
	 */
	public static function enqueue_script(): string {
		self::register();
		wp_enqueue_script( self::HANDLE );
		return self::HANDLE;
	}

	/**
	 * Enqueue the shared stylesheet in the light DOM (standalone public pages).
	 * The client portal inlines CSS into Shadow DOM and should not call this.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		self::register();
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * @return void
	 */
	private static function register(): void {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		$plugin_dir = defined( 'DOUBLESCALE_PLUGIN_DIR' ) ? \DOUBLESCALE_PLUGIN_DIR : '';
		$plugin_url = defined( 'DOUBLESCALE_PLUGIN_URL' ) ? \DOUBLESCALE_PLUGIN_URL : '';
		$version    = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0';

		$asset_file = $plugin_dir . 'build/renderer/portal/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : null;
		$deps       = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver        = isset( $asset['version'] ) ? $asset['version'] : $version;

		wp_register_script(
			self::HANDLE,
			$plugin_url . 'build/renderer/portal/index.js',
			$deps,
			$ver,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::HANDLE, 'doublescale', $plugin_dir . 'languages' );
		}

		wp_register_style(
			self::HANDLE,
			$plugin_url . 'build/renderer/portal/style.css',
			array(),
			$ver
		);
	}
}
