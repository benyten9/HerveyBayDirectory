<?php
/**
 * REST controller for ticket attachment uploads (agent).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\AttachmentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestAttachmentController class.
 */
class RestAttachmentController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'support/tickets';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<ticket_id>[\d]+)/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Ticketless upload for the "compose a new ticket" flow — the file is
		// staged before the ticket exists and linked at create time by its hash.
		// A sibling path (not nested under /tickets) since there is no ticket yet.
		register_rest_route(
			$this->namespace,
			'/support/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_unticketed' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Upload a file and return its temp hash.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$ticket = $this->resolve_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$service  = $this->attachments();
		$too_many = $service->guard_file_count( (int) $request->get_param( 'pending_count' ) );
		if ( $too_many ) {
			return $too_many;
		}

		$attachment = $service->store_upload(
			$file,
			(int) $ticket->id,
			array( 'user_id' => get_current_user_id() )
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		return new WP_REST_Response(
			array(
				'file_hash' => (string) $attachment->file_hash,
				'file_name' => (string) $attachment->file_name,
				'file_size' => (int) $attachment->file_size,
				'file_type' => (string) $attachment->file_type,
			),
			201
		);
	}

	/**
	 * Upload a file BEFORE a ticket exists (new-ticket composer). Stores an
	 * unticketed temp row and returns its hash; the create call links it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_unticketed( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$service  = $this->attachments();
		$too_many = $service->guard_file_count( (int) $request->get_param( 'pending_count' ) );
		if ( $too_many ) {
			return $too_many;
		}

		$attachment = $service->store_upload(
			$file,
			0,
			array( 'user_id' => get_current_user_id() )
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		return new WP_REST_Response(
			array(
				'file_hash' => (string) $attachment->file_hash,
				'file_name' => (string) $attachment->file_name,
				'file_size' => (int) $attachment->file_size,
				'file_type' => (string) $attachment->file_type,
			),
			201
		);
	}

	/**
	 * @param WP_REST_Request $request Unused.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( Permissions::has_support_access() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to access support tickets.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return TicketModel|WP_Error
	 */
	private function resolve_ticket( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'ticket_id' );
		$ticket = TicketModel::find( $id );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! Permissions::can_manage_all_tickets()
			&& (int) $ticket->agent_user_id !== (int) get_current_user_id()
		) {
			return new WP_Error(
				'not_allowed',
				__( 'You can only access tickets assigned to you.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return $ticket;
	}

	/**
	 * @return AttachmentService
	 */
	private function attachments(): AttachmentService {
		return new AttachmentService();
	}
}
