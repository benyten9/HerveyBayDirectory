<?php
/**
 * Abstract Email Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Blocks\EmailBlockInterface;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Email Block abstract class with common methods
 */
abstract class EmailBlock implements EmailBlockInterface {

	/**
	 * Process merge tags in content
	 *
	 * @param string                                   $content Content with merge tags
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string Processed content
	 */
	protected function process_merge_tags( $content, $contact = null ) {
		if ( empty( $content ) ) {
			return $content;
		}

		$content = $this->replace_assets_url( $content );

		if ( ! $contact ) {
			return $content;
		}

		return MergeTagsManager::instance()->process_merge_tags( $content, $contact );
	}

	/**
	 * Replace {{ASSETS_URL}} with the plugin images base URL.
	 *
	 * Template JSON uses this placeholder; the builder rewrites it on pick,
	 * but unsaved / leftover tags must still resolve at send time.
	 *
	 * @param string $content Content that may contain {{ASSETS_URL}}.
	 * @return string
	 */
	protected function replace_assets_url( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{{ASSETS_URL}}' ) ) {
			return $content;
		}

		$base = defined( 'DOUBLESCALE_PLUGIN_URL' )
			? trailingslashit( DOUBLESCALE_PLUGIN_URL ) . 'assets/images/'
			: '';

		return str_replace( '{{ASSETS_URL}}', $base, $content );
	}

	/**
	 * Escape an image `src` for an HTML attribute.
	 *
	 * Src values are a single string in one of these shapes:
	 * - `{{ASSETS_URL}}templates-images/greetings/img13.png` (template placeholder)
	 * - `https://example.com/wp-content/uploads/2026/08/photo.png` (media / full URL)
	 * - `http://localhost/wordpress/wp-content/plugins/doublescale/assets/images/…`
	 * - `data:image/png;base64,…` (inline image)
	 *
	 * Use `esc_attr()`, not `esc_url()`. WordPress `esc_url()` strips `{{…}}`
	 * and rejects the `data:` protocol, which leaves `<img src="">` and the
	 * inbox shows alt text instead of the picture.
	 *
	 * @param string $src Image source.
	 * @return string
	 */
	protected function escape_image_src( $src ) {
		$src = trim( (string) $src );
		if ( '' === $src ) {
			return '';
		}

		if ( preg_match( '/^(?:javascript|vbscript|file):/i', $src ) ) {
			return '';
		}

		return esc_attr( $src );
	}

	/**
	 * Re-escape `<img src>` values in HTML so merge tags and data URIs survive.
	 *
	 * @param string $html HTML that may contain img tags.
	 * @return string
	 */
	protected function rewrite_html_image_srcs( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$rewritten = preg_replace_callback(
			'/(<img\b[^>]*\bsrc\s*=\s*)(["\'])(.*?)\2/is',
			function ( $matches ) {
				$raw = html_entity_decode( $matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return $matches[1] . $matches[2] . $this->escape_image_src( $raw ) . $matches[2];
			},
			$html
		);

		return is_string( $rewritten ) ? $rewritten : $html;
	}

	/**
	 * Build CSS style string from array
	 *
	 * @param array $styles Array of CSS properties
	 * @return string CSS style string
	 */
	protected function build_style_string( array $styles ) {
		$style_string = '';

		foreach ( $styles as $property => $value ) {
			if ( $value !== null && $value !== '' ) {
				$style_string .= "{$property}: {$value}; ";
			}
		}

		return rtrim( $style_string );
	}

	/**
	 * Format padding from object to CSS string
	 *
	 * @param array|null $padding Padding object
	 * @return string CSS padding string
	 */
	protected function format_padding( $padding ) {
		if ( ! $padding ) {
			return '0';
		}

		$top    = isset( $padding['top'] ) ? $padding['top'] : 0;
		$right  = isset( $padding['right'] ) ? $padding['right'] : 0;
		$bottom = isset( $padding['bottom'] ) ? $padding['bottom'] : 0;
		$left   = isset( $padding['left'] ) ? $padding['left'] : 0;

		return "{$top}px {$right}px {$bottom}px {$left}px";
	}

	/**
	 * Convert camelCase to kebab-case
	 *
	 * @param string $string camelCase string
	 * @return string kebab-case string
	 */
	protected function convert_camel_to_kebab( $string ) {
		return strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $string ) );
	}
}
