<?php
/**
 * REST controller for global invoice payments list.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Services\DocumentPdf;
use DoubleScale\Modules\Documents\Services\InvoicePayments;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPaymentController class.
 */
class RestPaymentController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/payments';

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
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
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
			'search'             => array( 'type' => 'string' ),
			'payment_mode'       => array( 'type' => 'string' ),
			'payment_date_from'  => array( 'type' => 'string' ),
			'payment_date_to'    => array( 'type' => 'string' ),
			'sort_by'            => array(
				'type'    => 'string',
				'enum'    => array( 'id', 'payment_date', 'amount', 'created_at' ),
				'default' => 'id',
			),
			'sort_order' => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
			'per_page'   => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		unset( $request );
		if ( Capabilities::is_sales_rep_only() ) {
			return false;
		}
		return Capabilities::can_view_sales()
			&& ( Capabilities::can_manage_all_sales() || Capabilities::current_user_can( 'doublescale_manage_own_sales' ) );
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

		$payment = $this->resolve_payment( $request );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		return new WP_REST_Response(
			array(
				'payment' => $this->shape_payment( $payment, true ),
			),
			200
		);
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

		$payment = $this->resolve_payment( $request );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$invoice = $payment->invoice;
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$amount = isset( $params['amount'] ) ? (float) $params['amount'] : (float) $payment->amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_data', __( 'Payment amount must be greater than zero.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$balance = round( (float) $invoice->total - (float) $invoice->amount_paid + (float) $payment->amount, 2 );
		if ( $amount > $balance + 0.001 ) {
			return new WP_Error(
				'payment_exceeds_balance',
				__( 'Payment amount exceeds the balance due on this invoice.', 'doublescale' ),
				array( 'status' => 422 )
			);
		}

		$payment_date = $payment->payment_date;
		if ( ! empty( $params['payment_date'] ) ) {
			$payment_date = sanitize_text_field( (string) $params['payment_date'] );
		}

		$payment_mode = $payment->payment_mode;
		if ( isset( $params['payment_mode'] ) ) {
			$payment_mode = PaymentMode::normalize( (string) $params['payment_mode'] );
		}

		$payment->fill(
			array(
				'amount'         => $amount,
				'payment_mode'   => $payment_mode,
				'payment_date'   => $payment_date,
				'transaction_id' => array_key_exists( 'transaction_id', $params )
					? ( '' !== (string) $params['transaction_id'] ? sanitize_text_field( (string) $params['transaction_id'] ) : null )
					: $payment->transaction_id,
				'note'           => array_key_exists( 'note', $params )
					? ( '' !== (string) $params['note'] ? sanitize_textarea_field( (string) $params['note'] ) : null )
					: $payment->note,
			)
		);
		$payment->save();

		$invoice = ( new InvoicePayments() )->sync( $invoice );
		$payment->load( array( 'invoice.contact', 'recorded_by' ) );

		return new WP_REST_Response(
			array(
				'payment' => $this->shape_payment( $payment, true ),
			),
			200
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

		$payment = $this->resolve_payment( $request );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$invoice = $payment->invoice;
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$payment->delete();

		$invoice = ( new InvoicePayments() )->sync( $invoice );

		return new WP_REST_Response(
			array(
				'deleted'    => true,
				'invoice_id' => (int) $invoice->id,
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return PaymentModel|WP_Error
	 */
	private function resolve_payment( WP_REST_Request $request ) {
		$payment = PaymentModel::query()
			->with( array( 'invoice.contact', 'recorded_by' ) )
			->where( 'id', (int) $request->get_param( 'id' ) )
			->first();

		if ( ! $payment ) {
			return new WP_Error( 'not_found', __( 'Payment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$invoice = $payment->invoice;
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! Capabilities::user_can_manage_record( get_current_user_id(), $invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null ) ) {
			return new WP_Error( 'not_allowed', __( 'You do not have permission to access this payment.', 'doublescale' ), array( 'status' => 403 ) );
		}

		return $payment;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$query = PaymentModel::query()->with( array( 'invoice.contact', 'recorded_by' ) );

		$query->whereHas(
			'invoice',
			function ( $invoice_query ) {
				if ( ! Capabilities::can_manage_all_sales() ) {
					$invoice_query->where( 'sale_agent_user_id', get_current_user_id() );
				}
			}
		);

		$total_count = (int) $query->count();

		$payment_mode = $request->get_param( 'payment_mode' );
		if ( is_string( $payment_mode ) && '' !== trim( $payment_mode ) && 'all' !== $payment_mode ) {
			$raw_mode = strtolower( str_replace( '-', '_', trim( $payment_mode ) ) );
			$allowed  = array_merge(
				PaymentMode::all(),
				array( PaymentMode::CREDIT_NOTE, PaymentMode::CREDIT_CARD )
			);
			if ( in_array( $raw_mode, $allowed, true ) ) {
				$query->where( 'payment_mode', $raw_mode );
			} else {
				$normalized_mode = PaymentMode::normalize( trim( $payment_mode ) );
				if ( $normalized_mode ) {
					$query->where( 'payment_mode', $normalized_mode );
				}
			}
		}

		$payment_date_from = $request->get_param( 'payment_date_from' );
		if ( is_string( $payment_date_from ) && '' !== trim( $payment_date_from ) ) {
			$query->where( 'payment_date', '>=', trim( $payment_date_from ) );
		}

		$payment_date_to = $request->get_param( 'payment_date_to' );
		if ( is_string( $payment_date_to ) && '' !== trim( $payment_date_to ) ) {
			$query->where( 'payment_date', '<=', trim( $payment_date_to ) );
		}

		$search = $request->get_param( 'search' );
		if ( is_string( $search ) && '' !== trim( $search ) ) {
			$term = trim( $search );
			$like = '%' . str_replace( array( '%', '_' ), array( '\\%', '\\_' ), $term ) . '%';
			$query->where(
				function ( $payment_query ) use ( $like, $term ) {
					if ( ctype_digit( $term ) ) {
						$payment_query->where( 'id', (int) $term );
					}
					$payment_query->orWhere( 'transaction_id', 'LIKE', $like )
						->orWhereHas(
							'invoice',
							function ( $invoice_query ) use ( $like ) {
								$invoice_query->where( 'invoice_number', 'LIKE', $like )
									->orWhereHas(
										'contact',
										function ( $contact_query ) use ( $like ) {
											$contact_query->where( 'first_name', 'LIKE', $like )
												->orWhere( 'last_name', 'LIKE', $like )
												->orWhere( 'email', 'LIKE', $like );
										}
									);
							}
						);
				}
			);
		}

		$sort_by    = in_array( $request->get_param( 'sort_by' ), array( 'id', 'payment_date', 'amount', 'created_at' ), true )
			? $request->get_param( 'sort_by' )
			: 'id';
		$sort_order = 'asc' === $request->get_param( 'sort_order' ) ? 'asc' : 'desc';
		$query->orderBy( $sort_by, $sort_order );
		if ( 'id' !== $sort_by ) {
			$query->orderBy( 'id', 'desc' );
		}

		$per_page  = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 25 ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$paginator = $query->paginate( $per_page, array( '*' ), 'page', $page );

		$data = array();
		foreach ( $paginator->items() as $payment ) {
			$data[] = $this->shape_payment( $payment );
		}

		return new WP_REST_Response(
			array(
				'data'        => $data,
				'total_count' => $total_count,
				'meta'        => array(
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
	 * @param PaymentModel $payment Payment.
	 * @param bool         $with_receipt Include receipt fields.
	 * @return array
	 */
	private function shape_payment( PaymentModel $payment, bool $with_receipt = false ): array {
		$row = array(
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

		$invoice = $payment->relationLoaded( 'invoice' ) ? $payment->invoice : null;
		if ( $invoice ) {
			$row['invoice'] = array(
				'id'             => (int) $invoice->id,
				'invoice_number' => (string) $invoice->invoice_number,
				'currency'       => \DoubleScale\Core\Settings\Settings::document_currency( $invoice->currency, $invoice->sent_at ),
			);

			if ( $with_receipt ) {
				$row['invoice']['invoice_date']    = $invoice->invoice_date;
				$row['invoice']['total']           = (float) $invoice->total;
				$row['invoice']['amount_paid']     = (float) $invoice->amount_paid;
				$row['invoice']['billing_address'] = $invoice->billing_address;
			}

			$contact = $invoice->relationLoaded( 'contact' ) ? $invoice->contact : null;
			if ( $contact ) {
				$row['contact'] = array(
					'id'         => (int) $contact->id,
					'first_name' => $contact->first_name,
					'last_name'  => $contact->last_name,
					'email'      => $contact->email,
				);
			}
		}

		$user = $payment->relationLoaded( 'recorded_by' ) ? $payment->recorded_by : null;
		if ( $user ) {
			$row['recorded_by'] = array(
				'id'           => (int) $user->ID,
				'display_name' => (string) $user->display_name,
				'email'        => (string) $user->user_email,
			);
		}

		if ( $with_receipt ) {
			$company = DocumentPdf::resolved_company_block();
			$row['company'] = array(
				'name'                => (string) ( $company['name'] ?? '' ),
				'url'                 => (string) ( $company['url'] ?? '' ),
				'address'             => (string) ( $company['address'] ?? '' ),
				'registration_number' => (string) ( $company['registration_number'] ?? '' ),
				'tax_vat_number'      => (string) ( $company['tax_vat_number'] ?? '' ),
			);
		}

		return $row;
	}
}
