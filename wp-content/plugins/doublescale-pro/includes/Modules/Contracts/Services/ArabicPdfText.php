<?php
/**
 * Arabic text shaping for PDF output.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Services;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Dompdf renders glyphs in logical order and performs no Arabic shaping, so
 * Arabic comes out disconnected and reversed. ar-php rewrites the text into
 * Unicode presentation forms (U+FB50-U+FEFF) already laid out in visual order,
 * which Dompdf then draws correctly left-to-right.
 *
 * Shaping is destructive -- the output is display glyphs, not logical text --
 * so it must only ever run on the PDF branch, never on stored or on-screen
 * content.
 */
final class ArabicPdfText {

	/**
	 * Matches Arabic, Arabic Supplement and Arabic Extended-A blocks.
	 */
	private const ARABIC_PATTERN = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u';

	/**
	 * Shape plain (non-HTML) text such as a subject line or contract number.
	 *
	 * @param string $text Logical-order text.
	 * @return string Visual-order text with presentation forms.
	 */
	public static function shape( string $text ): string {
		if ( '' === $text || ! self::has_arabic( $text ) ) {
			return $text;
		}

		$arabic = self::arabic();
		if ( null === $arabic ) {
			return $text;
		}

		try {
			return (string) $arabic->utf8Glyphs( $text );
		} catch ( \Throwable $e ) {
			return $text;
		}
	}

	/**
	 * Shape the text nodes of an HTML fragment, leaving markup untouched.
	 *
	 * Shaping the raw string would reverse tag names and attributes along with
	 * the text, so the fragment is walked as a DOM and only DOMText nodes are
	 * rewritten.
	 *
	 * @param string $html HTML fragment (already sanitized).
	 * @return string HTML with Arabic text nodes shaped.
	 */
	public static function shape_html( string $html ): string {
		if ( '' === $html || ! self::has_arabic( $html ) ) {
			return $html;
		}

		if ( null === self::arabic() ) {
			return $html;
		}

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		// Force UTF-8: without an explicit charset DOMDocument assumes ISO-8859-1.
		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8"><div id="ds-arabic-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $html;
		}

		$xpath = new DOMXPath( $dom );
		$root  = $xpath->query( '//*[@id="ds-arabic-root"]' )->item( 0 );
		if ( ! $root instanceof DOMNode ) {
			return $html;
		}

		$text_nodes = $xpath->query( './/text()', $root );
		if ( false !== $text_nodes ) {
			foreach ( $text_nodes as $node ) {
				if ( ! $node instanceof DOMText ) {
					continue;
				}
				if ( ! self::has_arabic( $node->nodeValue ) ) {
					continue;
				}
				$node->nodeValue = self::shape( $node->nodeValue );
			}
		}

		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Whether the string contains any Arabic-script character.
	 *
	 * @param string $text Text to inspect.
	 * @return bool
	 */
	private static function has_arabic( string $text ): bool {
		return 1 === preg_match( self::ARABIC_PATTERN, $text );
	}

	/**
	 * Resolve the scoped ar-php class, or null when dependencies are unbuilt.
	 *
	 * @return object|null
	 */
	private static function arabic() {
		static $instance = false;

		if ( false !== $instance ) {
			return $instance;
		}

		$class = 'DoubleScale\\Vendor\\ArPHP\\I18N\\Arabic';
		if ( ! class_exists( $class ) ) {
			$class = 'ArPHP\\I18N\\Arabic';
		}

		$instance = class_exists( $class ) ? new $class() : null;

		return $instance;
	}
}
