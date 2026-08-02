<?php
/**
 * REST controller for invoice payments.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Services\InvoicePayments;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestInvoicePaymentController class.
 */
class RestInvoicePaymentController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/invoices';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<invoice_id>[\d]+)/payments',
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
			'/' . $this->rest_base . '/(?P<invoice_id>[\d]+)/payments/(?P<id>[\d]+)',
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
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = $this->resolve_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$payments = PaymentModel::query()
			->with( 'recorded_by' )
			->where( 'invoice_id', (int) $invoice->id )
			->orderBy( 'payment_date', 'desc' )
			->orderBy( 'id', 'desc' )
			->get();

		$data = array();
		foreach ( $payments as $payment ) {
			$data[] = $this->shape_payment( $payment, true );
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = $this->resolve_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		if ( InvoiceStatus::DRAFT === (string) $invoice->status ) {
			return new WP_Error( 'invalid_status', __( 'Payments cannot be recorded on draft invoices.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$amount = isset( $params['amount'] ) ? (float) $params['amount'] : 0;
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_data', __( 'Payment amount must be greater than zero.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$balance = round( (float) $invoice->total - (float) $invoice->amount_paid, 2 );
		if ( $amount > $balance + 0.001 ) {
			return new WP_Error(
				'payment_exceeds_balance',
				__( 'Payment amount exceeds the balance due on this invoice.', 'doublescale' ),
				array( 'status' => 422 )
			);
		}

		$payment_date = current_time( 'Y-m-d' );
		if ( ! empty( $params['payment_date'] ) ) {
			$payment_date = sanitize_text_field( (string) $params['payment_date'] );
		}

		$payment = new PaymentModel();
		$payment->fill(
			array(
				'invoice_id'          => (int) $invoice->id,
				'amount'              => $amount,
				'payment_mode'        => isset( $params['payment_mode'] )
					? PaymentMode::normalize( (string) $params['payment_mode'] )
					: null,
				'payment_date'        => $payment_date,
				'transaction_id'      => isset( $params['transaction_id'] ) ? sanitize_text_field( (string) $params['transaction_id'] ) : null,
				'note'                => isset( $params['note'] ) ? sanitize_textarea_field( (string) $params['note'] ) : null,
				'recorded_by_user_id' => get_current_user_id(),
			)
		);
		$payment->save();

		$invoice = ( new InvoicePayments() )->sync( $invoice );
		$invoice->load( array( 'contact', 'sale_agent' ) );

		return new WP_REST_Response(
			array(
				'payment' => $this->shape_payment( $payment->fresh( 'recorded_by' ), true ),
				'invoice' => InvoiceShaper::shape( $invoice, true ),
			),
			201
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = $this->resolve_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$payment = PaymentModel::query()
			->where( 'invoice_id', (int) $invoice->id )
			->where( 'id', (int) $request->get_param( 'id' ) )
			->first();

		if ( ! $payment ) {
			return new WP_Error( 'not_found', __( 'Payment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$payment->delete();

		$invoice = ( new InvoicePayments() )->sync( $invoice );
		$invoice->load( array( 'contact', 'sale_agent' ) );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'invoice' => InvoiceShaper::shape( $invoice, true ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return InvoiceModel|WP_Error
	 */
	private function resolve_invoice( WP_REST_Request $request ) {
		$invoice = InvoiceModel::find( (int) $request->get_param( 'invoice_id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! Capabilities::user_can_manage_record( get_current_user_id(), $invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null ) ) {
			return new WP_Error( 'not_allowed', __( 'You do not have permission to access this invoice.', 'doublescale' ), array( 'status' => 403 ) );
		}

		return $invoice;
	}

	/**
	 * @param PaymentModel $payment Payment.
	 * @param bool         $with_relations Include relations.
	 * @return array
	 */
	private function shape_payment( PaymentModel $payment, bool $with_relations = false ): array {
		$data = array(
			'id'                  => (int) $payment->id,
			'invoice_id'          => (int) $payment->invoice_id,
			'amount'              => (float) $payment->amount,
			'payment_mode'        => $payment->payment_mode,
			'payment_date'        => $payment->payment_date,
			'transaction_id'      => $payment->transaction_id,
			'note'                => $payment->note,
			'recorded_by_user_id' => $payment->recorded_by_user_id ? (int) $payment->recorded_by_user_id : null,
			'created_at'          => $payment->created_at,
			'updated_at'          => $payment->updated_at,
		);

		if ( $with_relations ) {
			$user = $payment->relationLoaded( 'recorded_by' ) ? $payment->recorded_by : null;
			if ( $user ) {
				$data['recorded_by'] = array(
					'id'           => (int) $user->ID,
					'display_name' => (string) $user->display_name,
					'email'        => (string) $user->user_email,
				);
			}
		}

		return $data;
	}
}
