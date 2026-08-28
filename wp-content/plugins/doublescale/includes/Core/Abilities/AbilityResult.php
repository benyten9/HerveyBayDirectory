<?php
/**
 * Shared shaping helpers for ability payloads.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Pagination clamping and response envelopes.
 *
 * Centralised so fifteen abilities do not each re-invent paging, and so the
 * caps are enforced in one place. Schema `maximum` is a hint to the caller,
 * not a security boundary — every limit is clamped again here.
 */
final class AbilityResult {

	public const DEFAULT_LIMIT = 20;
	public const MAX_LIMIT     = 100;

	/**
	 * Clamp a caller-supplied limit into range.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input   Ability input.
	 * @param int                  $default Default when absent.
	 * @param int                  $max     Hard ceiling.
	 * @return int
	 */
	public static function limit( array $input, int $default = self::DEFAULT_LIMIT, int $max = self::MAX_LIMIT ): int {
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : $default;
		if ( $limit < 1 ) {
			$limit = $default;
		}
		return min( $limit, $max );
	}

	/**
	 * Clamp a caller-supplied offset to a non-negative integer.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return int
	 */
	public static function offset( array $input ): int {
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		return max( 0, $offset );
	}

	/**
	 * Build a list envelope.
	 *
	 * `has_more` and `total` matter to an agent: without them it cannot tell a
	 * capped page from a complete result and will report a partial count as
	 * final.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed>    $items  Shaped rows.
	 * @param int                  $total  Total matching rows before paging.
	 * @param int                  $limit  Applied limit.
	 * @param int                  $offset Applied offset.
	 * @param array<string, mixed> $extra Additional top-level keys.
	 * @return array<string, mixed>
	 */
	public static function collection( array $items, int $total, int $limit, int $offset, array $extra = array() ): array {
		return array_merge(
			array(
				'items'    => array_values( $items ),
				'total'    => $total,
				'limit'    => $limit,
				'offset'   => $offset,
				'has_more' => ( $offset + count( $items ) ) < $total,
			),
			$extra
		);
	}

	/**
	 * Truncate long free-text so one ability call cannot exhaust the context window.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text     Raw text.
	 * @param int    $max_chars Maximum characters to keep.
	 * @return array{text: string, truncated: bool}
	 */
	public static function truncate( string $text, int $max_chars = 2000 ): array {
		$text = self::plain_text( $text );

		if ( mb_strlen( $text ) <= $max_chars ) {
			return array(
				'text'      => $text,
				'truncated' => false,
			);
		}

		return array(
			'text'      => mb_substr( $text, 0, $max_chars ),
			'truncated' => true,
		);
	}

	/**
	 * Reduce stored HTML/email content to readable plain text.
	 *
	 * Stripping tags alone is not enough for ticket bodies harvested from
	 * inbound email: wp_strip_all_tags() removes the tags but leaves the CSS
	 * rules that lived inside <style>, plus the zero-width and non-breaking
	 * padding bulk mailers inject. Left in, that noise fills the truncation
	 * budget, so a body that is almost entirely markup passes as "not
	 * truncated" while carrying no readable content.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Raw stored content.
	 * @return string
	 */
	public static function plain_text( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		// Drop script/style blocks WITH their contents before stripping tags.
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text ) ?? $text;

		// Bare CSS survives when a mailer inlines rules without a <style> tag.
		// Nested blocks (@media wrapping rules) need the balanced-brace pass
		// below; a single non-greedy match leaves the outer closing brace.
		$text = preg_replace( '/@(import|charset|namespace)\b[^;]*;/i', ' ', $text ) ?? $text;
		$text = preg_replace( '/@(media|supports|font-face)\b[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/i', ' ', $text ) ?? $text;
		$text = preg_replace( '/[^{}<>]*\{[^{}]*\}/', ' ', $text ) ?? $text;

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Zero-width and non-breaking padding used to stretch preview text.
		$text = preg_replace( '/[\x{200B}-\x{200D}\x{00AD}\x{FEFF}\x{00A0}]+/u', ' ', $text ) ?? $text;

		// Collapse the runs of blank lines left behind.
		$text = preg_replace( '/[ \t]+/', ' ', $text ) ?? $text;
		$text = preg_replace( '/(\s*\R\s*){2,}/u', "\n", $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Standard 403 for a record the caller does not own.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Caller-facing message.
	 * @return \WP_Error
	 */
	public static function forbidden( string $message ): \WP_Error {
		return new \WP_Error(
			'doublescale_forbidden',
			$message,
			array( 'status' => 403 )
		);
	}

	/**
	 * Standard 404 for a record that does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Caller-facing message.
	 * @return \WP_Error
	 */
	public static function not_found( string $message ): \WP_Error {
		return new \WP_Error(
			'doublescale_not_found',
			$message,
			array( 'status' => 404 )
		);
	}
}
