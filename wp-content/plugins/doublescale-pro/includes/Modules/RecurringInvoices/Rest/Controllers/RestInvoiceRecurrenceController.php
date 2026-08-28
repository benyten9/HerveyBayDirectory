<?php
/**
 * REST controller for invoice recurrence rules.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\RecurringInvoices\Models\InvoiceRecurrenceModel;
use DoubleScale\Pro\Modules\RecurringInvoices\Rest\InvoiceRecurrenceShaper;
use DoubleScale\Pro\Modules\RecurringInvoices\Services\SaveInvoiceRecurrence;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestInvoiceRecurrenceController class.
 */
class RestInvoiceRecurrenceController extends RestController {

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
			'/' . $this->rest_base . '/(?P<invoice_id>[\d]+)/recurrence',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales()
			&& ( Capabilities::can_manage_all_sales() || Capabilities::current_user_can( 'doublescale_manage_own_sales' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$invoice = $this->resolve_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$recurrence = InvoiceRecurrenceModel::where( 'template_invoice_id', (int) $invoice->id )->first();

		return new WP_REST_Response(
			array( 'data' => $recurrence ? InvoiceRecurrenceShaper::shape( $recurrence ) : null ),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_item( $request ) {
		$invoice = $this->resolve_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$payload = $this->sanitize_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$recurrence = ( new SaveInvoiceRecurrence() )->save( $invoice, $payload );

		return new WP_REST_Response(
			array( 'data' => $recurrence ? InvoiceRecurrenceShaper::shape( $recurrence ) : null ),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$invoice = $this->resolve_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		( new SaveInvoiceRecurrence() )->save( $invoice, null );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Load the template invoice, enforcing module gate and owner scoping.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return InvoiceModel|WP_Error
	 */
	private function resolve_invoice( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'recurring_invoices' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = InvoiceModel::find( (int) $request->get_param( 'invoice_id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! Capabilities::user_can_manage_record(
			get_current_user_id(),
			$invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null
		) ) {
			return new WP_Error(
				'not_allowed',
				__( 'You do not have permission to access this invoice.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return $invoice;
	}

	/**
	 * Validate the recurrence payload.
	 *
	 * Returns null when recurrence is being switched off, which the save
	 * service treats as "remove the rule".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|null|WP_Error
	 */
	private function sanitize_payload( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		// An explicit "off" switch, or simply no cadence supplied.
		if ( array_key_exists( 'enabled', $params ) && ! $params['enabled'] ) {
			return null;
		}

		if ( ! isset( $params['interval_value'] ) && ! isset( $params['interval_unit'] ) ) {
			return null;
		}

		$value = (int) ( $params['interval_value'] ?? 1 );
		if ( $value < 1 ) {
			return new WP_Error(
				'invalid_interval',
				__( 'Recurrence interval must be at least 1.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$unit_raw = isset( $params['interval_unit'] ) ? (string) $params['interval_unit'] : 'month';
		$unit     = InvoiceRecurrenceModel::normalize_unit( $unit_raw );

		$end_date = null;
		if ( ! empty( $params['end_date'] ) ) {
			$end_date = sanitize_text_field( (string) $params['end_date'] );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
				return new WP_Error(
					'invalid_end_date',
					__( 'End date must be in YYYY-MM-DD format.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
		}

		$total_cycles = isset( $params['total_cycles'] ) ? (int) $params['total_cycles'] : 0;
		if ( $total_cycles < 0 ) {
			return new WP_Error(
				'invalid_total_cycles',
				__( 'Total cycles cannot be negative.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'interval_value' => $value,
			'interval_unit'  => $unit,
			'total_cycles'   => $total_cycles,
			'is_infinite'    => array_key_exists( 'is_infinite', $params )
				? (bool) $params['is_infinite']
				: ( 0 === $total_cycles ),
			'end_date'       => $end_date,
			'auto_send'      => ! empty( $params['auto_send'] ),
			'require_paid'   => ! empty( $params['require_paid'] ),
		);
	}
}
