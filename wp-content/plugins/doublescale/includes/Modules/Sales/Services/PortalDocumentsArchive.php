<?php
/**
 * "Download all" in the client portal: the client's documents as one zip of
 * PDFs — exactly what the Documents page lists for the chosen type (so never
 * a draft, another client's document or a type hidden from them).
 *
 * Invoices and proposals are drawn here; Pro draws credit notes and contracts
 * through `doublescale_portal_document_pdf`.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Rest\ProposalShaper;
use DoubleScale\Modules\Documents\Services\DocumentPdf;
use WP_Error;
use WP_REST_Request;

/**
 * PortalDocumentsArchive.
 */
final class PortalDocumentsArchive {

	/** Documents in one zip at most (each PDF is drawn on the spot). */
	public const MAX_DOCUMENTS = 50;

	/** Types the Documents page filters by. */
	public const TYPES = array( 'all', 'invoice', 'proposal', 'contract', 'credit_note' );

	/**
	 * The zip's bytes, or why not.
	 *
	 * @param ContactModel|null $contact The signed-in client.
	 * @param string            $type    Documents page type.
	 * @return string|WP_Error
	 */
	public static function build( ?ContactModel $contact, string $type ) {
		if ( ! $contact ) {
			return new WP_Error( 'no_documents', __( 'There are no documents to download.', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_unavailable', __( 'Downloading several documents at once is not available on this site.', 'doublescale' ), array( 'status' => 503 ) );
		}
		$type = in_array( $type, self::TYPES, true ) ? $type : 'all';

		// The same list the Documents page shows, with every rule it applies.
		$request = new WP_REST_Request( 'GET', '/doublescale/v1/portal/documents' );
		$request->set_param( 'type', $type );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		$rows = array_slice( (array) ( $response->get_data()['data'] ?? array() ), 0, self::MAX_DOCUMENTS );

		$files = array();
		foreach ( $rows as $row ) {
			$bytes = self::pdf( (string) ( $row['type'] ?? '' ), (int) ( $row['id'] ?? 0 ), (int) $contact->id );
			if ( ! is_string( $bytes ) || '' === $bytes ) {
				continue;
			}
			$base = sanitize_file_name( (string) ( $row['number'] ?? '' ) );
			$base = '' !== $base ? $base : $row['type'] . '-' . (int) $row['id'];
			$name = $base . '.pdf';
			for ( $n = 2; isset( $files[ $name ] ); $n++ ) {
				$name = $base . '-' . $n . '.pdf';
			}
			$files[ $name ] = $bytes;
		}
		if ( empty( $files ) ) {
			return new WP_Error( 'no_documents', __( 'There are no documents to download.', 'doublescale' ), array( 'status' => 404 ) );
		}

		// Not wp_tempnam(): it lives in wp-admin and the portal is not admin.
		$path = tempnam( get_temp_dir(), 'ds-documents' );
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_failed', __( 'The download could not be made. Please try again.', 'doublescale' ), array( 'status' => 500 ) );
		}
		foreach ( $files as $name => $bytes ) {
			$zip->addFromString( $name, $bytes );
		}
		$zip->close();
		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $path );

		return $bytes;
	}

	/**
	 * One document's PDF (the client's own only).
	 *
	 * @param string $type       Row type.
	 * @param int    $id         Document id.
	 * @param int    $contact_id Client.
	 * @return string|null
	 */
	private static function pdf( string $type, int $id, int $contact_id ): ?string {
		if ( 'invoice' === $type ) {
			$invoice = InvoiceModel::find( $id );
			if ( ! $invoice || ! \DoubleScale\Modules\Portal\Services\PortalScope::owns( $invoice, $contact_id, 'invoices' ) ) {
				return null;
			}
			$invoice->load( array( 'contact' ) );
			$bytes = DocumentPdf::render_pdf( InvoiceShaper::shape_public( $invoice ), 'invoice' );
			return is_string( $bytes ) ? $bytes : null;
		}
		if ( 'proposal' === $type ) {
			$proposal = ProposalModel::find( $id );
			if ( ! $proposal || ! \DoubleScale\Modules\Portal\Services\PortalScope::owns( $proposal, $contact_id, 'invoices' ) ) {
				return null;
			}
			$bytes = DocumentPdf::render_pdf( ProposalShaper::shape_public( $proposal ), 'proposal' );
			return is_string( $bytes ) ? $bytes : null;
		}

		/**
		 * A PDF for another document type of the client's (Pro: credit
		 * notes, contracts). Return the bytes, or null.
		 *
		 * @param string|null $bytes      PDF bytes.
		 * @param string      $type       Row type.
		 * @param int         $id         Document id.
		 * @param int         $contact_id Client.
		 */
		$bytes = apply_filters( 'doublescale_portal_document_pdf', null, $type, $id, $contact_id );
		return is_string( $bytes ) ? $bytes : null;
	}
}
