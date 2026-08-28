<?php
/**
 * Pro Renderer Loader.
 *
 * Subscribes to Free's `doublescale_booking_renderer_enqueue_scripts` action
 * (fired by `BaseTemplateRenderer::enqueue_react_assets()` before the free
 * renderer script is enqueued) and enqueues the Pro renderer bundle so Pro
 * `addFilter` registrations in `src/renderer/booking-pro/index.tsx` run on
 * public booking pages. Without this, every renderer filter (Stripe payment
 * component, price display, waiting-list copy, etc.) falls through to the
 * free defaults.
 *
 * @package DoubleScale\Pro\Modules\Booking
 * @subpackage Renderer
 */

namespace DoubleScale\Pro\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

final class ProRendererLoader {

	public const SCRIPT_HANDLE = 'doublescale-booking-pro-renderer';
	public const STYLE_HANDLE  = 'doublescale-booking-pro-renderer';

	/**
	 * Subscribe to the free renderer's enqueue action. Idempotent.
	 */
	public static function register(): void {
		add_action(
			'doublescale_booking_renderer_enqueue_scripts',
			array( __CLASS__, 'enqueue' )
		);
	}

	/**
	 * Enqueue the Pro renderer bundle.
	 *
	 * Depends on the free script handle `doublescale-booking-renderer` so that
	 * (a) Pro loads in the same request batch as Free and (b) Pro's
	 * `addFilter` calls run synchronously before Free's renderer module reads
	 * any filter result (Pro adds at default priority 10, same as Free's
	 * applyFilters consumers).
	 */
	public static function enqueue(): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) || ! defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ) {
			return;
		}

		$asset_file = DOUBLESCALE_PRO_PLUGIN_DIR . 'build/renderer/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$deps    = (array) ( $asset['dependencies'] ?? array() );
		$deps[]  = 'doublescale-booking-renderer';
		$version = $asset['version'] ?? ( defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : null );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			DOUBLESCALE_PRO_PLUGIN_URL . 'build/renderer/index.js',
			$deps,
			$version,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::SCRIPT_HANDLE,
				'doublescale',
				DOUBLESCALE_PRO_PLUGIN_DIR . 'languages'
			);
		}

		$style_file = DOUBLESCALE_PRO_PLUGIN_DIR . 'build/renderer/style.css';
		if ( is_readable( $style_file ) ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				DOUBLESCALE_PRO_PLUGIN_URL . 'build/renderer/style.css',
				array( 'doublescale-booking-renderer' ),
				$version
			);
			wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );
		}
	}
}
