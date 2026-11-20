<?php
/**
 * Append-only archive of issued XRechnung files (GoBD).
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use WP_Error;

/**
 * XRechnungArchive service.
 *
 * GoBD requires an issued invoice to be kept exactly as issued. Invoices stay
 * editable in DoubleScale, so the XML is frozen here the moment it is issued
 * (sent, or first downloaded once no longer a draft) and every later download
 * serves that frozen copy. A re-issue adds a new version; nothing in this
 * class ever updates or deletes a row — deleting the invoice leaves its
 * archived e-invoices in place on purpose.
 */
final class XRechnungArchive {

	public const FORMAT = 'xrechnung';

	private const SCHEMA_OPTION  = 'doublescale_einvoice_archive_schema';
	private const SCHEMA_VERSION = '1';

	/**
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'doublescale_sales_einvoice_archive';
	}

	/**
	 * Create the archive table once (tracked by an option, so boot stays cheap).
	 *
	 * @return void
	 */
	public static function ensure_table(): void {
		global $wpdb;

		$table = self::table();
		if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_OPTION ) ) {
			// The table is confirmed once per Pro version, not on every request.
			$version = defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : '';
			if ( '' !== $version && get_option( self::SCHEMA_OPTION . '_checked' ) === $version ) {
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				if ( '' !== $version ) {
					update_option( self::SCHEMA_OPTION . '_checked', $version, false );
				}
				return;
			}
		}

		$charset = $wpdb->get_charset_collate();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`invoice_id` BIGINT UNSIGNED NOT NULL,
				`format` VARCHAR(20) NOT NULL,
				`version` INT UNSIGNED NOT NULL,
				`xml` LONGTEXT NOT NULL,
				`sha256` CHAR(64) NOT NULL,
				`channel` VARCHAR(20) NULL,
				`created_by` BIGINT UNSIGNED NULL,
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `invoice_format_version` (`invoice_id`, `format`, `version`)
			) {$charset}"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * An invoice is issued once it is no longer a draft.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return bool
	 */
	public static function is_issued( InvoiceModel $invoice ): bool {
		return InvoiceStatus::DRAFT !== (string) $invoice->status;
	}

	/**
	 * XML to hand out: the frozen copy for an issued invoice (freezing it now
	 * if it was issued before archiving existed), a live preview for a draft.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return string|WP_Error
	 */
	public static function for_download( InvoiceModel $invoice ) {
		if ( ! self::is_issued( $invoice ) ) {
			return XRechnungExporter::for_invoice( $invoice );
		}

		$latest = self::latest( (int) $invoice->id );
		if ( $latest ) {
			return $latest['xml'];
		}

		$archived = self::archive( $invoice, 'download' );

		return is_wp_error( $archived ) ? $archived : $archived['xml'];
	}

	/**
	 * Freeze the current XML as a new version, unless it is identical to the
	 * latest one (re-sending an unchanged invoice adds nothing).
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $channel What issued it: email, whatsapp, download, reissue.
	 * @return array<string, mixed>|WP_Error The version row (with `created` = bool).
	 */
	public static function archive( InvoiceModel $invoice, string $channel ) {
		$xml = XRechnungExporter::for_invoice( $invoice );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		self::ensure_table();

		$sha    = hash( 'sha256', $xml );
		$latest = self::latest( (int) $invoice->id );
		if ( $latest && hash_equals( $latest['sha256'], $sha ) ) {
			$latest['created'] = false;
			return $latest;
		}

		global $wpdb;
		$version = $latest ? $latest['version'] + 1 : 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'invoice_id' => (int) $invoice->id,
				'format'     => self::FORMAT,
				'version'    => $version,
				'xml'        => $xml,
				'sha256'     => $sha,
				'channel'    => sanitize_key( $channel ),
				'created_by' => get_current_user_id() ?: null,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $inserted ) {
			return new WP_Error( 'xrechnung_archive_failed', __( 'The XRechnung file could not be archived.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$row            = self::get_version( (int) $invoice->id, $version );
		$row['created'] = true;

		return $row;
	}

	/**
	 * Whether the invoice as it is now would produce a different XML than the
	 * one last issued (edited after sending, or seller settings changed).
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return bool
	 */
	public static function changed_since( InvoiceModel $invoice ): bool {
		$latest = self::latest( (int) $invoice->id );
		if ( ! $latest ) {
			return false;
		}

		// A payment received after issuing does not change the invoice that was
		// sent: compare against the paid amount the issued XML carried, so only
		// real edits (lines, prices, dates, parties) count as changes.
		$probe = clone $invoice;
		$probe->setAttribute( 'amount_paid', self::issued_prepaid( (string) $latest['xml'] ) );
		$current = XRechnungExporter::for_invoice( $probe );

		// Not expressible any more counts as changed: the sent copy no longer
		// matches what the invoice says.
		return is_wp_error( $current ) || ! hash_equals( $latest['sha256'], hash( 'sha256', $current ) );
	}

	/**
	 * Paid amount (BT-113 PrepaidAmount) written into an issued XML; 0 when absent.
	 *
	 * @param string $xml Issued XRechnung XML.
	 * @return float
	 */
	private static function issued_prepaid( string $xml ): float {
		return preg_match( '/PrepaidAmount[^>]*>\s*([0-9.]+)\s*</', $xml, $m ) ? (float) $m[1] : 0.0;
	}

	/**
	 * @param int $invoice_id Invoice ID.
	 * @return array<string, mixed>|null
	 */
	public static function latest( int $invoice_id ): ?array {
		return self::row( 'ORDER BY `version` DESC LIMIT 1', $invoice_id );
	}

	/**
	 * @param int $invoice_id Invoice ID.
	 * @param int $version    Version number.
	 * @return array<string, mixed>|null
	 */
	public static function get_version( int $invoice_id, int $version ): ?array {
		global $wpdb;

		return self::row( $wpdb->prepare( 'AND `version` = %d LIMIT 1', $version ), $invoice_id );
	}

	/**
	 * All versions, oldest first.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function versions( int $invoice_id ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `invoice_id` = %d AND `format` = %s ORDER BY `version` ASC", $invoice_id, self::FORMAT ), ARRAY_A );

		return array_map( array( __CLASS__, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * `doublescale_sales_invoice_sent`: freeze what was just sent. Never lets
	 * an e-invoice problem (missing settings) interfere with the send itself.
	 *
	 * @param mixed  $invoice Invoice.
	 * @param string $message Message (unused).
	 * @param string $channel Channel.
	 * @return void
	 */
	public static function on_sent( $invoice, $message = '', $channel = 'email' ): void {
		unset( $message );
		if ( ! $invoice instanceof InvoiceModel || ! \DoubleScale\Pro\Modules\Sales\EInvoicing\EInvoicing::enabled() ) {
			return;
		}

		try {
			self::archive( $invoice, (string) $channel );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'XRechnung archive on send failed',
					array(
						'source'     => 'einvoicing',
						'invoice_id' => (int) $invoice->id,
						'error'      => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * @param string $tail       SQL after the invoice/format condition.
	 * @param int    $invoice_id Invoice ID.
	 * @return array<string, mixed>|null
	 */
	private static function row( string $tail, int $invoice_id ): ?array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `invoice_id` = %d AND `format` = %s ", $invoice_id, self::FORMAT ) . $tail, ARRAY_A );

		return is_array( $row ) ? self::normalize( $row ) : null;
	}

	/**
	 * @return bool
	 */
	private static function table_exists(): bool {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $row ): array {
		return array(
			'invoice_id' => (int) $row['invoice_id'],
			'version'    => (int) $row['version'],
			'xml'        => (string) $row['xml'],
			'sha256'     => (string) $row['sha256'],
			'channel'    => null !== $row['channel'] ? (string) $row['channel'] : null,
			'created_by' => null !== $row['created_by'] ? (int) $row['created_by'] : null,
			'created_at' => (string) $row['created_at'],
		);
	}
}
