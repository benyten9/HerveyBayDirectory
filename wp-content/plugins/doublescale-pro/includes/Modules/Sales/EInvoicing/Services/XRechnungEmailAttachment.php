<?php
/**
 * Attach the XRechnung XML to the invoice email.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Sales\Services\SalesSettings;

/**
 * XRechnungEmailAttachment service.
 *
 * Freezes the XML in the archive first and attaches exactly that version, so
 * the customer's copy, the archive and later downloads are the same bytes
 * (the send hook then finds it unchanged and adds no second version).
 * Anything missing — settings, a writable temp dir — just means no
 * attachment: the invoice email always goes out.
 */
final class XRechnungEmailAttachment {

	/**
	 * Temp files written for the current request.
	 *
	 * @var string[]
	 */
	private static $files = array();

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'doublescale_sales_invoice_email_attachments', array( __CLASS__, 'attach' ), 10, 2 );
		// Remove the temp file as soon as the mail is out (or failed).
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'cleanup' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'cleanup' ) );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'cleanup_after_short_circuit' ), PHP_INT_MAX, 1 );
		register_shutdown_function( array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Whether invoice emails should carry the XML.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return '0' !== (string) SalesSettings::get( 'einvoice_attach_to_email', '1' );
	}

	/**
	 * @param mixed $attachments File paths.
	 * @param mixed $invoice     Invoice being emailed.
	 * @return mixed
	 */
	public static function attach( $attachments, $invoice ) {
		if ( ! is_array( $attachments ) || ! $invoice instanceof InvoiceModel || ! self::enabled() || ! \DoubleScale\Pro\Modules\Sales\EInvoicing\EInvoicing::enabled() ) {
			return $attachments;
		}

		try {
			$row = XRechnungArchive::archive( $invoice, 'email' );
			if ( is_wp_error( $row ) ) {
				return $attachments;
			}

			$dir = trailingslashit( get_temp_dir() ) . 'doublescale-xrechnung-' . wp_generate_password( 12, false, false );
			if ( ! wp_mkdir_p( $dir ) ) {
				return $attachments;
			}

			$path = $dir . '/' . sanitize_file_name( XRechnungExporter::filename( $invoice ) . '.xml' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $path, $row['xml'] ) ) {
				return $attachments;
			}

			self::$files[] = $path;
			$attachments[] = $path;
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'XRechnung email attachment failed',
					array(
						'source'     => 'einvoicing',
						'invoice_id' => (int) $invoice->id,
						'error'      => $e->getMessage(),
					)
				);
			}
		}

		return $attachments;
	}

	/**
	 * Delete the temp files written for this request.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		foreach ( self::$files as $path ) {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			}
			$dir = dirname( $path );
			if ( is_dir( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $dir );
			}
		}
		self::$files = array();
	}

	/**
	 * A short-circuited wp_mail() (pre_wp_mail returned non-null) fires
	 * neither wp_mail_succeeded nor wp_mail_failed; clean up here instead.
	 *
	 * @param mixed $short Short-circuit value.
	 * @return mixed
	 */
	public static function cleanup_after_short_circuit( $short ) {
		if ( null !== $short ) {
			self::cleanup();
		}
		return $short;
	}
}
