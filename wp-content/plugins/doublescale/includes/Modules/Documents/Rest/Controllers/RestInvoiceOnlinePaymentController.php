<?php
/**
 * REST controller for online invoice payment gateways.
 *
 * Gateway SDK logic lives in Pro adapters; this controller validates the
 * invoice and delegates to GatewayManager.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestInvoiceOnlinePaymentController class.
 */
class RestInvoiceOnlinePaymentController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payment-gateways',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_gateways' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<invoice_id>[\d]+)/pay/(?P<gateway>[a-z0-9_\-]+)/init',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'init_payment' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<invoice_id>[\d]+)/pay/(?P<gateway>[a-z0-9_\-]+)/confirm',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'confirm_payment' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		// Backward compatibility — Stripe-specific paths delegate to the manager.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stripe/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stripe_status' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<invoice_id>[\d]+)/stripe/init',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'init_stripe_legacy' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<invoice_id>[\d]+)/stripe/confirm',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'confirm_stripe_legacy' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);
	}

	public function get_items_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales();
	}

	public function manage_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales()
			&& ( Capabilities::can_manage_all_sales() || Capabilities::current_user_can( 'doublescale_manage_own_sales' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_gateways( $request ) {
		unset( $request );
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		return new WP_REST_Response(
			array(
				'gateways' => GatewayManager::instance()->shape_status_list(),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_stripe_status( $request ) {
		unset( $request );
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$manager = GatewayManager::instance();
		$stripe  = $manager->get( GatewayManager::CONTEXT_INVOICE, 'stripe' );

		return new WP_REST_Response(
			array(
				'available'  => $stripe ? $stripe->is_available() : (bool) apply_filters( 'doublescale_sales_stripe_available', false ),
				'configured' => $stripe ? $stripe->is_configured() : (bool) apply_filters( 'doublescale_sales_stripe_configured', false ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function init_payment( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = $this->resolve_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$gateway = sanitize_key( (string) $request->get_param( 'gateway' ) );
		$result  = GatewayManager::instance()->init_payment( $gateway, $invoice );
		if ( is_wp_error( $result ) ) {
			return $this->normalize_gateway_error( $result, $gateway );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm_payment( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}

		$invoice = $this->resolve_invoice( $request );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$gateway = sanitize_key( (string) $request->get_param( 'gateway' ) );
		$result  = GatewayManager::instance()->confirm_payment( $gateway, $invoice );
		if ( is_wp_error( $result ) ) {
			return $this->normalize_gateway_error( $result, $gateway );
		}

		if ( is_array( $result ) && isset( $result['invoice'] ) && $result['invoice'] instanceof InvoiceModel ) {
			$result['invoice'] = InvoiceShaper::shape( $result['invoice'], true );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function init_stripe_legacy( $request ) {
		$request->set_param( 'gateway', 'stripe' );
		return $this->init_payment( $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm_stripe_legacy( $request ) {
		$request->set_param( 'gateway', 'stripe' );
		return $this->confirm_payment( $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return InvoiceModel|WP_Error
	 */
	private function resolve_invoice( WP_REST_Request $request ) {
		$invoice = InvoiceModel::with( array( 'contact', 'sale_agent', 'proposal' ) )->find( (int) $request->get_param( 'invoice_id' ) );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! Capabilities::user_can_manage_record( get_current_user_id(), $invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null ) ) {
			return new WP_Error( 'not_allowed', __( 'You do not have permission to access this invoice.', 'doublescale' ), array( 'status' => 403 ) );
		}

		return $invoice;
	}

	/**
	 * Map generic gateway errors to legacy stripe_unavailable code for Stripe.
	 *
	 * @param WP_Error $error   Error.
	 * @param string   $gateway Gateway slug.
	 * @return WP_Error
	 */
	private function normalize_gateway_error( WP_Error $error, string $gateway ): WP_Error {
		if ( 'stripe' === $gateway && in_array( $error->get_error_code(), array( 'gateway_unavailable', 'gateway_not_configured', 'gateway_not_found' ), true ) ) {
			return new WP_Error(
				'stripe_unavailable',
				__( 'Stripe payments require DoubleScale Pro with Stripe configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}
		return $error;
	}
}
