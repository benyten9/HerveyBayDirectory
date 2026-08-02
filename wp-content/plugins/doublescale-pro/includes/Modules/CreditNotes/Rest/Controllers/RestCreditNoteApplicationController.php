<?php
/**
 * REST controller for credit note applications.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteApplicationModel;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use DoubleScale\Pro\Modules\CreditNotes\Rest\CreditNoteShaper;
use DoubleScale\Pro\Modules\CreditNotes\Services\CreditNoteApplications;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestCreditNoteApplicationController class.
 */
class RestCreditNoteApplicationController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/credit-notes';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<credit_note_id>[\d]+)/applications',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<credit_note_id>[\d]+)/applications/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);
	}

	public function get_items_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales();
	}

	public function create_item_permissions_check( $request ) {
		return $this->update_item_permissions_check( $request );
	}

	public function delete_item_permissions_check( $request ) {
		return $this->update_item_permissions_check( $request );
	}

	public function update_item_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales()
			&& ( Capabilities::can_manage_all_sales() || Capabilities::current_user_can( 'doublescale_manage_own_sales' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$credit_note = $this->resolve_credit_note( $request );
		if ( is_wp_error( $credit_note ) ) {
			return $credit_note;
		}

		$applications = CreditNoteApplicationModel::query()
			->with( array( 'invoice', 'applied_by' ) )
			->where( 'credit_note_id', (int) $credit_note->id )
			->orderBy( 'applied_date', 'desc' )
			->orderBy( 'id', 'desc' )
			->get();

		$data = array();
		foreach ( $applications as $application ) {
			$data[] = CreditNoteShaper::shape_application( $application, true );
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$credit_note = $this->resolve_credit_note( $request );
		if ( is_wp_error( $credit_note ) ) {
			return $credit_note;
		}

		$gate = apply_filters( 'doublescale_sales_credit_apply_gate', null, 'credit_note', $credit_note );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$invoice_id = isset( $params['invoice_id'] ) ? (int) $params['invoice_id'] : 0;
		if ( $invoice_id <= 0 ) {
			return new WP_Error( 'invalid_data', __( 'Invoice is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$invoice = InvoiceModel::find( $invoice_id );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$amount = isset( $params['amount'] ) ? (float) $params['amount'] : 0;
		$note   = isset( $params['note'] ) ? sanitize_textarea_field( (string) $params['note'] ) : null;

		$result = ( new CreditNoteApplications() )->apply( $credit_note, $invoice, $amount, $note );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$credit_note = $result['credit_note']->load( array( 'contact', 'sale_agent', 'invoice', 'applications.invoice' ) );
		$invoice     = $result['invoice']->load( array( 'contact', 'sale_agent' ) );

		return new WP_REST_Response(
			array(
				'credit_note' => CreditNoteShaper::shape( $credit_note, true ),
				'invoice'     => InvoiceShaper::shape( $invoice, true ),
			),
			201
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$credit_note = $this->resolve_credit_note( $request );
		if ( is_wp_error( $credit_note ) ) {
			return $credit_note;
		}

		$application = CreditNoteApplicationModel::query()
			->where( 'credit_note_id', (int) $credit_note->id )
			->where( 'id', (int) $request->get_param( 'id' ) )
			->first();

		if ( ! $application ) {
			return new WP_Error( 'not_found', __( 'Application not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$result = ( new CreditNoteApplications() )->revoke( $application );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$credit_note = $result['credit_note']->load( array( 'contact', 'sale_agent', 'invoice', 'applications.invoice' ) );
		$invoice     = $result['invoice']->load( array( 'contact', 'sale_agent' ) );

		return new WP_REST_Response(
			array(
				'deleted'     => true,
				'credit_note' => CreditNoteShaper::shape( $credit_note, true ),
				'invoice'     => InvoiceShaper::shape( $invoice, true ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return CreditNoteModel|WP_Error
	 */
	private function resolve_credit_note( WP_REST_Request $request ) {
		$credit_note = CreditNoteModel::find( (int) $request->get_param( 'credit_note_id' ) );
		if ( ! $credit_note ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! Capabilities::user_can_manage_record( get_current_user_id(), $credit_note->sale_agent_user_id ? (int) $credit_note->sale_agent_user_id : null ) ) {
			return new WP_Error( 'not_allowed', __( 'You do not have permission to access this credit note.', 'doublescale' ), array( 'status' => 403 ) );
		}

		return $credit_note;
	}
}
