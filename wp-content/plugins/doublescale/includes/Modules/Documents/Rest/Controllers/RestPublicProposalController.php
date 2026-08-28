<?php
/**
 * Public guest access to proposals via hash.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Rest\ProposalShaper;
use DoubleScale\Modules\Documents\Services\DocumentPdf;
use DoubleScale\Modules\Sales\Services\SalesRepNotifications;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPublicProposalController class.
 */
class RestPublicProposalController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/public/proposals';

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
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/accept',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'accept' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/decline',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'decline' ),
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

		$proposal = $this->resolve_by_hash( $request );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		if ( ProposalStatus::SENT === (string) $proposal->status ) {
			$proposal->status = ProposalStatus::OPEN;
			$proposal->save();
		}

		if ( empty( $proposal->viewed_at ) ) {
			$proposal->viewed_at = current_time( 'mysql' );
			$proposal->save();
		}

		return new WP_REST_Response( ProposalShaper::shape_public( $proposal ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function accept( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$proposal = $this->resolve_by_hash( $request );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		if ( ! ProposalShaper::can_respond( $proposal ) ) {
			return new WP_Error( 'invalid_status', __( 'This proposal can no longer be accepted.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$signed_name = isset( $params['signed_name'] ) ? sanitize_text_field( (string) $params['signed_name'] ) : '';
		$signature   = isset( $params['signature'] ) ? (string) $params['signature'] : '';
		$agreed_terms = ! empty( $params['agreed_terms'] );

		$terms = trim( wp_strip_all_tags( (string) $proposal->terms ) );
		if ( '' !== $terms && ! $agreed_terms ) {
			return new WP_Error( 'invalid_data', __( 'You must agree to the Terms & Conditions to accept.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( SalesSettings::get( 'require_signature_on_accept', true ) ) {
			if ( '' === trim( $signed_name ) ) {
				return new WP_Error( 'invalid_data', __( 'Please enter your name to accept.', 'doublescale' ), array( 'status' => 400 ) );
			}
			if ( '' === trim( $signature ) ) {
				return new WP_Error( 'invalid_data', __( 'Please provide your signature to accept.', 'doublescale' ), array( 'status' => 400 ) );
			}
		}

		$proposal->status      = ProposalStatus::ACCEPTED;
		$proposal->accepted_at = current_time( 'mysql' );
		if ( '' !== trim( $signed_name ) ) {
			$proposal->signed_name = $signed_name;
		}
		if ( '' !== trim( $signature ) ) {
			$proposal->signature = $this->sanitize_signature( $signature );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP for audit only.
		$proposal->signed_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : null;
		$proposal->save();

		$this->log_status_activity(
			$proposal,
			ProposalStatus::ACCEPTED,
			__( 'Customer accepted the proposal.', 'doublescale' )
		);

		do_action( 'doublescale_sales_proposal_accepted', $proposal );

		( new SalesRepNotifications() )->notify_proposal_event( $proposal, 'accepted' );

		return new WP_REST_Response( ProposalShaper::shape_public( $proposal ), 200 );
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

		$proposal = $this->resolve_by_hash( $request );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$shaped = ProposalShaper::shape_public( $proposal );

		return DocumentPdf::rest_response( $shaped, 'proposal', (string) $proposal->proposal_number );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function decline( $request ) {
		$disabled = $this->require_module( 'documents' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$proposal = $this->resolve_by_hash( $request );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		if ( ! ProposalShaper::can_respond( $proposal ) ) {
			return new WP_Error( 'invalid_status', __( 'This proposal can no longer be declined.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$reason = isset( $params['reason'] ) ? sanitize_textarea_field( (string) $params['reason'] ) : '';

		$proposal->status         = ProposalStatus::DECLINED;
		$proposal->declined_at    = current_time( 'mysql' );
		$proposal->decline_reason = '' !== $reason ? $reason : null;
		$proposal->save();

		$note = __( 'Customer declined the proposal.', 'doublescale' );
		if ( '' !== $reason ) {
			$note .= ' ' . sprintf(
				/* translators: %s: decline reason */
				__( 'Reason: %s', 'doublescale' ),
				$reason
			);
		}

		$this->log_status_activity( $proposal, ProposalStatus::DECLINED, $note );

		do_action( 'doublescale_sales_proposal_declined', $proposal, $reason );

		( new SalesRepNotifications() )->notify_proposal_event( $proposal, 'declined' );

		return new WP_REST_Response( ProposalShaper::shape_public( $proposal ), 200 );
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
	 * Resolve a proposal from the public hash.
	 *
	 * Drafts are treated as non-existent on the public surface (404, mirroring
	 * {@see RestPublicInvoiceController}) so a work-in-progress proposal the
	 * agent has not sent yet can't be viewed, downloaded, commented on, or have
	 * its `viewed_at` stamped by anyone who guesses/obtains the hash early.
	 *
	 * @param WP_REST_Request $request     Request.
	 * @param bool            $block_draft Return 404 for draft proposals (default true).
	 * @return ProposalModel|WP_Error
	 */
	private function resolve_by_hash( WP_REST_Request $request, bool $block_draft = true ) {
		$hash     = (string) $request->get_param( 'hash' );
		$proposal = ProposalModel::get_by_hash( $hash );
		if ( ! $proposal ) {
			return new WP_Error( 'not_found', __( 'Proposal not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( $block_draft && ProposalStatus::DRAFT === (string) $proposal->status ) {
			return new WP_Error( 'not_found', __( 'Proposal not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		return $proposal;
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @param string        $status New status.
	 * @param string        $note Activity note.
	 * @return void
	 */
	private function log_status_activity( ProposalModel $proposal, string $status, string $note ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		ActivityModel::create(
			array(
				'contact_id'    => (int) $proposal->contact_id,
				'activity_type' => ActivityTypes::STATUS_CHANGED,
				'data'          => array(
					'title'       => __( 'Proposal updated', 'doublescale' ),
					'type'        => 'system',
					'note'        => $note,
					'proposal_id' => (int) $proposal->id,
					'status'      => $status,
				),
				'user_id'       => null,
			)
		);
		// TODO(morph): wire proposal/invoice associations (ENTITY_TYPE_PROPOSAL).
	}

	/**
	 * @return bool
	 */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for rate-limit key.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'ds_sales_pub_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count > 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
