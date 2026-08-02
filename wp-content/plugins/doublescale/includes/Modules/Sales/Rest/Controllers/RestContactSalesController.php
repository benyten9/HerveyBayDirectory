<?php
/**
 * REST controller for contact-scoped sales data.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestContactSalesController class.
 */
class RestContactSalesController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/contacts';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<contact_id>[\d]+)/payments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_contact_payments' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
			)
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
	 * @return WP_REST_Response|\WP_Error
	 */
	public function get_contact_payments( $request ) {
		$disabled = $this->require_module( 'sales' );
		if ( $disabled ) {
			return $disabled;
		}

		$contact_id = (int) $request->get_param( 'contact_id' );
		$per_page   = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$page       = max( 1, (int) $request->get_param( 'page' ) ?: 1 );

		$invoice_query = InvoiceModel::query()->where( 'contact_id', $contact_id );
		if ( ! Capabilities::can_manage_all_sales() ) {
			$invoice_query->where( 'sale_agent_user_id', get_current_user_id() );
		}

		$invoice_ids = $invoice_query->pluck( 'id' )->all();
		if ( empty( $invoice_ids ) ) {
			return new WP_REST_Response(
				array(
					'data' => array(),
					'meta' => array(
						'total'        => 0,
						'per_page'     => $per_page,
						'current_page' => $page,
						'last_page'    => 1,
					),
				),
				200
			);
		}

		$payments_query = PaymentModel::query()
			->with( array( 'invoice', 'recorded_by' ) )
			->whereIn( 'invoice_id', $invoice_ids )
			->orderBy( 'payment_date', 'desc' )
			->orderBy( 'id', 'desc' );

		$paginator = $payments_query->paginate( $per_page, array( '*' ), 'page', $page );

		$data = array();
		foreach ( $paginator->items() as $payment ) {
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
					'currency'       => (string) $invoice->currency,
				);
			}

			$user = $payment->relationLoaded( 'recorded_by' ) ? $payment->recorded_by : null;
			if ( $user ) {
				$row['recorded_by'] = array(
					'id'           => (int) $user->ID,
					'display_name' => (string) $user->display_name,
					'email'        => (string) $user->user_email,
				);
			}

			$data[] = $row;
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
}
