<?php
/**
 * Client Portal documents endpoint.
 *
 *   GET /doublescale/v1/portal/documents?type=invoice|proposal|contract|all
 *
 * Lists the logged-in customer's own non-draft invoices and proposals. Reuses
 * {@see PortalIdentity} for the login + lowercased-email contact resolve and
 * gates every row on `contact_id`. Drafts are excluded; agent identity and
 * internal ids are never exposed (the list shape is built from the customer-safe
 * static helpers, not the admin shapers).
 *
 * Phase 1 is link-out: each row carries the existing public hash-page URL
 * (`public_url`) for view / pay (Pro) / accept / decline / sign / PDF — the
 * portal does not reimplement those flows.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Rest\ProposalShaper;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Modules\Documents\Services\ProposalUrl;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalDocumentsController.
 */
class RestPortalDocumentsController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Max rows returned across both document types.
	 */
	private const LIST_CAP = 100;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/documents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_documents' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => array(
						'type' => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'invoice', 'proposal', 'contract', 'credit_note' ),
							'default' => 'all',
						),
					),
				),
			)
		);
	}

	/**
	 * List the contact's non-draft documents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_documents( WP_REST_Request $request ) {
		$gate = $this->documents_gate();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_REST_Response( array( 'data' => array() ), 200 );
		}

		$type = (string) $request->get_param( 'type' );
		$rows = array();

		if ( in_array( $type, array( 'all', 'invoice' ), true ) && doublescale_sales_child_module_active( 'documents' ) ) {
			$invoices = InvoiceModel::where( 'contact_id', (int) $contact->id )
				->where( 'status', '!=', InvoiceStatus::DRAFT )
				->orderBy( 'id', 'desc' )
				->limit( self::LIST_CAP )
				->get();
			foreach ( $invoices as $invoice ) {
				$rows[] = $this->shape_invoice( $invoice );
			}
		}

		if ( in_array( $type, array( 'all', 'proposal' ), true ) && doublescale_sales_child_module_active( 'documents' ) ) {
			$proposals = ProposalModel::where( 'contact_id', (int) $contact->id )
				->where( 'status', '!=', ProposalStatus::DRAFT )
				->orderBy( 'id', 'desc' )
				->limit( self::LIST_CAP )
				->get();
			foreach ( $proposals as $proposal ) {
				$rows[] = $this->shape_proposal( $proposal );
			}
		}

		// Sort the merged list newest-first by created_at (a full datetime, unlike
		// the Y-m-d doc dates), then drop the private sort key and cap.
		/**
		 * Allow Pro modules to append customer-visible document rows before sort.
		 *
		 * @param array<int, array<string, mixed>> $rows    Merged rows with `_sort`.
		 * @param ContactModel                      $contact Resolved contact.
		 * @param string                            $type    Requested type filter.
		 */
		$rows = apply_filters( 'doublescale_portal_documents_rows', $rows, $contact, $type );

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) $b['_sort'], (string) $a['_sort'] );
			}
		);
		$rows = array_slice( $rows, 0, self::LIST_CAP );
		$data = array_map(
			static function ( $row ) {
				unset( $row['_sort'] );
				return $row;
			},
			$rows
		);

		return new WP_REST_Response( array( 'data' => array_values( $data ) ), 200 );
	}

	/**
	 * Documents-surface gate (404 when Sales/release-gated off), mirroring
	 * {@see RestController::require_module()}.
	 *
	 * @return WP_Error|null
	 */
	private function documents_gate(): ?WP_Error {
		if ( ! doublescale_sales_documents_ready() || ! doublescale_any_sales_document_module_active() ) {
			return new WP_Error(
				'module_disabled',
				__( 'The Documents area is unavailable.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}
		return null;
	}

	/**
	 * Customer-safe invoice list row.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return array<string, mixed>
	 */
	private function shape_invoice( InvoiceModel $invoice ): array {
		return array(
			'id'          => (int) $invoice->id,
			'type'        => 'invoice',
			'number'      => (string) $invoice->invoice_number,
			'subject'     => null,
			'status'      => (string) $invoice->status,
			'date'        => $invoice->invoice_date,
			'due_date'    => $invoice->due_date,
			'open_till'   => null,
			'currency'    => (string) $invoice->currency,
			'total'       => (float) $invoice->total,
			'amount_paid' => (float) $invoice->amount_paid,
			'balance'     => InvoiceShaper::balance( $invoice ),
			'is_overdue'  => InvoiceShaper::is_overdue( $invoice ),
			'is_expired'  => false,
			'invoice_id'  => null,
			// Raw hash drives the in-portal detail view (which mounts the public
			// invoice renderer); `public_url` already embeds the same hash.
			'hash'        => (string) $invoice->hash,
			'public_url'  => InvoiceUrl::get_public_url( $invoice ),
			'_sort'       => (string) $invoice->created_at,
		);
	}

	/**
	 * Customer-safe proposal list row.
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @return array<string, mixed>
	 */
	private function shape_proposal( ProposalModel $proposal ): array {
		return array(
			'id'          => (int) $proposal->id,
			'type'        => 'proposal',
			'number'      => (string) $proposal->proposal_number,
			'subject'     => (string) $proposal->subject,
			'status'      => (string) $proposal->status,
			'date'        => $proposal->date,
			'due_date'    => null,
			'open_till'   => $proposal->open_till,
			'currency'    => (string) $proposal->currency,
			'total'       => (float) $proposal->total,
			'amount_paid' => null,
			'balance'     => null,
			'is_overdue'  => false,
			'is_expired'  => ProposalShaper::is_expired( $proposal ),
			'invoice_id'  => ProposalShaper::get_linked_invoice_id( $proposal ),
			// Raw hash drives the in-portal detail view (mounts the public
			// proposal renderer for accept / decline / sign).
			'hash'        => (string) $proposal->hash,
			'public_url'  => ProposalUrl::get_public_url( $proposal ),
			'_sort'       => (string) $proposal->created_at,
		);
	}
}
