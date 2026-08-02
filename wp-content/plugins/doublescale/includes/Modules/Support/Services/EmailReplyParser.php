<?php
/**
 * EmailReplyParser — strips the quoted history from an inbound email reply so a
 * support conversation shows only what the customer actually typed.
 *
 * When a customer replies from Gmail / Outlook / Apple Mail / etc., the client
 * appends the ENTIRE message being replied to underneath the new text (the
 * "On <date>, <name> wrote:" block, the bordered quote box, the
 * "-----Original Message-----" separator). Without trimming, every reply re-shows
 * the agent's previous message — the duplication seen in the ticket thread.
 *
 * Strategy (conservative — never lose the real reply):
 *   1. Remove the well-known quote CONTAINERS each major client wraps the history
 *      in (Gmail `div.gmail_quote`, Apple `blockquote[type=cite]`, Yahoo
 *      `div.yahoo_quoted`, Thunderbird `.moz-cite-prefix`).
 *   2. Cut everything after the Outlook boundary markers (`#appendonsend`,
 *      `#divRplyFwdMsg`) which sit BETWEEN the reply and the quote.
 *   3. Trim a trailing "On … wrote:" / "-----Original Message-----" attribution
 *      line the client left outside the container.
 *   4. If any step would leave the body visually empty, return the ORIGINAL —
 *      a thread that still shows the quote is far better than a blank reply.
 *
 * Free owns this because Free renders the conversation; Pro's inbound factory
 * calls it on the way in so both inbound engines trim identically.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

// This file walks PHP's DOMDocument tree, whose native node properties
// (parentNode, childNodes, nextSibling, nodeName) are camelCase and cannot be
// renamed. Exempt only that single naming sub-sniff for the whole file.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * EmailReplyParser class.
 */
final class EmailReplyParser {

	/**
	 * Class attribute substrings whose container node (and its descendants) is the
	 * quoted history and can be removed whole.
	 *
	 * @var string[]
	 */
	private const QUOTE_CLASSES = array(
		'gmail_quote',
		'gmail_quote_container',
		'yahoo_quoted',
		'moz-cite-prefix',
	);

	/**
	 * Element ids that mark the START of the quoted block: the node itself and
	 * everything after it in document order is history.
	 *
	 * @var string[]
	 */
	private const BOUNDARY_IDS = array(
		'appendonsend',
		'divRplyFwdMsg',
	);

	/**
	 * Strip the quoted reply history from an email body.
	 *
	 * @param string $html Inbound email body (HTML or plain text).
	 * @return string Body with the quoted history removed, or the original when
	 *                nothing could be removed safely.
	 */
	public static function strip_quoted_reply( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$result = self::strip_html( $html );
		if ( null !== $result ) {
			$result = self::trim_trailing_attribution( $result );
			if ( self::has_visible_text( $result ) ) {
				return trim( $result );
			}
		}

		// Plain-text (or container-less) fallback: cut at a text delimiter.
		$cut = self::cut_at_text_delimiter( $html );
		if ( $cut !== $html && self::has_visible_text( $cut ) ) {
			return trim( $cut );
		}

		return $html;
	}

	/**
	 * Remove quote containers / boundary blocks from an HTML body via DOM.
	 *
	 * @param string $html HTML body.
	 * @return string|null Inner HTML with quotes removed, or null when the body
	 *                     has no DOM support or no recognised quote markers.
	 */
	private static function strip_html( string $html ): ?string {
		if ( ! class_exists( '\DOMDocument' ) || false === strpos( $html, '<' ) ) {
			return null;
		}

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML PI pins the encoding so multi-byte bodies aren't mangled.
		$loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$xpath   = new \DOMXPath( $doc );
		$removed = false;

		foreach ( self::QUOTE_CLASSES as $class ) {
			$nodes = $xpath->query(
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]"
			);
			if ( $nodes instanceof \DOMNodeList ) {
				// Snapshot first: removing nodes mutates a live list mid-iteration.
				$snapshot = array();
				foreach ( $nodes as $node ) {
					$snapshot[] = $node;
				}
				foreach ( $snapshot as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
						$removed = true;
					}
				}
			}
		}

		// Apple Mail and others quote inside a bare cite blockquote.
		$cites = $xpath->query( "//blockquote[@type='cite']" );
		if ( $cites instanceof \DOMNodeList ) {
			$snapshot = array();
			foreach ( $cites as $node ) {
				$snapshot[] = $node;
			}
			foreach ( $snapshot as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
					$removed = true;
				}
			}
		}

		// XPath (not getElementById): libxml doesn't treat `id` as an ID attribute
		// for HTML loaded without a DTD, so getElementById returns null here.
		foreach ( self::BOUNDARY_IDS as $id ) {
			$matches = $xpath->query( "//*[@id='" . $id . "']" );
			if ( $matches instanceof \DOMNodeList && $matches->length > 0 ) {
				$boundary = $matches->item( 0 );
				if ( $boundary instanceof \DOMNode ) {
					self::remove_from( $boundary );
					$removed = true;
					break;
				}
			}
		}

		if ( ! $removed ) {
			return null;
		}

		return self::inner_html( $doc );
	}

	/**
	 * Remove a boundary node and everything after it in document order, while
	 * keeping its ancestors (which still hold the reply that precedes it).
	 *
	 * @param \DOMNode $node Boundary node.
	 * @return void
	 */
	private static function remove_from( \DOMNode $node ): void {
		$current     = $node;
		$remove_self = true;

		while ( $current && $current->parentNode ) {
			$parent = $current->parentNode;

			while ( $current->nextSibling ) {
				$parent->removeChild( $current->nextSibling );
			}

			if ( $remove_self ) {
				$parent->removeChild( $current );
				$remove_self = false;
			}

			$name = strtolower( $parent->nodeName );
			if ( 'body' === $name || 'html' === $name ) {
				break;
			}

			// Ascend: keep the parent (holds earlier reply text), trim what
			// follows it next loop.
			$current = $parent;
		}
	}

	/**
	 * Serialize the inner HTML of the parsed document body.
	 *
	 * @param \DOMDocument $doc Parsed document.
	 * @return string Inner HTML of <body>, or '' when absent.
	 */
	private static function inner_html( \DOMDocument $doc ): string {
		$bodies = $doc->getElementsByTagName( 'body' );
		$body   = $bodies->length > 0 ? $bodies->item( 0 ) : null;
		if ( ! $body instanceof \DOMNode ) {
			return '';
		}

		$html = '';
		foreach ( $body->childNodes as $child ) {
			$html .= $doc->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Trim a trailing "On … wrote:" / "-----Original Message-----" attribution
	 * line some clients place just before the (already removed) quote.
	 *
	 * @param string $html HTML body.
	 * @return string Body with the trailing attribution removed.
	 */
	private static function trim_trailing_attribution( string $html ): string {
		$patterns = array(
			// "On Mon, Jun 7, 2026 at 12:56 PM John Doe <a@b.com> wrote:" — possibly
			// wrapped in a block tag and at the very end of the body.
			'/(?:<(?:div|p|span)\b[^>]*>\s*)?On\b[^<]{0,400}?\bwrote:\s*(?:<\/(?:div|p|span)>\s*)*$/is',
			'/(?:<(?:div|p|span)\b[^>]*>\s*)?-{2,}\s*Original Message\s*-{2,}.*$/is',
		);

		foreach ( $patterns as $pattern ) {
			$trimmed = preg_replace( $pattern, '', $html );
			if ( is_string( $trimmed ) ) {
				$html = $trimmed;
			}
		}

		return $html;
	}

	/**
	 * Cut a plain-ish body at the first quote delimiter line.
	 *
	 * @param string $body Email body.
	 * @return string Body up to (excluding) the delimiter, or the original.
	 */
	private static function cut_at_text_delimiter( string $body ): string {
		// Deliberately conservative: only delimiters that unambiguously start a
		// quoted block. A bare "From:" line is NOT included — it appears in plenty
		// of legitimate replies and would truncate real content.
		$patterns = array(
			'/^\s*On\b.{0,400}?\bwrote:\s*$/im',
			'/^\s*-{2,}\s*Original Message\s*-{2,}/im',
			'/^\s*_{5,}\s*$/m',
		);

		$cut_at = null;
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $body, $m, PREG_OFFSET_CAPTURE ) ) {
				$offset = (int) $m[0][1];
				if ( null === $cut_at || $offset < $cut_at ) {
					$cut_at = $offset;
				}
			}
		}

		if ( null === $cut_at || $cut_at <= 0 ) {
			return $body;
		}

		return substr( $body, 0, $cut_at );
	}

	/**
	 * Whether a body still contains visible (non-whitespace) text once tags and
	 * entities are stripped.
	 *
	 * @param string $html HTML or text body.
	 * @return bool
	 */
	private static function has_visible_text( string $html ): bool {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$text = wp_strip_all_tags( $html );
		} else {
			// Non-WP unit-test fallback: drop script/style payloads first so they
			// don't read as visible text, mirroring wp_strip_all_tags().
			$html = (string) preg_replace( '@<(script|style)\b[^>]*>.*?</\\1>@si', '', $html );
			$text = strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- runtime uses wp_strip_all_tags() above; this branch only runs in the non-WP test context.
		}
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		// Collapse non-breaking spaces so a "&nbsp;"-only body counts as empty.
		$text = str_replace( "\xc2\xa0", ' ', $text );

		return '' !== trim( $text );
	}
}
