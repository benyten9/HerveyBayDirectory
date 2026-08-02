<?php
/**
 * REST controller for sales document approvals.
 *
 * @package DoubleScale\Pro\Modules\Sales\Approvals
 */

namespace DoubleScale\Pro\Modules\Sales\Approvals\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Rest\ProposalShaper;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\Contracts\Rest\ContractShaper;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use DoubleScale\Pro\Modules\CreditNotes\Rest\CreditNoteShaper;
use DoubleScale\Pro\Modules\Sales\Approvals\Constants\ApprovalStatus;
use DoubleScale\Pro\Modules\Sales\Approvals\Models\ApprovalModel;
use DoubleScale\Pro\Modules\Sales\Approvals\Services\ApprovalWorkflow;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestApprovalController class.
 */
class RestApprovalController extends RestController {

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
			'/' . $this->rest_base . '/proposals/(?P<id>[\d]+)/submit-approval',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_proposal' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<id>[\d]+)/submit-approval',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_invoice' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/contracts/(?P<id>[\d]+)/submit-approval',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_contract' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/credit-notes/(?P<id>[\d]+)/submit-approval',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_credit_note' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/proposals/(?P<id>[\d]+)/withdraw-approval',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'withdraw_proposal' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/invoices/(?P<id>[\d]+)/withdraw-approval',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'withdraw_invoice' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/contracts/(?P<id>[\d]+)/withdraw-approval',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'withdraw_contract' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/credit-notes/(?P<id>[\d]+)/withdraw-approval',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'withdraw_credit_note' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/approvals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'review_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/approvals/(?P<id>[\d]+)/approve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve_item' ),
					'permission_callback' => array( $this, 'review_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/approvals/(?P<id>[\d]+)/reject',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reject_item' ),
					'permission_callback' => array( $this, 'review_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_collection_params() {
		return array(
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
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function submit_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_view_sales()
			&& Capabilities::current_user_can( 'doublescale_manage_own_sales' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function review_permissions_check( $request ) {
		unset( $request );
		return Capabilities::can_approve_sales();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_proposal( $request ) {
		$id = (int) $request->get_param( 'id' );
		$result = ApprovalWorkflow::submit( 'proposal', $id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'data' => ApprovalWorkflow::shape_approval( $result ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_invoice( $request ) {
		$id = (int) $request->get_param( 'id' );
		$result = ApprovalWorkflow::submit( 'invoice', $id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'data' => ApprovalWorkflow::shape_approval( $result ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_contract( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = ApprovalWorkflow::submit( 'contract', $id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'data' => ApprovalWorkflow::shape_approval( $result ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_credit_note( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = ApprovalWorkflow::submit( 'credit_note', $id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'data' => ApprovalWorkflow::shape_approval( $result ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function withdraw_proposal( $request ) {
		$result = ApprovalWorkflow::withdraw( 'proposal', (int) $request->get_param( 'id' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'withdrawn' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function withdraw_invoice( $request ) {
		$result = ApprovalWorkflow::withdraw( 'invoice', (int) $request->get_param( 'id' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'withdrawn' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function withdraw_contract( $request ) {
		$result = ApprovalWorkflow::withdraw( 'contract', (int) $request->get_param( 'id' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'withdrawn' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function withdraw_credit_note( $request ) {
		$result = ApprovalWorkflow::withdraw( 'credit_note', (int) $request->get_param( 'id' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'withdrawn' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$storage = ApprovalWorkflow::ensure_storage_or_error();
		if ( $storage ) {
			return $storage;
		}

		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$offset   = ( $page - 1 ) * $per_page;

		$query = ApprovalModel::pending()->orderBy( 'requested_at', 'asc' );
		$total = (int) $query->count();
		$rows  = $query->skip( $offset )->take( $per_page )->get();

		$data = array();
		foreach ( $rows as $row ) {
			if ( ! $row instanceof ApprovalModel ) {
				continue;
			}
			$shaped = ApprovalWorkflow::shape_approval( $row );
			if ( ! $shaped ) {
				continue;
			}
			$shaped['document'] = $this->shape_document_for_queue( (string) $row->document_type, (int) $row->document_id );
			$data[] = $shaped;
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'    => $total,
					'page'     => $page,
					'per_page' => $per_page,
				),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_item( $request ) {
		$approval = ApprovalModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $approval ) {
			return new WP_Error( 'not_found', __( 'Approval not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$result = ApprovalWorkflow::approve(
			(string) $approval->document_type,
			(int) $approval->document_id,
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'data' => ApprovalWorkflow::shape_approval( $result ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_item( $request ) {
		$approval = ApprovalModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $approval ) {
			return new WP_Error( 'not_found', __( 'Approval not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$reason = isset( $params['reason'] ) ? (string) $params['reason'] : '';

		$result = ApprovalWorkflow::reject(
			(string) $approval->document_type,
			(int) $approval->document_id,
			get_current_user_id(),
			$reason
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'data' => ApprovalWorkflow::shape_approval( $result ),
			),
			200
		);
	}

	/**
	 * @param string $type Document type.
	 * @param int    $id   Document id.
	 * @return array<string, mixed>|null
	 */
	private function shape_document_for_queue( string $type, int $id ): ?array {
		if ( 'proposal' === $type ) {
			$proposal = ProposalModel::with( array( 'contact', 'assigned_user' ) )->find( $id );
			return $proposal ? ProposalShaper::shape_admin( $proposal, true ) : null;
		}

		if ( 'invoice' === $type ) {
			$invoice = InvoiceModel::with( array( 'contact', 'sale_agent' ) )->find( $id );
			return $invoice ? InvoiceShaper::shape( $invoice, true ) : null;
		}

		if ( 'contract' === $type ) {
			$contract = ContractModel::with( array( 'contact', 'assigned_user' ) )->find( $id );
			return $contract ? ContractShaper::shape_admin( $contract, true ) : null;
		}

		if ( 'credit_note' === $type ) {
			$credit_note = CreditNoteModel::with( array( 'contact', 'sale_agent' ) )->find( $id );
			return $credit_note ? CreditNoteShaper::shape( $credit_note, true ) : null;
		}

		return null;
	}
}
