<?php
/**
 * Freeze issuer (supplier) details on sales documents at send time.
 *
 * @package DoubleScale\Modules\Documents\Services
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * DocumentIssuerSnapshot service.
 */
final class DocumentIssuerSnapshot {

	/**
	 * @param object $document Model with optional issuer_snapshot attribute.
	 * @return void
	 */
	public static function freeze_if_needed( $document ): void {
		if ( ! empty( $document->issuer_snapshot ) ) {
			return;
		}

		$document->issuer_snapshot = \wp_json_encode( DocumentPdf::resolved_company_block() );
	}

	/**
	 * @param string|null $json Stored snapshot JSON.
	 * @return array<string, mixed>|null
	 */
	public static function decode( ?string $json ): ?array {
		if ( null === $json || '' === trim( $json ) ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Resolve company block for PDF rendering from shaped document data.
	 *
	 * @param array<string, mixed> $shaped Shaped document payload.
	 * @return array<string, mixed>
	 */
	public static function resolve_company_for_shaped( array $shaped ): array {
		if ( ! empty( $shaped['issuer_snapshot'] ) && is_array( $shaped['issuer_snapshot'] ) ) {
			return DocumentPdf::normalize_company_block( $shaped['issuer_snapshot'] );
		}

		if ( ! empty( $shaped['sent_at'] ) && ! empty( $shaped['issuer_snapshot_raw'] ) ) {
			$decoded = self::decode( (string) $shaped['issuer_snapshot_raw'] );
			if ( $decoded ) {
				return DocumentPdf::normalize_company_block( $decoded );
			}
		}

		return DocumentPdf::resolved_company_block();
	}
}
