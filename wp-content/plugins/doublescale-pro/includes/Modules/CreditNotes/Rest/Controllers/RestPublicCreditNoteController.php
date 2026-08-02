<?php
/**
 * Public guest access to credit notes via hash.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use DoubleScale\Pro\Modules\CreditNotes\Rest\CreditNoteShaper;
use DoubleScale\Pro\Modules\CreditNotes\Services\CreditNotePdf;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPublicCreditNoteController class.
 */
class RestPublicCreditNoteController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/public/credit-notes';

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
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$credit_note = $this->resolve_by_hash( $request );
		if ( is_wp_error( $credit_note ) ) {
			return $credit_note;
		}

		if ( empty( $credit_note->viewed_at ) ) {
			$credit_note->viewed_at = current_time( 'mysql' );
			$credit_note->save();
		}

		return new WP_REST_Response( CreditNoteShaper::shape_public( $credit_note ), 200 );
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
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$credit_note = $this->resolve_by_hash( $request );
		if ( is_wp_error( $credit_note ) ) {
			return $credit_note;
		}

		return CreditNotePdf::rest_response( CreditNoteShaper::shape_public( $credit_note ), (string) $credit_note->credit_note_number );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return CreditNoteModel|WP_Error
	 */
	private function resolve_by_hash( WP_REST_Request $request ) {
		$hash        = (string) $request->get_param( 'hash' );
		$credit_note = CreditNoteModel::get_by_hash( $hash );
		if ( ! $credit_note ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( CreditNoteStatus::DRAFT === (string) $credit_note->status ) {
			return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$credit_note->loadMissing( 'contact' );

		return $credit_note;
	}

	/**
	 * @return bool
	 */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for rate-limit key.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'ds_sales_pub_cn_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count > 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
