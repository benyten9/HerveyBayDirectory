<?php
/**
 * Client Portal → how the portal page looks.
 *
 * A page holding the portal shortcode is always shown on its own, without the
 * theme's header, footer or sidebars, the portal filling the window like an
 * app: the portal's own navigation and branding need the whole page, and
 * inside a theme they clashed with its header and styles. (The old
 * `full_screen` switch is gone; a saved `false` is ignored.)
 *
 * The brand colour that paints the portal and its login page is the
 * business's (Settings → Business, {@see BrandColor}); this class only hands it
 * on, and the portal's settings route can still change it.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\BrandColor;
use DoubleScale\Core\Settings\Settings;

/**
 * PortalPageLayout.
 */
final class PortalPageLayout {

	/** Settings key shared with the other client portal settings. */
	public const SETTINGS_KEY = 'client_portal';

	/** The portal's colour when none is set. */
	public const DEFAULT_BRAND_COLOR = BrandColor::DEFAULT;

	/** Body class on a full-screen portal page. */
	public const BODY_CLASS = 'doublescale-client-portal-standalone';

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		// A client's WordPress toolbar has nothing for them on their portal.
		add_filter( 'show_admin_bar', array( self::class, 'admin_bar' ), 20 );
	}

	/**
	 * Portal pages always fill the screen.
	 *
	 * @return bool
	 */
	public static function full_screen(): bool {
		return true;
	}

	/**
	 * The business's brand colour.
	 *
	 * @return string `#RRGGBB`.
	 */
	public static function brand_color(): string {
		return BrandColor::get();
	}

	/**
	 * @return array{brand_color: string, default_brand_color: string}
	 */
	public static function settings(): array {
		return array(
			'brand_color'         => self::brand_color(),
			'default_brand_color' => self::DEFAULT_BRAND_COLOR,
		);
	}

	/**
	 * @param array<string, mixed> $input `brand_color` (`#RRGGBB`; empty = the default).
	 * @return array{brand_color: string, default_brand_color: string}
	 */
	public static function update( array $input ): array {
		if ( array_key_exists( 'brand_color', $input ) ) {
			BrandColor::set( (string) $input['brand_color'] );
		}

		$raw = Settings::get( self::SETTINGS_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();
		// Retired with the always-full-screen page.
		unset( $raw['full_screen'] );
		Settings::update( self::SETTINGS_KEY, $raw );

		return self::settings();
	}

	/**
	 * Whether this request shows a portal page.
	 *
	 * @return bool
	 */
	public static function is_standalone_request(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();

		return $post instanceof \WP_Post
			&& ! empty( $post->post_content )
			&& has_shortcode( $post->post_content, 'doublescale_client_portal' );
	}

	/**
	 * The plugin's own page template instead of the theme's.
	 *
	 * @param string $template Template the theme picked.
	 * @return string
	 */
	public static function template( $template ) {
		if ( ! self::is_standalone_request() ) {
			return $template;
		}
		$own = dirname( __DIR__ ) . '/Renderer/views/standalone.php';

		return file_exists( $own ) ? $own : $template;
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( $classes ) {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::is_standalone_request() ) {
			$classes[] = self::BODY_CLASS;
		}

		return $classes;
	}

	/**
	 * No WordPress toolbar for clients on a full-screen portal page; the team
	 * keeps theirs.
	 *
	 * @param bool $show Whether the toolbar shows.
	 * @return bool
	 */
	public static function admin_bar( $show ) {
		if ( $show && self::is_standalone_request() && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		return $show;
	}
}
