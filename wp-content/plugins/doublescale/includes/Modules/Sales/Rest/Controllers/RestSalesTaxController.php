<?php
/**
 * REST controller for sales taxes.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Modules\Sales\Models\TaxModel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestSalesTaxController class.
 */
class RestSalesTaxController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/taxes';

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
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_items_permissions_check' ),
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
					'permission_callback' => array( $this, 'manage_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'manage_items_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return Capabilities::can_view_sales();
	}

	/**
	 * @return bool
	 */
	public function manage_items_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return Capabilities::can_manage_all_sales();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$disabled = $this->require_module( 'sales' );
		if ( $disabled ) {
			return $disabled;
		}

		$taxes = TaxModel::query()
			->orderBy( 'name' )
			->get();

		$data = array();
		foreach ( $taxes as $tax ) {
			$data[] = $this->shape_tax( $tax );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$disabled = $this->require_module( 'sales' );
		if ( $disabled ) {
			return $disabled;
		}

		$tax = TaxModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $tax ) {
			return new WP_Error( 'not_found', __( 'Tax not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->shape_tax( $tax ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$disabled = $this->require_module( 'sales' );
		if ( $disabled ) {
			return $disabled;
		}

		$payload = $this->sanitize_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		try {
			$tax = TaxModel::create( $payload );
		} catch ( \Exception $e ) {
			if ( $this->is_duplicate_name_error( $e ) ) {
				return new WP_Error( 'duplicate_name', __( 'A tax with this name already exists.', 'doublescale' ), array( 'status' => 400 ) );
			}
			throw $e;
		}

		return new WP_REST_Response( $this->shape_tax( $tax ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$disabled = $this->require_module( 'sales' );
		if ( $disabled ) {
			return $disabled;
		}

		$tax = TaxModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $tax ) {
			return new WP_Error( 'not_found', __( 'Tax not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$payload = $this->sanitize_payload( $request, false );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( empty( $payload ) ) {
			return new WP_Error( 'invalid_data', __( 'No valid fields to update.', 'doublescale' ), array( 'status' => 400 ) );
		}

		try {
			$tax->fill( $payload );
			$tax->save();
		} catch ( \Exception $e ) {
			if ( $this->is_duplicate_name_error( $e ) ) {
				return new WP_Error( 'duplicate_name', __( 'A tax with this name already exists.', 'doublescale' ), array( 'status' => 400 ) );
			}
			throw $e;
		}

		return new WP_REST_Response( $this->shape_tax( $tax ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$disabled = $this->require_module( 'sales' );
		if ( $disabled ) {
			return $disabled;
		}

		$tax = TaxModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $tax ) {
			return new WP_Error( 'not_found', __( 'Tax not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$tax->delete();

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param TaxModel $tax Tax.
	 * @return array
	 */
	private function shape_tax( TaxModel $tax ): array {
		return array(
			'id'   => (int) $tax->id,
			'name' => (string) $tax->name,
			'rate' => (float) $tax->rate,
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param bool            $require_name Whether name is required.
	 * @return array|WP_Error
	 */
	private function sanitize_payload( WP_REST_Request $request, bool $require_name = true ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$payload = array();

		if ( array_key_exists( 'name', $params ) ) {
			$name = sanitize_text_field( (string) $params['name'] );
			if ( '' === trim( $name ) ) {
				return new WP_Error( 'invalid_data', __( 'Tax name is required.', 'doublescale' ), array( 'status' => 400 ) );
			}
			$payload['name'] = $name;
		} elseif ( $require_name ) {
			return new WP_Error( 'invalid_data', __( 'Tax name is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'rate', $params ) ) {
			$rate = (float) $params['rate'];
			if ( $rate < 0 || $rate > 100 ) {
				return new WP_Error( 'invalid_data', __( 'Tax rate must be between 0 and 100.', 'doublescale' ), array( 'status' => 400 ) );
			}
			$payload['rate'] = $rate;
		} elseif ( $require_name ) {
			return new WP_Error( 'invalid_data', __( 'Tax rate is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		return $payload;
	}

	/**
	 * @param \Exception $e Exception.
	 * @return bool
	 */
	private function is_duplicate_name_error( \Exception $e ): bool {
		$message = $e->getMessage();
		return false !== stripos( $message, 'duplicate' ) && false !== stripos( $message, 'name' );
	}
}
