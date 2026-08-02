<?php
/**
 * Public guest access to contracts via hash.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Pro\Modules\Contracts\Constants\ContractStatus;
use DoubleScale\Pro\Modules\Contracts\Models\ContractAttachmentModel;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\Contracts\Rest\ContractShaper;
use DoubleScale\Pro\Modules\Contracts\Services\ContractAttachmentService;
use DoubleScale\Pro\Modules\Contracts\Services\ContractNotifications;
use DoubleScale\Pro\Modules\Contracts\Services\ContractPdf;
use DoubleScale\Modules\Sales\Services\SalesRepNotifications;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPublicContractController class.
 */
class RestPublicContractController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/public/contracts';

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
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/sign',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sign' ),
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
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_attachments' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/attachments/(?P<file_hash>[a-zA-Z0-9]+)/download',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download_attachment' ),
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
		$disabled = $this->require_module( 'contracts' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$contract = $this->resolve_by_hash( $request );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}

		if ( empty( $contract->viewed_at ) ) {
			$contract->viewed_at = current_time( 'mysql' );
			$contract->save();
		}

		return new WP_REST_Response( ContractShaper::shape_public( $contract ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sign( $request ) {
		$disabled = $this->require_module( 'contracts' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$contract = $this->resolve_by_hash( $request );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}

		if ( ! ContractShaper::can_sign( $contract ) ) {
			return new WP_Error( 'invalid_status', __( 'This contract can no longer be signed.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$signed_name = isset( $params['signed_name'] ) ? sanitize_text_field( (string) $params['signed_name'] ) : '';
		$signature   = isset( $params['signature'] ) ? (string) $params['signature'] : '';

		if ( SalesSettings::get( 'require_signature_on_accept', true ) ) {
			if ( '' === trim( $signed_name ) ) {
				return new WP_Error( 'invalid_data', __( 'Please enter your name to sign.', 'doublescale' ), array( 'status' => 400 ) );
			}
			if ( '' === trim( $signature ) ) {
				return new WP_Error( 'invalid_data', __( 'Please provide your signature to sign.', 'doublescale' ), array( 'status' => 400 ) );
			}
		}

		$contract->status      = ContractStatus::SIGNED;
		$contract->signed_at   = current_time( 'mysql' );
		if ( '' !== trim( $signed_name ) ) {
			$contract->signed_name = $signed_name;
		}
		if ( '' !== trim( $signature ) ) {
			$contract->signature = $this->sanitize_signature( $signature );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP for audit only.
		$contract->signed_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : null;
		$contract->save();

		$this->log_status_activity(
			$contract,
			ContractStatus::SIGNED,
			__( 'Customer signed the contract.', 'doublescale' )
		);

		do_action( 'doublescale_sales_contract_signed', $contract );

		( new SalesRepNotifications() )->notify_contract_event( $contract, 'signed' );
		( new ContractNotifications() )->send_signed_confirmation( $contract );

		return new WP_REST_Response( ContractShaper::shape_public( $contract ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pdf( $request ) {
		$disabled = $this->require_module( 'contracts' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$contract = $this->resolve_by_hash( $request );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}

		$shaped = ContractShaper::shape_public( $contract );

		return ContractPdf::rest_response( $shaped, (string) $contract->contract_number );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_attachments( $request ) {
		$disabled = $this->require_module( 'contracts' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$contract = $this->resolve_by_hash( $request );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}

		$service = new ContractAttachmentService();
		$items   = ContractAttachmentModel::query()
			->forType( ContractAttachmentModel::ATTACHABLE_TYPE )
			->with( array( 'user', 'contact' ) )
			->where( 'attachable_id', (int) $contract->id )
			->orderBy( 'id' )
			->get();

		$data = array();
		foreach ( $items as $item ) {
			$data[] = $service->shape_for_api( $item );
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function download_attachment( $request ) {
		$disabled = $this->require_module( 'contracts' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$contract = $this->resolve_by_hash( $request );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}

		$file_hash  = (string) $request->get_param( 'file_hash' );
		$attachment = ContractAttachmentModel::query()
			->forType( ContractAttachmentModel::ATTACHABLE_TYPE )
			->where( 'attachable_id', (int) $contract->id )
			->where( 'file_hash', $file_hash )
			->first();
		if ( ! $attachment ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$service = new ContractAttachmentService();
		wp_safe_redirect( $service->signed_url( $attachment ) );
		exit;
	}

	/**
	 * @param string $signature Raw signature payload.
	 * @return string
	 */
	private function sanitize_signature( string $signature ): string {
		$signature = trim( $signature );
		if ( strlen( $signature ) > 500000 ) {
			return '';
		}
		if ( preg_match( '/^data:image\/(png|jpeg|jpg|gif);base64,/', $signature ) ) {
			return $signature;
		}
		return sanitize_text_field( $signature );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return ContractModel|WP_Error
	 */
	private function resolve_by_hash( WP_REST_Request $request ) {
		$hash     = (string) $request->get_param( 'hash' );
		$contract = ContractModel::get_by_hash( $hash );
		if ( ! $contract ) {
			return new WP_Error( 'not_found', __( 'Contract not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( $contract->hide_from_customer || $contract->is_trash ) {
			return new WP_Error( 'not_found', __( 'Contract not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$contract->loadMissing( 'type' );

		return $contract;
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @param string        $status New status.
	 * @param string        $note Activity note.
	 * @return void
	 */
	private function log_status_activity( ContractModel $contract, string $status, string $note ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		ActivityModel::create(
			array(
				'contact_id'    => (int) $contract->contact_id,
				'activity_type' => ActivityTypes::STATUS_CHANGED,
				'data'          => array(
					'title'       => __( 'Contract updated', 'doublescale' ),
					'type'        => 'system',
					'note'        => $note,
					'contract_id' => (int) $contract->id,
					'status'      => $status,
				),
				'user_id'       => null,
			)
		);
	}

	/**
	 * @return bool
	 */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for rate-limit key.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'ds_sales_pub_contract_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count > 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
