<?php
/**
 * PDF rendering for credit notes.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Services\DocumentIssuerSnapshot;
use WP_Error;

/**
 * CreditNotePdf service.
 */
final class CreditNotePdf {

	/**
	 * @param array<string, mixed> $shaped Shaped credit note data.
	 * @return string
	 */
	public static function render_html( array $shaped ): string {
		$company = DocumentIssuerSnapshot::resolve_company_for_shaped( $shaped );

		ob_start();
		$document = $shaped;
		include __DIR__ . '/templates/credit-note-pdf.php';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $shaped Shaped credit note data.
	 * @return string|WP_Error Raw PDF bytes or error.
	 */
	public static function render_pdf( array $shaped ) {
		if ( ! class_exists( 'DoubleScale\\Vendor\\Dompdf\\Dompdf' ) ) {
			return new WP_Error(
				'pdf_unavailable',
				__( 'PDF export is not available. Rebuild plugin dependencies with composer scope:vendor.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		$html = self::render_html( $shaped );

		$dompdf_class  = 'DoubleScale\\Vendor\\Dompdf\\Dompdf';
		$options_class = 'DoubleScale\\Vendor\\Dompdf\\Options';

		$options = new $options_class();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );

		try {
			$dompdf = new $dompdf_class( $options );
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();

			return (string) $dompdf->output();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( 'DoubleScale credit note PDF generation failed: ' . $e->getMessage() );
			}

			return new WP_Error(
				'pdf_generation_failed',
				__( 'PDF generation failed. Rebuild plugin dependencies with composer scope:vendor.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * @param array<string, mixed> $shaped   Shaped credit note data.
	 * @param string               $filename Download filename (without extension is fine).
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function rest_response( array $shaped, string $filename ) {
		$pdf = self::render_pdf( $shaped );
		if ( \is_wp_error( $pdf ) ) {
			return $pdf;
		}

		$download_name = \sanitize_file_name( $filename );
		if ( '' === $download_name ) {
			$download_name = 'credit-note';
		}
		if ( '.pdf' !== strtolower( substr( $download_name, -4 ) ) ) {
			$download_name .= '.pdf';
		}

		\add_filter(
			'rest_pre_serve_request',
			static function ( $served ) use ( $pdf, $download_name ) {
				if ( $served ) {
					return $served;
				}

				if ( ! headers_sent() ) {
					header( 'Content-Type: application/pdf' );
					header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
					header( 'Content-Length: ' . strlen( $pdf ) );
					header( 'X-Content-Type-Options: nosniff' );
					header( 'Cache-Control: private, no-store, max-age=0' );
				}

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PDF bytes.
				echo $pdf;

				return true;
			},
			10,
			1
		);

		return new \WP_REST_Response( null, 200 );
	}
}
