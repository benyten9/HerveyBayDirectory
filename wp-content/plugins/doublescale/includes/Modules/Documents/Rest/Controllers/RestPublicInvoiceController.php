<?php
/**
 * Public guest access to invoices via hash.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Services\DocumentPdf;
use DoubleScale\Core\Payment\GatewayManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPublicInvoiceController class.
 */
class RestPublicInvoiceController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/public/invoices';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/pdf',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pdf' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/pay/(?P<gateway>[a-z0-9_\-]+)/init',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'gateway_init' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/pay/(?P<gateway>[a-z0-9_\-]+)/confirm',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'gateway_confirm' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/stripe/init',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'stripe_init' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/stripe/confirm',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'stripe_confirm' ),
					'permission_callback' => '__return_true',
				),
			)
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
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$invoice = $this->resolve_by_hash( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		if ( InvoiceStatus::DRAFT === (string) $invoice->status ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( empty( $invoice->viewed_at ) ) {
			$invoice->viewed_at = current_time( 'mysql' );
			$invoice->save();
			$this->log_viewed_activity( $invoice );
		}

		return new WP_REST_Response( $this->shape_public_payload( $invoice ), 200 );
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
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$invoice = $this->resolve_by_hash( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		if ( InvoiceStatus::DRAFT === (string) $invoice->status ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$invoice->load( array( 'contact' ) );
		$shaped = InvoiceShaper::shape_public( $invoice );

		return DocumentPdf::rest_response( $shaped, 'invoice', (string) $invoice->invoice_number );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function gateway_init( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$invoice = $this->resolve_by_hash( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$terms_error = $this->require_terms_agreement( $invoice, $request );
		if ( is_wp_error( $terms_error ) ) {
			return $terms_error;
		}

		$gateway = sanitize_key( (string) $request->get_param( 'gateway' ) );
		$result  = GatewayManager::instance()->init_payment( $gateway, $invoice );
		if ( is_wp_error( $result ) ) {
			return $this->normalize_public_gateway_error( $result, $gateway );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function gateway_confirm( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$invoice = $this->resolve_by_hash( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$gateway = sanitize_key( (string) $request->get_param( 'gateway' ) );
		$result  = GatewayManager::instance()->confirm_payment( $gateway, $invoice );
		if ( is_wp_error( $result ) ) {
			return $this->normalize_public_gateway_error( $result, $gateway );
		}

		if ( is_array( $result ) && isset( $result['invoice'] ) && $result['invoice'] instanceof InvoiceModel ) {
			$result['invoice'] = InvoiceShaper::shape_public( $result['invoice'] );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function stripe_init( $request ) {
		$request->set_param( 'gateway', 'stripe' );
		return $this->gateway_init( $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function stripe_confirm( $request ) {
		$request->set_param( 'gateway', 'stripe' );
		return $this->gateway_confirm( $request );
	}

	/**
	 * @param WP_Error $error   Error.
	 * @param string   $gateway Gateway slug.
	 * @return WP_Error
	 */
	private function normalize_public_gateway_error( WP_Error $error, string $gateway ): WP_Error {
		if ( 'stripe' === $gateway && in_array( $error->get_error_code(), array( 'gateway_unavailable', 'gateway_not_configured', 'gateway_not_found' ), true ) ) {
			return new WP_Error(
				'stripe_unavailable',
				__( 'Stripe payments require DoubleScale Pro with Stripe configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}
		return $error;
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return array<string, mixed>
	 */
	private function shape_public_payload( InvoiceModel $invoice ): array {
		$invoice->load( array( 'contact' ) );

		$payments = PaymentModel::query()
			->where( 'invoice_id', (int) $invoice->id )
			->orderBy( 'payment_date', 'desc' )
			->orderBy( 'id', 'desc' )
			->get();

		$payload = InvoiceShaper::shape_public( $invoice );
		$payload['payments'] = array();
		foreach ( $payments as $payment ) {
			$payload['payments'][] = InvoiceShaper::shape_public_payment( $payment );
		}

		return $payload;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return InvoiceModel|WP_Error
	 */
	private function resolve_by_hash( WP_REST_Request $request ) {
		$hash    = (string) $request->get_param( 'hash' );
		$invoice = InvoiceModel::get_by_hash( $hash );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		return $invoice;
	}

	/**
	 * @param InvoiceModel    $invoice Invoice.
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function require_terms_agreement( InvoiceModel $invoice, WP_REST_Request $request ) {
		$terms = trim( wp_strip_all_tags( (string) $invoice->terms ) );
		if ( '' === $terms ) {
			return true;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		if ( empty( $params['agreed_terms'] ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'You must agree to the Terms & Conditions before paying.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return void
	 */
	private function log_viewed_activity( InvoiceModel $invoice ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		ActivityModel::create(
			array(
				'contact_id'    => (int) $invoice->contact_id,
				'activity_type' => ActivityTypes::STATUS_CHANGED,
				'data'          => array(
					'title'      => __( 'Invoice viewed', 'doublescale' ),
					'type'       => 'system',
					'note'       => sprintf(
						/* translators: %s: invoice number */
						__( 'Customer opened invoice %s.', 'doublescale' ),
						(string) $invoice->invoice_number
					),
					'invoice_id' => (int) $invoice->id,
				),
				'user_id'       => null,
			)
		);
		// TODO(morph): wire proposal/invoice associations (ENTITY_TYPE_INVOICE).
	}

	/**
	 * @return bool
	 */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for rate-limit key.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'ds_sales_inv_pub_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count > 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
