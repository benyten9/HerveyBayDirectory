<?php
/**
 * XRechnung (EN 16931 UBL) download routes for invoices.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Services\DocumentDownload;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\Sales\EInvoicing\EInvoicing;
use DoubleScale\Pro\Modules\Sales\EInvoicing\Services\XRechnungArchive;
use DoubleScale\Pro\Modules\Sales\EInvoicing\Services\XRechnungExporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestXRechnungController class.
 *
 * Admin: `GET sales/invoices/{id}/xrechnung` (same permission and ownership
 * rules as the invoice PDF). Customer: `GET sales/public/invoices/{hash}/xrechnung`
 * (same hash, draft and rate-limit rules as the public PDF).
 */
class RestXRechnungController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales';

	/**
	 * @return void
	 */
	public function register_routes() {
		// Older free (< 1.4.7) has neither the download helper nor the buyer
		// reference column: register nothing rather than half a feature. The
		// Pro/free version notice already tells the admin to update free.
		if ( ! EInvoicing::free_supports() ) {
			return;
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<id>[\d]+)/xrechnung',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_admin' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<id>[\d]+)/xrechnung/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<id>[\d]+)/xrechnung/archive',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reissue' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/public/invoices/(?P<hash>[A-Za-z0-9]{32})/xrechnung',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_public' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function admin_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_admin( $request ) {
		$off = $this->einvoicing_off();
		if ( $off ) {
			return $off;
		}

		$invoice = $this->owned_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		// ?version=N: a specific archived version, exactly as it was issued.
		$version = (int) $request->get_param( 'version' );
		if ( $version > 0 ) {
			$row = XRechnungArchive::get_version( (int) $invoice->id, $version );
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'That XRechnung version does not exist.', 'doublescale' ), array( 'status' => 404 ) );
			}
			return DocumentDownload::stream( $row['xml'], XRechnungExporter::filename( $invoice ) . '-v' . $version, 'xml', 'application/xml; charset=UTF-8' );
		}

		$xml = XRechnungArchive::for_download( $invoice );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		return DocumentDownload::stream( $xml, XRechnungExporter::filename( $invoice ), 'xml', 'application/xml; charset=UTF-8' );
	}

	/**
	 * Archive state for the invoice page: which version was issued, and
	 * whether the invoice has changed since (so the page can warn).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_status( $request ) {
		$off = $this->einvoicing_off();
		if ( $off ) {
			return $off;
		}

		$invoice = $this->owned_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$versions = XRechnungArchive::versions( (int) $invoice->id );
		$latest   = empty( $versions ) ? null : end( $versions );

		return new WP_REST_Response(
			array(
				'issued'        => XRechnungArchive::is_issued( $invoice ),
				'archived'      => null !== $latest,
				'version'       => $latest ? $latest['version'] : null,
				'archived_at'   => $latest ? $latest['created_at'] : null,
				'changed_since' => XRechnungArchive::changed_since( $invoice ),
				'versions'      => array_map(
					static function ( array $row ): array {
						return array(
							'version'    => $row['version'],
							'channel'    => $row['channel'],
							'created_at' => $row['created_at'],
						);
					},
					$versions
				),
			),
			200
		);
	}

	/**
	 * Issue a new version from the invoice as it is now; older versions stay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reissue( $request ) {
		$off = $this->einvoicing_off();
		if ( $off ) {
			return $off;
		}

		$invoice = $this->owned_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}
		if ( ! XRechnungArchive::is_issued( $invoice ) ) {
			return new WP_Error( 'not_issued', __( 'A draft has not been issued yet — send it first.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$row = XRechnungArchive::archive( $invoice, 'reissue' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return new WP_REST_Response(
			array(
				'version'    => $row['version'],
				'created_at' => $row['created_at'],
				'created'    => $row['created'],
			),
			$row['created'] ? 201 : 200
		);
	}

	/**
	 * Module gate, lookup and ownership for the admin routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return InvoiceModel|WP_Error
	 */
	private function owned_invoice( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = InvoiceModel::with( array( 'contact' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$owner = $invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null;
		if ( ! Capabilities::user_can_manage_record( get_current_user_id(), $owner ) ) {
			return new WP_Error( 'not_allowed', __( 'You do not have permission to access this invoice.', 'doublescale' ), array( 'status' => 403 ) );
		}

		return $invoice;
	}

	/**
	 * Customer-facing XRechnung download (public invoice page and portal).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_public( $request ) {
		$off = $this->einvoicing_off();
		if ( $off ) {
			return $off;
		}

		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$invoice = InvoiceModel::get_by_hash( (string) $request->get_param( 'hash' ) );
		if ( ! $invoice || InvoiceStatus::DRAFT === (string) $invoice->status ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$xml = XRechnungArchive::for_download( $invoice );
		if ( is_wp_error( $xml ) ) {
			// The blockers name the seller's settings (IBAN, contact phone…);
			// the customer only needs to know the file is not available.
			return new WP_Error(
				XRechnungExporter::ERROR_INCOMPLETE,
				__( 'This invoice is not available as XRechnung. Please contact the sender.', 'doublescale' ),
				array( 'status' => 422 )
			);
		}

		return DocumentDownload::stream( $xml, XRechnungExporter::filename( $invoice ), 'xml', 'application/xml; charset=UTF-8' );
	}

	/**
	 * Same budget as the public invoice routes (120 requests/minute per IP).
	 *
	 * @return bool
	 */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for rate-limit key.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'ds_sales_xr_pub_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count > 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Refuse while XRechnung is switched off in Sales → Settings → E-invoicing.
	 *
	 * @return WP_Error|null
	 */
	private function einvoicing_off(): ?WP_Error {
		if ( EInvoicing::enabled() ) {
			return null;
		}

		return new WP_Error(
			'doublescale_einvoicing_off',
			__( 'XRechnung is turned off. Turn it on in Sales → Settings → E-invoicing.', 'doublescale' ),
			array( 'status' => 404 )
		);
	}
}
