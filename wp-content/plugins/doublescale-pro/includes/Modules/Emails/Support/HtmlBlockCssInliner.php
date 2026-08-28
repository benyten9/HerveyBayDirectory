<?php
/**
 * Inline CSS from HTML block custom CSS and embedded <style> tags for email clients.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Support;

defined( 'ABSPATH' ) || exit;

/**
 * HtmlBlockCssInliner
 */
class HtmlBlockCssInliner {

	/**
	 * Process HTML content: inline supported CSS rules and strip <style> tags.
	 *
	 * @param string $html       Block HTML content.
	 * @param string $custom_css Additional CSS from the block settings field.
	 * @param string $block_id   Root element id used for scoping.
	 * @return string Processed HTML.
	 */
	public static function process( string $html, string $custom_css = '', string $block_id = '' ): string {
		$extracted_styles = array();
		$html             = preg_replace_callback(
			'/<style\b[^>]*>(.*?)<\/style>/is',
			static function ( $matches ) use ( &$extracted_styles ) {
				$body = trim( $matches[1] ?? '' );
				if ( '' !== $body ) {
					$extracted_styles[] = $body;
				}
				return '';
			},
			$html
		);

		$css_chunks = array_filter( array_merge( $extracted_styles, array( trim( $custom_css ) ) ) );
		if ( empty( $css_chunks ) || ! class_exists( 'DOMDocument' ) ) {
			return $html;
		}

		$rules = array();
		foreach ( $css_chunks as $chunk ) {
			$rules = array_merge( $rules, self::parse_rules( $chunk ) );
		}

		if ( empty( $rules ) ) {
			return $html;
		}

		$wrapped = '<div id="' . esc_attr( $block_id ) . '">' . $html . '</div>';
		$doc     = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?>' . $wrapped,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$root = $doc->getElementById( $block_id );
		if ( ! $root ) {
			return $html;
		}

		$xpath = new \DOMXPath( $doc );
		foreach ( $rules as $rule ) {
			$selector = $rule['selector'];
			$styles   = $rule['styles'];
			if ( empty( $styles ) ) {
				continue;
			}

			$nodes = self::query_nodes( $xpath, $root, $selector );
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}
				$existing = $node->getAttribute( 'style' );
				$merged     = self::merge_style_strings( $existing, $styles );
				$node->setAttribute( 'style', $merged );
			}
		}

		$output = '';
		foreach ( $root->childNodes as $child ) {
			$output .= $doc->saveHTML( $child );
		}

		return $output;
	}

	/**
	 * Parse simple CSS rules from a stylesheet chunk.
	 *
	 * @param string $css CSS text.
	 * @return array<int, array{selector: string, styles: string}>
	 */
	private static function parse_rules( string $css ): array {
		$rules  = array();
		$chunks = preg_split( '/\}/', $css );
		if ( ! is_array( $chunks ) ) {
			return $rules;
		}

		foreach ( $chunks as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk || false === strpos( $chunk, '{' ) ) {
				continue;
			}
			list( $selector, $body ) = explode( '{', $chunk, 2 );
			$selector = trim( $selector );
			$body     = trim( $body );
			if ( '' === $selector || '' === $body || 0 === strpos( $selector, '@' ) ) {
				continue;
			}
			$rules[] = array(
				'selector' => $selector,
				'styles'   => $body,
			);
		}

		return $rules;
	}

	/**
	 * Query nodes for a selector within the block root.
	 *
	 * @param \DOMXPath     $xpath    XPath instance.
	 * @param \DOMElement   $root     Block root.
	 * @param string        $selector CSS selector.
	 * @return \DOMNodeList<int, \DOMNode>|array<int, \DOMNode>
	 */
	private static function query_nodes( \DOMXPath $xpath, \DOMElement $root, string $selector ) {
		$selector = trim( $selector );
		if ( '' === $selector ) {
			return array();
		}

		// Element selector: p, div, h1, etc.
		if ( preg_match( '/^[a-z][a-z0-9]*$/i', $selector ) ) {
			return $root->getElementsByTagName( strtolower( $selector ) );
		}

		// Class selector: .foo
		if ( 0 === strpos( $selector, '.' ) ) {
			$class = substr( $selector, 1 );
			return $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]', $root );
		}

		// ID selector: #foo
		if ( 0 === strpos( $selector, '#' ) ) {
			$id = substr( $selector, 1 );
			$el = $root->ownerDocument ? $root->ownerDocument->getElementById( $id ) : null;
			return $el ? array( $el ) : array();
		}

		// Tag.class: p.intro
		if ( preg_match( '/^([a-z][a-z0-9]*)\.([a-z0-9_-]+)$/i', $selector, $m ) ) {
			$tag   = strtolower( $m[1] );
			$class = $m[2];
			return $xpath->query(
				'.//' . $tag . '[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]',
				$root
			);
		}

		return array();
	}

	/**
	 * Merge two inline style strings (later wins for duplicate properties).
	 *
	 * @param string $existing Existing inline style.
	 * @param string $addition New declarations.
	 * @return string
	 */
	private static function merge_style_strings( string $existing, string $addition ): string {
		$map = array();
		foreach ( array( $existing, $addition ) as $chunk ) {
			foreach ( explode( ';', $chunk ) as $decl ) {
				$decl = trim( $decl );
				if ( '' === $decl || false === strpos( $decl, ':' ) ) {
					continue;
				}
				list( $prop, $val ) = array_map( 'trim', explode( ':', $decl, 2 ) );
				$map[ strtolower( $prop ) ] = $val;
			}
		}
		$parts = array();
		foreach ( $map as $prop => $val ) {
			$parts[] = $prop . ': ' . $val;
		}
		return implode( '; ', $parts );
	}
}
