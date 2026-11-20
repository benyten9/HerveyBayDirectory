<?php
/**
 * Split a help-center article into passages for the assistant.
 *
 * One passage per heading section (h1–h6), so an answer can cite "Billing ›
 * Change your plan" rather than a whole article. A section longer than
 * MAX_WORDS is cut into windows of MAX_WORDS with OVERLAP_WORDS carried over,
 * so a sentence on a boundary is still found whole in one of them. Text before
 * the first heading is a passage of its own, headed by nothing.
 *
 * Pure PHP (no WordPress calls) so it is unit-tested without WordPress.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Chunker class.
 */
final class Chunker {

	/**
	 * Longest passage, in words.
	 */
	public const MAX_WORDS = 250;

	/**
	 * Words repeated at the start of the next window of a long section.
	 */
	public const OVERLAP_WORDS = 40;

	/**
	 * Passages of an article body.
	 *
	 * @param string $html Article body (sanitised HTML).
	 * @return array<int, array{heading: string, text: string}>
	 */
	public static function split( string $html ): array {
		$sections = self::sections( $html );

		$out = array();
		foreach ( $sections as $section ) {
			$words = preg_split( '/\s+/u', $section['text'], -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $words ) || array() === $words ) {
				continue;
			}

			$step = self::MAX_WORDS - self::OVERLAP_WORDS;
			for ( $start = 0; $start < count( $words ); $start += $step ) {
				$out[] = array(
					'heading' => $section['heading'],
					'text'    => implode( ' ', array_slice( $words, $start, self::MAX_WORDS ) ),
				);
				if ( $start + self::MAX_WORDS >= count( $words ) ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Heading sections of the body, as plain text.
	 *
	 * @param string $html Body HTML.
	 * @return array<int, array{heading: string, text: string}>
	 */
	private static function sections( string $html ): array {
		$parts = preg_split( '/(<h[1-6][^>]*>.*?<\/h[1-6]>)/isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$sections = array();
		$heading  = '';
		$buffer   = '';
		foreach ( $parts as $part ) {
			if ( preg_match( '/^<h[1-6][^>]*>(.*?)<\/h[1-6]>$/isu', $part, $m ) ) {
				$sections[] = array(
					'heading' => $heading,
					'text'    => self::plain( $buffer ),
				);
				$heading = self::plain( $m[1] );
				$buffer  = '';
				continue;
			}
			$buffer .= ' ' . $part;
		}
		$sections[] = array(
			'heading' => $heading,
			'text'    => self::plain( $buffer ),
		);

		// A heading followed by nothing still says something ("Pricing").
		return array_values(
			array_filter(
				array_map(
					static function ( array $section ): array {
						if ( '' === $section['text'] && '' !== $section['heading'] ) {
							$section['text'] = $section['heading'];
						}
						return $section;
					},
					$sections
				),
				static fn( array $section ): bool => '' !== $section['text']
			)
		);
	}

	/**
	 * HTML fragment to one line of plain text; block tags become word breaks,
	 * inline formatting tags just disappear.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	public static function plain( string $html ): string {
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#isu', ' ', $html );
		// Inline formatting sits inside a word run ("<b>Billing</b>."): drop it
		// without a space. Every other tag (paragraph, list item, br) breaks words.
		$text = preg_replace( '#</?(a|abbr|b|code|em|i|mark|s|small|span|strong|sub|sup|u)\b[^>]*>#iu', '', (string) $text );
		$text = preg_replace( '/<[^>]*>/u', ' ', (string) $text );
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );

		return trim( (string) $text );
	}
}
