<?php
/**
 * Sanitize document content sections for REST payloads.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * DocumentSectionsSanitizer class.
 */
final class DocumentSectionsSanitizer {

	/**
	 * @param array<int, mixed> $sections Raw section rows.
	 * @return array<int, array{title: string, body: string}>
	 */
	public static function sanitize( array $sections ): array {
		$sanitized = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$title = isset( $section['title'] ) ? sanitize_text_field( (string) $section['title'] ) : '';
			$body  = isset( $section['body'] ) ? wp_kses_post( (string) $section['body'] ) : '';

			if ( '' === trim( $title ) && '' === trim( wp_strip_all_tags( $body ) ) ) {
				continue;
			}

			$position = isset( $section['position'] ) ? (string) $section['position'] : 'after_totals';
			if ( ! in_array( $position, array( 'before_items', 'after_totals' ), true ) ) {
				$position = 'after_totals';
			}

			$sanitized[] = array(
				'title'    => $title,
				'body'     => $body,
				'position' => $position,
			);
		}

		return $sanitized;
	}
}
