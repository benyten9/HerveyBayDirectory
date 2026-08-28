<?php
/**
 * REST controller for sales credit notes.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Services\CurrencyResolver;
use DoubleScale\Core\Services\DocumentCurrency;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Modules\Sales\Rest\SendsDocumentViaWhatsapp;
use DoubleScale\Modules\Sales\Services\SalesNumbering;
use DoubleScale\Modules\Documents\Constants\DiscountType;
use DoubleScale\Modules\Documents\Services\DocumentCustomerDetails;
use DoubleScale\Modules\Documents\Services\DocumentIssuerSnapshot;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use DoubleScale\Pro\Modules\CreditNotes\Rest\CreditNoteShaper;
use DoubleScale\Pro\Modules\CreditNotes\Services\CreditNoteApplications;
use DoubleScale\Pro\Modules\CreditNotes\Services\CreditNoteNotifications;
use DoubleScale\Pro\Modules\CreditNotes\Services\CreditNotePdf;
use DoubleScale\Pro\Modules\CreditNotes\Services\CreditNoteUrl;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestCreditNoteController class.
 */
class RestCreditNoteController extends RestController {

	use SendsDocumentViaWhatsapp;

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
			'/' . $this->rest_base . '/(?P<id>[\d]+)/send-whatsapp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_item_whatsapp' ),
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
				'enum'    => array( 'created_at', 'updated_at', 'credit_note_date', 'total' ),
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
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$query = CreditNoteModel::query()->with( array( 'contact', 'sale_agent', 'invoice' ) );
		$this->apply_filters( $query, $request );

		$per_page  = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$paginator = $query->paginate( $per_page, array( '*' ), 'page', $page );

		$data = array();
		foreach ( $paginator->items() as $credit_note ) {
			$data[] = CreditNoteShaper::shape( $credit_note, true );
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
		unset( $request );
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$query = CreditNoteModel::query();
		if ( ! Capabilities::can_manage_all_sales() && ! Capabilities::can_assign_sales_rep() ) {
			$query->where( 'sale_agent_user_id', get_current_user_id() );
		}

		$total_count = (int) ( clone $query )->count();
		$by_status   = array();

		foreach ( CreditNoteStatus::all() as $status ) {
			$count  = (int) ( clone $query )->where( 'status', $status )->count();
			$totals = $this->sum_by_currency( clone $query, array( $status ) );
			$by_status[ $status ] = array(
				'count'              => $count,
				'amount'             => $totals['total'],
				'amount_by_currency' => $totals['by_currency'],
				'percent'            => $total_count > 0 ? round( ( $count / $total_count ) * 100, 2 ) : 0,
			);
		}

		$credited = $this->sum_by_currency(
			clone $query,
			array_values(
				array_diff(
					CreditNoteStatus::all(),
					array( CreditNoteStatus::DRAFT, CreditNoteStatus::VOID )
				)
			)
		);

		$open_by_currency = array();
		$open_rows        = ( clone $query )
			->whereIn( 'status', array( CreditNoteStatus::OPEN, CreditNoteStatus::PARTIALLY_APPLIED ) )
			->get();
		foreach ( $open_rows as $row ) {
			$remaining = max( 0, (float) $row->total - (float) $row->amount_applied );
			if ( 0.0 === $remaining ) {
				continue;
			}
			$code = CurrencyResolver::resolve( $row );
			if ( ! isset( $open_by_currency[ $code ] ) ) {
				$open_by_currency[ $code ] = 0.0;
			}
			$open_by_currency[ $code ] += $remaining;
		}
		$open_by_currency = CurrencyResolver::round_map( $open_by_currency );
		$global           = CurrencyResolver::global_currency();

		return new WP_REST_Response(
			array(
				'total_credited'             => $credited['total'],
				'total_credited_by_currency' => $credited['by_currency'],
				'open_credit'                => $open_by_currency[ $global ] ?? 0.0,
				'open_credit_by_currency'    => $open_by_currency,
				'by_status'                  => $by_status,
				'total_count'                => $total_count,
			),
			200
		);
	}

	/**
	 * @param mixed    $query    Credit-note query (already scoped).
	 * @param string[] $statuses Statuses to include.
	 * @return array{total: float, by_currency: array<string, float>}
	 */
	private function sum_by_currency( $query, array $statuses ): array {
		$by_currency = CurrencyResolver::sum_by_currency( $query->whereIn( 'status', $statuses )->get(), 'total' );
		$global      = CurrencyResolver::global_currency();

		return array(
			'total'       => $by_currency[ $global ] ?? 0.0,
			'by_currency' => $by_currency,
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$credit_note = CreditNoteModel::with( array( 'contact', 'sale_agent', 'invoice', 'applications.invoice' ) )
			->find( (int) $request->get_param( 'id' ) );
		if ( ! $credit_note ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $credit_note );
		if ( $forbidden ) {
			return $forbidden;
		}

		return new WP_REST_Response( CreditNoteShaper::shape( $credit_note, true ), 200 );
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

		$payload = $this->sanitize_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$discount_check = DiscountType::validate_payload( $payload );
		if ( is_wp_error( $discount_check ) ) {
			return $discount_check;
		}

		if ( ! Capabilities::can_assign_sales_rep() ) {
			$payload['sale_agent_user_id'] = get_current_user_id();
		}

		$credit_note = new CreditNoteModel();
		$credit_note->fill( $payload );
		SalesNumbering::save_with_retry( $credit_note );

		return new WP_REST_Response(
			CreditNoteShaper::shape( $credit_note->fresh( array( 'contact', 'sale_agent', 'invoice' ) ), true ),
			201
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$credit_note = CreditNoteModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $credit_note ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $credit_note );
		if ( $forbidden ) {
			return $forbidden;
		}

		$gate = apply_filters( 'doublescale_sales_update_gate', null, 'credit_note', $credit_note );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$payload = $this->sanitize_payload( $request, false );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( array_key_exists( 'currency', $payload ) ) {
			// Applied credit is settled value, so it locks the currency just as a
			// recorded payment locks an invoice's.
			$locked = DocumentCurrency::reject_if_locked( $credit_note, $payload['currency'], true );
			if ( is_wp_error( $locked ) ) {
				return $locked;
			}
		}

		$discount_check = DiscountType::validate_payload( $payload, $credit_note );
		if ( is_wp_error( $discount_check ) ) {
			return $discount_check;
		}

		if ( ! Capabilities::can_assign_sales_rep() && isset( $payload['sale_agent_user_id'] ) ) {
			unset( $payload['sale_agent_user_id'] );
		}

		$credit_note->fill( $payload );
		$credit_note->save();

		do_action( 'doublescale_sales_credit_note_updated', $credit_note );

		return new WP_REST_Response(
			CreditNoteShaper::shape( $credit_note->fresh( array( 'contact', 'sale_agent', 'invoice', 'applications.invoice' ) ), true ),
			200
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

		$credit_note = CreditNoteModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $credit_note ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $credit_note );
		if ( $forbidden ) {
			return $forbidden;
		}

		( new CreditNoteApplications() )->destroy_applications( $credit_note );

		do_action( 'doublescale_sales_credit_note_deleted', $credit_note );

		$credit_note->delete();

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_item( $request ) {
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$credit_note = CreditNoteModel::with( array( 'contact', 'sale_agent', 'invoice' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $credit_note ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $credit_note );
		if ( $forbidden ) {
			return $forbidden;
		}

		if ( '' === CreditNoteUrl::get_page_url() ) {
			return new WP_Error(
				'no_credit_note_page',
				__( 'Create a WordPress page with the [doublescale_credit_note] shortcode before sending credit notes.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$gate = apply_filters( 'doublescale_sales_send_gate', null, 'credit_note', $credit_note );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$message = isset( $params['message'] ) ? sanitize_textarea_field( (string) $params['message'] ) : '';

		$channel = isset( $params['channel'] ) ? sanitize_key( (string) $params['channel'] ) : 'email';

		// WhatsApp shares are delivered by the client opening wa.me; this call
		// only records that the send happened.
		if ( 'whatsapp' !== $channel ) {
			$notifier = new CreditNoteNotifications();
			if ( ! $notifier->send_credit_note( $credit_note, $message ) ) {
				return new WP_Error(
					'email_failed',
					__( 'Failed to send the credit note email. Check the customer email and SMTP settings.', 'doublescale' ),
					array( 'status' => 500 )
				);
			}
		}

		return $this->finish_credit_note_send( $credit_note, $message, $channel );
	}

	/**
	 * Prepare or perform a WhatsApp share for a credit note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_item_whatsapp( $request ) {
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$credit_note = CreditNoteModel::with( array( 'contact', 'sale_agent', 'invoice' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $credit_note ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $credit_note );
		if ( $forbidden ) {
			return $forbidden;
		}

		$no_page = $this->require_public_page(
			'credit_note',
			'no_credit_note_page',
			__( 'Create a WordPress page with the [doublescale_credit_note] shortcode before sending credit notes.', 'doublescale' )
		);
		if ( $no_page ) {
			return $no_page;
		}

		$gate = apply_filters( 'doublescale_sales_send_gate', null, 'credit_note', $credit_note );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$params  = $this->read_whatsapp_params( $request );
		$payload = $this->build_whatsapp_payload( $credit_note, 'credit_note', $params );

		if ( 'auto' !== $params['mode'] ) {
			return new WP_REST_Response( $this->whatsapp_link_response( $payload ), 200 );
		}

		$sent = $this->dispatch_whatsapp_auto( $credit_note, 'credit_note', $payload );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		return $this->finish_credit_note_send( $credit_note, $params['message'], 'whatsapp' );
	}

	/**
	 * Advance status and record the send, once a channel has delivered.
	 *
	 * @param CreditNoteModel $credit_note Credit note.
	 * @param string          $message     Custom message.
	 * @param string          $channel     Delivery channel.
	 * @return WP_REST_Response
	 */
	private function finish_credit_note_send( CreditNoteModel $credit_note, string $message, string $channel ): WP_REST_Response {
		if ( CreditNoteStatus::DRAFT === (string) $credit_note->status ) {
			$credit_note->status = CreditNoteStatus::OPEN;
		}
		DocumentCustomerDetails::snapshot_billing_from_contact( $credit_note );
		DocumentIssuerSnapshot::freeze_if_needed( $credit_note );
		DocumentCurrency::freeze_on_send( $credit_note );
		$credit_note->sent_at = current_time( 'mysql' );
		$credit_note->save();

		do_action( 'doublescale_sales_credit_note_sent', $credit_note, $message, $channel );

		return new WP_REST_Response(
			array(
				'sent'        => true,
				'credit_note' => CreditNoteShaper::shape( $credit_note->fresh( array( 'contact', 'sale_agent', 'invoice' ) ), true ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pdf( $request ) {
		$disabled = $this->require_module( 'credit_notes' );
		if ( $disabled ) {
			return $disabled;
		}

		$credit_note = CreditNoteModel::with( array( 'contact', 'sale_agent', 'invoice' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $credit_note ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ownership( $credit_note );
		if ( $forbidden ) {
			return $forbidden;
		}

		return CreditNotePdf::rest_response( CreditNoteShaper::shape( $credit_note, true ), (string) $credit_note->credit_note_number );
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
			$valid    = array_values( array_intersect( $statuses, CreditNoteStatus::all() ) );
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
					$q->where( 'credit_note_number', 'LIKE', $like );
				}
			);
		}

		$sort_by    = in_array( $request->get_param( 'sort_by' ), array( 'created_at', 'updated_at', 'credit_note_date', 'total' ), true )
			? $request->get_param( 'sort_by' )
			: 'created_at';
		$sort_order = 'asc' === $request->get_param( 'sort_order' ) ? 'asc' : 'desc';
		$query->orderBy( $sort_by, $sort_order );
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return WP_Error|null
	 */
	private function require_ownership( CreditNoteModel $credit_note ) {
		if ( Capabilities::user_can_manage_record( get_current_user_id(), $credit_note->sale_agent_user_id ? (int) $credit_note->sale_agent_user_id : null ) ) {
			return null;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to access this credit note.', 'doublescale' ), array( 'status' => 403 ) );
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
			'discount_type',
			'credit_note_date',
			'reason',
			'billing_address',
			'client_note',
			'terms',
		);

		foreach ( $string_fields as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				if ( in_array( $field, array( 'billing_address', 'client_note', 'terms' ), true ) ) {
					$payload[ $field ] = sanitize_textarea_field( (string) $params[ $field ] );
				} else {
					$payload[ $field ] = sanitize_text_field( (string) $params[ $field ] );
				}
			}
		}

		if ( array_key_exists( 'currency', $params ) ) {
			$currency = DocumentCurrency::sanitize_input( $params['currency'] );
			if ( is_wp_error( $currency ) ) {
				return $currency;
			}
			$payload['currency'] = $currency;
		}

		if ( array_key_exists( 'contact_id', $params ) ) {
			$payload['contact_id'] = (int) $params['contact_id'];
		} elseif ( $require_contact ) {
			return new WP_Error( 'invalid_data', __( 'Customer is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'invoice_id', $params ) ) {
			$payload['invoice_id'] = (int) $params['invoice_id'] ?: null;
		}

		if ( array_key_exists( 'sale_agent_user_id', $params ) ) {
			$payload['sale_agent_user_id'] = (int) $params['sale_agent_user_id'] ?: null;
		}

		foreach ( array( 'discount_value', 'adjustment' ) as $float_field ) {
			if ( array_key_exists( $float_field, $params ) ) {
				$payload[ $float_field ] = (float) $params[ $float_field ];
			}
		}

		if ( array_key_exists( 'line_items', $params ) && is_array( $params['line_items'] ) ) {
			$payload['line_items'] = $params['line_items'];
		}

		if ( isset( $payload['status'] ) && ! CreditNoteStatus::is_valid( $payload['status'] ) ) {
			return new WP_Error( 'invalid_status', __( 'Invalid credit note status.', 'doublescale' ), array( 'status' => 400 ) );
		}

		return $payload;
	}
}
