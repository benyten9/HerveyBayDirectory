<?php // phpcs:ignore

namespace SEOPressPro\Actions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Data\CommandPaletteIndex;

/**
 * Registers the PRO contribution to the command palette.
 *
 * Two parts:
 *  1. Wires {@see \SEOPressPro\Data\CommandPaletteIndex} into the free plugin's
 *     `seopress_command_palette_items` filter so PRO pages/tabs/fields show up
 *     alongside the free ones (settings navigation).
 *  2. Enqueues a small JS bundle that registers PRO "quick actions"
 *     (side-effect commands) into the palette via the `seopress.commands.register`
 *     filter exposed by the free bundle.
 *
 * @since 9.8.0
 */
class CommandPalette implements ExecuteHooks {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function hooks() {
		CommandPaletteIndex::register();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_quick_actions' ) );
	}

	/**
	 * Enqueue the PRO quick-actions bundle on every admin page.
	 *
	 * Mirrors the guards used by the free palette bundle: only for users who can
	 * access SEOPress, and only when the native command palette (WP 6.3+) is
	 * available. Feature flags are localised so each action only appears when its
	 * PRO feature is enabled.
	 *
	 * @return void
	 */
	public function enqueue_quick_actions() {
		if ( ! current_user_can( seopress_capability( 'manage_options', 'dashboard' ) ) ) {
			return;
		}

		// The palette itself is provided by the free bundle (WP 6.3+); without it
		// there is nothing to contribute to.
		if ( ! wp_script_is( 'wp-commands', 'registered' ) ) {
			return;
		}

		$asset_file = SEOPRESS_PRO_PUBLIC_PATH . '/admin/command-palette/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		$dependencies = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		// addFilter() lives in wp-hooks — make sure it is a dependency.
		if ( ! in_array( 'wp-hooks', $dependencies, true ) ) {
			$dependencies[] = 'wp-hooks';
		}

		wp_enqueue_script(
			'seopress-pro-command-palette',
			SEOPRESS_PRO_PUBLIC_URL . '/admin/command-palette/index.js',
			$dependencies,
			$asset['version'],
			true
		);

		wp_localize_script(
			'seopress-pro-command-palette',
			'SEOPRESS_PRO_COMMAND_PALETTE',
			array(
				'HOME_URL'                 => home_url( '/' ),
				'NEWS_SITEMAP_URL'         => home_url( '/news.xml' ),
				'VIDEO_SITEMAP_URL'        => home_url( '/video1.xml' ),
				'REST_NONCE'               => wp_create_nonce( 'wp_rest' ),
				'IS_BOT_ENABLED'           => function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'bot' ),
				'IS_ALERTS_ENABLED'        => function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'alerts' ),
				'IS_NEWS_ENABLED'          => $this->is_news_sitemap_enabled(),
				'IS_VIDEO_SITEMAP_ENABLED' => $this->is_video_sitemap_enabled(),
			)
		);

		// No `merge_chunks()` here: this bundle is a single `index.js` with no
		// lazy chunk, and it is enqueued on every admin screen — merging would
		// inline the whole domain payload site-wide for nothing.
		wp_set_script_translations( 'seopress-pro-command-palette', 'wp-seopress-pro', WP_LANG_DIR . '/plugins' );
	}

	/**
	 * Whether the Google News sitemap is actually served.
	 *
	 * Mirrors {@see \SEOPressPro\Actions\Sitemap\RouterNewsSitemap}: both the
	 * `news` feature toggle and the News option must be on.
	 *
	 * @return bool
	 */
	private function is_news_sitemap_enabled() {
		if ( ! function_exists( 'seopress_get_toggle_option' ) || '1' !== seopress_get_toggle_option( 'news' ) ) {
			return false;
		}

		$options = get_option( 'seopress_pro_option_name', array() );

		return is_array( $options ) && ! empty( $options['seopress_news_enable'] );
	}

	/**
	 * Whether the XML video sitemap is actually served.
	 *
	 * The video sitemap has no dedicated feature toggle: it rides on the master
	 * XML sitemap, so it is only served when that sitemap is enabled (feature
	 * toggle + general enable) and the video sub-option is on. Mirrors the guards
	 * in {@see \SEOPress\Actions\Sitemap\Router}.
	 *
	 * @return bool
	 */
	private function is_video_sitemap_enabled() {
		if ( ! function_exists( 'seopress_get_toggle_option' ) || '1' !== seopress_get_toggle_option( 'xml-sitemap' ) ) {
			return false;
		}

		$options = get_option( 'seopress_xml_sitemap_option_name', array() );
		if ( ! is_array( $options ) ) {
			return false;
		}

		return ! empty( $options['seopress_xml_sitemap_general_enable'] )
			&& ! empty( $options['seopress_xml_sitemap_video_enable'] );
	}
}
