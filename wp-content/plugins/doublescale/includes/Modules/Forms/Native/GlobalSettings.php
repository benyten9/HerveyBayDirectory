<?php
/**
 * Settings shared by every DoubleScale form: the address base of form links and CAPTCHA keys.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

/**
 * Forms → Settings.
 */
final class GlobalSettings {

	public const OPTION = 'doublescale_forms_settings';

	/**
	 * All settings, with defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Save settings. Secret keys left empty keep their stored value.
	 *
	 * @param array $input New settings.
	 * @return array Saved settings.
	 */
	public static function save( array $input ): array {
		$current = self::get();
		foreach ( array( 'turnstile', 'recaptcha', 'hcaptcha' ) as $provider ) {
			if ( ! isset( $input['captcha'][ $provider ] ) ) {
				continue;
			}
			$given = $input['captcha'][ $provider ];
			if ( array_key_exists( 'site_key', $given ) && '' === trim( (string) $given['site_key'] ) ) {
				// No site key: the provider is off, and its saved secret goes too.
				$input['captcha'][ $provider ]['secret_key'] = '';
			} elseif ( '' === (string) ( $given['secret_key'] ?? '' ) ) {
				// An empty secret field keeps the one already saved.
				$input['captcha'][ $provider ]['secret_key'] = $current['captcha'][ $provider ]['secret_key'];
			}
		}
		$settings = self::normalize( array_replace_recursive( $current, $input ) );
		$old_base = $current['base'];

		update_option( self::OPTION, $settings, false );

		if ( $old_base !== $settings['base'] ) {
			update_option( 'doublescale_forms_flush_rewrites', 1, false );
		}

		return $settings;
	}

	/**
	 * Settings as the admin sees them: secret keys masked.
	 *
	 * @return array
	 */
	public static function for_admin(): array {
		$settings = self::get();
		foreach ( $settings['captcha'] as $provider => $keys ) {
			if ( is_array( $keys ) && isset( $keys['secret_key'] ) ) {
				$settings['captcha'][ $provider ]['has_secret'] = '' !== $keys['secret_key'];
				$settings['captcha'][ $provider ]['secret_key'] = '';
			}
		}
		// Where form links start (home URL, plus index.php/ on "plain index" permalinks).
		$structure             = (string) get_option( 'permalink_structure' );
		$settings['link_root'] = untrailingslashit( home_url( 0 === strpos( $structure, '/index.php' ) ? 'index.php/' : '/' ) );
		return $settings;
	}

	/**
	 * The address base of form links ("forms" in example.com/forms/contact/).
	 *
	 * @return string
	 */
	public static function base(): string {
		return self::get()['base'];
	}

	/**
	 * Public CAPTCHA site key for a provider, or '' when it isn't set up.
	 *
	 * @param string $provider turnstile, recaptcha or hcaptcha.
	 * @return string
	 */
	public static function site_key( string $provider ): string {
		$keys = self::get()['captcha'][ $provider ] ?? array();
		return ( '' !== ( $keys['site_key'] ?? '' ) && '' !== ( $keys['secret_key'] ?? '' ) ) ? $keys['site_key'] : '';
	}

	/**
	 * Whether a link base would take over a WordPress or WooCommerce address, or an existing page.
	 *
	 * @param string $base Sanitized base.
	 * @return bool
	 */
	public static function is_reserved_base( string $base ): bool {
		$reserved = array( 'category', 'tag', 'author', 'page', 'feed', 'comments', 'search', 'embed', 'trackback', 'attachment', 'wp-json', 'wp-admin', 'wp-content', 'wp-includes', 'wp-login', 'wp-login-php', 'xmlrpc', 'xmlrpc-php', 'index-php', 'login', 'admin', 'dashboard', 'sitemap', 'sitemap_index', 'robots', 'shop', 'cart', 'checkout', 'my-account', 'product', 'product-category', 'product-tag', 'portal', 'booking', 'blog', 'type', 'date' );
		foreach ( array( 'category_base', 'tag_base' ) as $option ) {
			$value = trim( (string) get_option( $option ), '/' );
			if ( '' !== $value ) {
				$reserved[] = $value;
			}
		}
		/**
		 * Addresses a form link base may not use.
		 *
		 * @param string[] $reserved Slugs.
		 */
		$reserved = (array) apply_filters( 'doublescale_forms_reserved_bases', $reserved );
		if ( in_array( $base, $reserved, true ) ) {
			return true;
		}
		// An existing page, post or post type archive with that slug.
		if ( get_page_by_path( $base, OBJECT, array( 'page', 'post' ) ) ) {
			return true;
		}
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$rewrite = is_array( $type->rewrite ) ? trim( (string) ( $type->rewrite['slug'] ?? '' ), '/' ) : '';
			if ( $base === $type->name || $base === $rewrite || ( is_string( $type->has_archive ) && $base === $type->has_archive ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clean settings.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	private static function normalize( array $settings ): array {
		$base = sanitize_title( (string) ( $settings['base'] ?? 'forms' ) );
		if ( '' === $base ) {
			$base = 'forms';
		}

		$captcha = array();
		foreach ( array( 'turnstile', 'recaptcha', 'hcaptcha' ) as $provider ) {
			$keys                 = (array) ( $settings['captcha'][ $provider ] ?? array() );
			$captcha[ $provider ] = array(
				'site_key'   => sanitize_text_field( (string) ( $keys['site_key'] ?? '' ) ),
				'secret_key' => sanitize_text_field( (string) ( $keys['secret_key'] ?? '' ) ),
			);
		}
		$threshold                       = (float) ( $settings['captcha']['recaptcha_threshold'] ?? 0.5 );
		$captcha['recaptcha_threshold'] = max( 0.1, min( 0.9, $threshold ) );

		return array(
			'base'    => $base,
			'captcha' => $captcha,
		);
	}
}
