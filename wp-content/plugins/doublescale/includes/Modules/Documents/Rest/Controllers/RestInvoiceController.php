<?php
/**
 * REST controller for sales invoices.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Modules\Documents\Constants\DiscountType;
use DoubleScale\Modules\Documents\Constants\DocumentTemplate;
use DoubleScale\Modules\Documents\Constants\DocumentTemplateColor;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Services\DocumentPdf;
use DoubleScale\Modules\Documents\Services\DocumentCustomerDetails;
use DoubleScale\Modules\Documents\Services\DocumentIssuerSnapshot;
use DoubleScale\Modules\Documents\Services\DuplicateInvoice;
use DoubleScale\Modules\Documents\Services\InvoiceNotifications;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Modules\Sales\Services\SalesNumbering;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestInvoiceController class.
 */
class RestInvoiceController extends RestController {

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
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
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
			'/' . $this->rest_base . '/(?P<id>[\d]+)/send',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/pdf',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pdf' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'duplicate_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/status',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'change_status' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'status'     => array( 'type' => 'string' ),
			'contact_id' => array( 'type' => 'integer' ),
			'search'     => array( 'type' => 'string' ),
			'sort_by'    => array(
				'type'    => 'string',
				'enum'    => array( 'created_at', 'updated_at', 'invoice_date', 'due_date', 'total' ),
				'default' => 'created_at',
			),
			'sort_order' => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
			'per_page'   => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
			'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
		);
	}

	public function get_items_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales();
	}

	public function get_item_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales();
	}

	public function create_item_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales()
			&& (
				Capabilities::can_manage_all_sales()
				|| Capabilities::current_user_can( 'doublescale_manage_own_sales' )
				|| Capabilities::can_assign_sales_rep()
			);
	}

	public function update_item_permissions_check( $request ) {
		return $this->create_item_permissions_check( $request );
	}

	public function delete_item_permissions_check( $request ) {
		return $this->create_item_permissions_check( $request );
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

		$query = InvoiceModel::query()->with( array( 'contact', 'sale_agent', 'proposal' ) );
		$this->apply_filters( $query, $request );

		$per_page  = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$paginator = $query->paginate( $per_page, array( '*' ), 'page', $page );

		$data = array();
		foreach ( $paginator->items() as $invoice ) {
			$data[] = InvoiceShaper::shape( $invoice, true );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'        => $paginator->total(),
					'per_page'     => $per_page,
					'current_page' => $page,
					'last_page'    => max( 1, (int) ceil( $paginator->total() / $per_page ) ),
				),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$query = InvoiceModel::query();
		if ( ! Capabilities::can_manage_all_sales() && ! Capabilities::can_assign_sales_rep() ) {
			$query->where( 'sale_agent_user_id', get_current_user_id() );
		}

		$total_count = (int) ( clone $query )->count();
		$statuses    = InvoiceStatus::all();
		$by_status   = array();

		foreach ( $statuses as $status ) {
			$count = (int) ( clone $query )->where( 'status', $status )->count();
			$sum   = (float) ( clone $query )->where( 'status', $status )->sum( 'total' );
			$by_status[ $status ] = array(
				'count'  => $count,
				'amount' => round( $sum, 2 ),
				'percent' => $total_count > 0 ? round( ( $count / $total_count ) * 100, 2 ) : 0,
			);
		}

		// Break each total down by the RESOLVED currency (global for drafts, frozen
		// for sent) so reports stay consistent when older documents used a different
		// currency. The flat *_total is kept for backward compatibility.
		$paid        = $this->sum_by_currency( clone $query, array( InvoiceStatus::PAID ) );
		$outstanding = $this->sum_by_currency( clone $query, array( InvoiceStatus::UNPAID, InvoiceStatus::PARTIALLY_PAID ) );
		$overdue     = $this->sum_by_currency( clone $query, array( InvoiceStatus::OVERDUE ) );

		return new WP_REST_Response(
			array(
				'paid_total'              => $paid['total'],
				'outstanding_total'       => $outstanding['total'],
				'overdue_total'           => $overdue['total'],
				'paid_by_currency'        => $paid['by_currency'],
				'outstanding_by_currency' => $outstanding['by_currency'],
				'overdue_by_currency'     => $overdue['by_currency'],
				'by_status'               => $by_status,
				'total_count'             => $total_count,
			),
			200
		);
	}

	/**
	 * Sum invoice totals for the given statuses, grouped by the resolved currency.
	 *
	 * @param mixed    $query    Invoice query (already scoped to the caller).
	 * @param string[] $statuses Statuses to include.
	 * @return array{total: float, by_currency: array<string, float>}
	 */
	private function sum_by_currency( $query, array $statuses ): array {
		$total       = 0.0;
		$by_currency = array();

		foreach ( $query->whereIn( 'status', $statuses )->get() as $invoice ) {
			$amount = (float) $invoice->total;
			if ( 0.0 === $amount ) {
				continue;
			}
			$currency = \DoubleScale\Core\Settings\Settings::document_currency( $invoice->currency, $invoice->sent_at );
			$total   += $amount;
			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = 0.0;
			}
			$by_currency[ $currency ] += $amount;
		}

		foreach ( $by_currency as $code => $value ) {
			$by_currency[ $code ] = round( (float) $value, 2 );
		}

		return array(
			'total'       => round( $total, 2 ),
			'by_currency' => $by_currency,
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = InvoiceModel::with( array( 'contact', 'sale_agent', 'proposal' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $invoice );
		if ( $forbidden ) {
			return $forbidden;
		}

		return new WP_REST_Response( InvoiceShaper::shape( $invoice, true ), 200 );
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

		$payload = $this->sanitize_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( ! isset( $payload['template'] ) ) {
			$payload['template'] = DocumentTemplate::normalize(
				SalesSettings::get( 'default_invoice_template', DocumentTemplate::DEFAULT )
			);
		}

		$discount_check = DiscountType::validate_payload( $payload );
		if ( is_wp_error( $discount_check ) ) {
			return $discount_check;
		}

		if ( ! Capabilities::can_assign_sales_rep() ) {
			$payload['sale_agent_user_id'] = get_current_user_id();
		}

		$invoice = new InvoiceModel();
		$invoice->fill( $payload );
		SalesNumbering::save_with_retry( $invoice );

		return new WP_REST_Response( InvoiceShaper::shape( $invoice->fresh( array( 'contact', 'sale_agent', 'proposal' ) ), true ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = InvoiceModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $invoice );
		if ( $forbidden ) {
			return $forbidden;
		}

		$gate = apply_filters( 'doublescale_sales_update_gate', null, 'invoice', $invoice );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$payload = $this->sanitize_payload( $request, false );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$discount_check = DiscountType::validate_payload( $payload, $invoice );
		if ( is_wp_error( $discount_check ) ) {
			return $discount_check;
		}

		if ( ! Capabilities::can_assign_sales_rep() && isset( $payload['sale_agent_user_id'] ) ) {
			unset( $payload['sale_agent_user_id'] );
		}

		$invoice->fill( $payload );
		$invoice->save();

		do_action( 'doublescale_sales_invoice_updated', $invoice );

		return new WP_REST_Response( InvoiceShaper::shape( $invoice->fresh( array( 'contact', 'sale_agent', 'proposal' ) ), true ), 200 );
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

		$invoice = InvoiceModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $invoice );
		if ( $forbidden ) {
			return $forbidden;
		}

		do_action( 'doublescale_sales_invoice_deleted', $invoice );

		$invoice->delete();

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_item( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = InvoiceModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $invoice );
		if ( $forbidden ) {
			return $forbidden;
		}

		$copy = ( new DuplicateInvoice() )->duplicate( $invoice );

		return new WP_REST_Response( InvoiceShaper::shape( $copy, true ), 201 );
	}

	/**
	 * Change an invoice status manually from the admin.
	 *
	 * Paid states are rejected on purpose: `amount_paid` is the source of truth
	 * for payment state, so flipping the status here would desync totals and
	 * reporting. Payments must go through the payments flow.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_status( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = InvoiceModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $invoice );
		if ( $forbidden ) {
			return $forbidden;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$status = isset( $params['status'] ) ? sanitize_text_field( (string) $params['status'] ) : '';
		if ( ! InvoiceStatus::is_valid( $status ) ) {
			return new WP_Error( 'invalid_status', __( 'Invalid invoice status.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( in_array( $status, array( InvoiceStatus::PAID, InvoiceStatus::PARTIALLY_PAID ), true ) ) {
			return new WP_Error(
				'invalid_status',
				__( 'Paid statuses are derived from recorded payments. Record a payment on the invoice instead.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$previous = (string) $invoice->status;

		if ( $previous === $status ) {
			return new WP_REST_Response( InvoiceShaper::shape( $invoice->fresh( array( 'contact', 'sale_agent', 'proposal' ) ), true ), 200 );
		}

		$invoice->status = $status;
		$invoice->save();

		$this->log_manual_status_change( $invoice, $previous, $status );

		do_action( 'doublescale_sales_invoice_updated', $invoice );

		return new WP_REST_Response( InvoiceShaper::shape( $invoice->fresh( array( 'contact', 'sale_agent', 'proposal' ) ), true ), 200 );
	}

	/**
	 * Record an admin-driven status change on the activity timeline.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $previous Previous status.
	 * @param string       $status New status.
	 * @return void
	 */
	private function log_manual_status_change( InvoiceModel $invoice, string $previous, string $status ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		$note = sprintf(
			/* translators: 1: invoice number, 2: previous status label, 3: new status label */
			__( 'Invoice %1$s status changed manually from %2$s to %3$s.', 'doublescale' ),
			(string) $invoice->invoice_number,
			InvoiceStatus::get_label( $previous ),
			InvoiceStatus::get_label( $status )
		);

		ActivityModel::create(
			array(
				'contact_id'    => (int) $invoice->contact_id,
				'activity_type' => ActivityTypes::STATUS_CHANGED,
				'data'          => array(
					'title'           => __( 'Invoice updated', 'doublescale' ),
					'type'            => 'system',
					'note'            => $note,
					'invoice_id'      => (int) $invoice->id,
					'status'          => $status,
					'previous_status' => $previous,
					'manual'          => true,
				),
				'user_id'       => get_current_user_id() ?: null,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_item( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = InvoiceModel::with( array( 'contact', 'sale_agent', 'proposal' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $invoice );
		if ( $forbidden ) {
			return $forbidden;
		}

		if ( InvoiceStatus::PAID === (string) $invoice->status ) {
			return new WP_Error( 'invalid_status', __( 'Paid invoices cannot be sent.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( '' === InvoiceUrl::get_page_url() ) {
			return new WP_Error(
				'no_invoice_page',
				__( 'Create a WordPress page with the [doublescale_invoice] shortcode before sending invoices.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$gate = apply_filters( 'doublescale_sales_send_gate', null, 'invoice', $invoice );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$message = isset( $params['message'] ) ? sanitize_textarea_field( (string) $params['message'] ) : '';

		$notifier = new InvoiceNotifications();
		if ( ! $notifier->send_invoice( $invoice, $message ) ) {
			return new WP_Error(
				'email_failed',
				__( 'Failed to send the invoice email. Check the customer email and SMTP settings.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		if ( InvoiceStatus::DRAFT === (string) $invoice->status ) {
			$invoice->status = InvoiceStatus::UNPAID;
		}
		DocumentCustomerDetails::snapshot_billing_from_contact( $invoice );
		DocumentIssuerSnapshot::freeze_if_needed( $invoice );
		$invoice->sent_at = current_time( 'mysql' );
		$invoice->save();

		$this->log_invoice_sent( $invoice, $message );

		do_action( 'doublescale_sales_invoice_sent', $invoice, $message );

		return new WP_REST_Response(
			array(
				'sent'    => true,
				'invoice' => InvoiceShaper::shape( $invoice->fresh( array( 'contact', 'sale_agent', 'proposal' ) ), true ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pdf( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = InvoiceModel::with( array( 'contact', 'sale_agent', 'proposal' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $invoice );
		if ( $forbidden ) {
			return $forbidden;
		}

		return $this->stream_pdf_response( InvoiceShaper::shape( $invoice, true ), 'invoice', (string) $invoice->invoice_number );
	}

	/**
	 * @param array<string, mixed> $shaped Shaped document data.
	 * @param string               $type Document type (invoice|proposal).
	 * @param string               $filename Base filename without extension.
	 * @return WP_REST_Response|WP_Error
	 */
	private function stream_pdf_response( array $shaped, string $type, string $filename ) {
		return DocumentPdf::rest_response( $shaped, $type, $filename );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $message Optional custom message.
	 * @return void
	 */
	private function log_invoice_sent( InvoiceModel $invoice, string $message = '' ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		$note = sprintf(
			/* translators: %s: invoice number */
			__( 'Invoice %s sent to customer.', 'doublescale' ),
			(string) $invoice->invoice_number
		);
		if ( '' !== trim( $message ) ) {
			$note .= ' — ' . $message;
		}

		ActivityModel::create(
			array(
				'contact_id'    => (int) $invoice->contact_id,
				'activity_type' => ActivityTypes::EMAIL_SENT,
				'data'          => array(
					'title'      => __( 'Invoice sent', 'doublescale' ),
					'type'       => 'system',
					'note'       => $note,
					'invoice_id' => (int) $invoice->id,
				),
				'user_id'       => get_current_user_id() ?: null,
			)
		);
		// TODO(morph): wire proposal/invoice associations (ENTITY_TYPE_INVOICE).
	}

	/**
	 * @param \Illuminate\Database\Eloquent\Builder $query Query.
	 * @param WP_REST_Request                       $request Request.
	 * @return void
	 */
	private function apply_filters( $query, WP_REST_Request $request ): void {
		$status = $request->get_param( 'status' );
		if ( null !== $status && '' !== $status ) {
			$statuses = array_values( array_filter( array_map( 'trim', explode( ',', (string) $status ) ) ) );
			$valid    = array_values( array_intersect( $statuses, InvoiceStatus::all() ) );
			if ( ! empty( $valid ) ) {
				$query->whereIn( 'status', $valid );
			}
		}

		$contact_id = $request->get_param( 'contact_id' );
		if ( null !== $contact_id && '' !== $contact_id ) {
			$query->where( 'contact_id', (int) $contact_id );
		}

		if ( ! Capabilities::can_manage_all_sales() && ! Capabilities::can_assign_sales_rep() ) {
			$query->where( 'sale_agent_user_id', get_current_user_id() );
		}

		$search = $request->get_param( 'search' );
		if ( is_string( $search ) && '' !== trim( $search ) ) {
			$like = '%' . str_replace( array( '%', '_' ), array( '\\%', '\\_' ), trim( $search ) ) . '%';
			$query->where(
				function ( $q ) use ( $like ) {
					$q->where( 'invoice_number', 'LIKE', $like );
				}
			);
		}

		$sort_by    = in_array( $request->get_param( 'sort_by' ), array( 'created_at', 'updated_at', 'invoice_date', 'due_date', 'total' ), true )
			? $request->get_param( 'sort_by' )
			: 'created_at';
		$sort_order = 'asc' === $request->get_param( 'sort_order' ) ? 'asc' : 'desc';
		$query->orderBy( $sort_by, $sort_order );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return WP_Error|null
	 */
	private function require_ownership( InvoiceModel $invoice ) {
		if ( Capabilities::user_can_manage_record( get_current_user_id(), $invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null ) ) {
			return null;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to access this invoice.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param bool            $require_contact Whether contact_id is required.
	 * @return array|WP_Error
	 */
	private function sanitize_payload( WP_REST_Request $request, bool $require_contact = true ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$payload = array();

		$string_fields = array(
			'status',
			'currency',
			'discount_type',
			'invoice_date',
			'due_date',
			'billing_address',
			'shipping_address',
			'client_note',
			'terms',
		);

		foreach ( $string_fields as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				if ( in_array( $field, array( 'billing_address', 'shipping_address', 'client_note', 'terms' ), true ) ) {
					$payload[ $field ] = sanitize_textarea_field( (string) $params[ $field ] );
				} else {
					$payload[ $field ] = sanitize_text_field( (string) $params[ $field ] );
				}
			}
		}

		if ( array_key_exists( 'contact_id', $params ) ) {
			$payload['contact_id'] = (int) $params['contact_id'];
		} elseif ( $require_contact ) {
			return new WP_Error( 'invalid_data', __( 'Customer is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'sale_agent_user_id', $params ) ) {
			$payload['sale_agent_user_id'] = (int) $params['sale_agent_user_id'] ?: null;
		}

		foreach ( array( 'discount_value', 'adjustment' ) as $float_field ) {
			if ( array_key_exists( $float_field, $params ) ) {
				$payload[ $float_field ] = (float) $params[ $float_field ];
			}
		}

		if ( array_key_exists( 'allowed_payment_modes', $params ) ) {
			$payload['allowed_payment_modes'] = PaymentMode::normalize_list( $params['allowed_payment_modes'] );
		}
		if ( array_key_exists( 'line_items', $params ) && is_array( $params['line_items'] ) ) {
			$payload['line_items'] = $params['line_items'];
		}

		if ( array_key_exists( 'template', $params ) ) {
			$payload['template'] = DocumentTemplate::normalize( $params['template'] );
		}

		if ( array_key_exists( 'template_color', $params ) ) {
			$payload['template_color'] = DocumentTemplateColor::normalize( $params['template_color'] );
		}

		if ( isset( $payload['status'] ) && ! InvoiceStatus::is_valid( $payload['status'] ) ) {
			return new WP_Error( 'invalid_status', __( 'Invalid invoice status.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'invoice_number', $params ) ) {
			$number = SalesNumbering::validate_manual_number(
				sanitize_text_field( (string) $params['invoice_number'] ),
				InvoiceModel::class,
				'invoice_number',
				(int) $request->get_param( 'id' )
			);
			if ( is_wp_error( $number ) ) {
				return $number;
			}
			// An empty value leaves auto-numbering to the model's creating hook.
			if ( '' !== $number ) {
				$payload['invoice_number'] = $number;
			}
		}

		return $payload;
	}

}
