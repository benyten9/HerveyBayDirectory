<?php
/**
 * XRechnung e-invoicing (Pro): wiring into free's Sales hooks.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use DoubleScale\Pro\Modules\Sales\EInvoicing\Services\XRechnungArchive;
use DoubleScale\Pro\Modules\Sales\EInvoicing\Services\XRechnungEmailAttachment;
use DoubleScale\Pro\Modules\Sales\EInvoicing\Services\XRechnungExporter;
use DoubleScale\Pro\Modules\Sales\EInvoicing\Services\XRechnungProfile;

/**
 * EInvoicing registrar.
 *
 * Free owns the invoice data (including the `buyer_reference` column) and
 * exposes neutral filters; everything XRechnung-specific lives here.
 */
final class EInvoicing {

	/**
	 * Whether the running free plugin has the hooks and helpers this feature
	 * needs (added in free 1.4.7).
	 *
	 * @return bool
	 */
	public static function free_supports(): bool {
		return class_exists( \DoubleScale\Modules\Documents\Services\DocumentDownload::class )
			&& class_exists( \DoubleScale\Modules\Documents\Services\DocumentTaxNote::class );
	}

	/**
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::free_supports() ) {
			return;
		}

		add_filter( 'doublescale_sales_settings_defaults', array( __CLASS__, 'settings_defaults' ) );
		add_filter( 'doublescale_sales_settings_sanitize', array( __CLASS__, 'sanitize_settings' ), 10, 2 );
		add_filter( 'doublescale_public_invoice_payload', array( __CLASS__, 'public_payload' ), 10, 2 );
		add_filter( 'doublescale_portal_documents_rows', array( __CLASS__, 'portal_rows' ), 10, 2 );

		// GoBD: freeze the XML the moment the invoice is issued.
		add_action( 'init', array( XRechnungArchive::class, 'ensure_table' ) );
		add_action( 'doublescale_sales_invoice_sent', array( XRechnungArchive::class, 'on_sent' ), 20, 3 );

		XRechnungEmailAttachment::register();
		add_filter( 'doublescale_admin_config', array( __CLASS__, 'admin_config' ) );
	}

	/**
	 * @param mixed $defaults Sales settings defaults.
	 * @return array<string, mixed>
	 */
	public static function settings_defaults( $defaults ): array {
		return array_merge( is_array( $defaults ) ? $defaults : array(), XRechnungProfile::setting_defaults() );
	}

	/**
	 * @param mixed                $extra  Already-sanitised add-on values.
	 * @param array<string, mixed> $merged Defaults + stored + submitted values.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $extra, $merged ): array {
		return array_merge(
			is_array( $extra ) ? $extra : array(),
			XRechnungProfile::sanitize_settings( is_array( $merged ) ? $merged : array() )
		);
	}

	/**
	 * The public page offers the XRechnung download only when it would
	 * succeed, so a customer never meets the seller's missing settings.
	 *
	 * @param mixed $payload Public invoice payload.
	 * @param mixed $invoice Invoice model.
	 * @return mixed
	 */
	public static function public_payload( $payload, $invoice ) {
		if ( ! is_array( $payload ) || ! $invoice instanceof InvoiceModel ) {
			return $payload;
		}

		$payload['xrechnung_available'] = XRechnungExporter::is_available( $invoice );

		return $payload;
	}

	/**
	 * Whether the site uses XRechnung (Sales → Settings → E-invoicing). Off,
	 * no download, no email attachment and nothing archived.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return '0' !== (string) SalesSettings::get( 'einvoice_enabled', '1' );
	}

	/**
	 * Tell the admin app whether to show the XRechnung buttons.
	 *
	 * @param mixed $config Admin config.
	 * @return mixed
	 */
	public static function admin_config( $config ) {
		if ( is_array( $config ) ) {
			$config['einvoicingEnabled'] = self::enabled();
		}
		return $config;
	}

	/**
	 * Flag which portal invoice cards can offer the XRechnung download.
	 *
	 * @param mixed  $rows    Portal document rows.
	 * @param object $contact The portal user's contact.
	 * @return mixed
	 */
	public static function portal_rows( $rows, $contact ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}

		$ids = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && 'invoice' === ( $row['type'] ?? '' ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		if ( empty( $ids ) ) {
			return $rows;
		}

		// One query for every card; the contact is the same for all of them.
		$invoices = InvoiceModel::query()->whereIn( 'id', $ids )->get()->keyBy( 'id' );

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) || 'invoice' !== ( $row['type'] ?? '' ) ) {
				continue;
			}
			$invoice = $invoices->get( (int) $row['id'] );
			if ( ! $invoice ) {
				continue;
			}
			$invoice->setRelation( 'contact', $contact );
			$rows[ $index ]['xrechnung_available'] = XRechnungExporter::is_available( $invoice );
		}

		return $rows;
	}
}
