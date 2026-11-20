<?php
/**
 * Saved replies for the team.
 *
 *   GET    /support/saved-replies            Theirs and the shared ones (search).
 *   POST   /support/saved-replies            Save one (title, content, shared).
 *   PUT    /support/saved-replies/{id}       Change one they may change.
 *   DELETE /support/saved-replies/{id}       Delete one they may change.
 *   POST   /support/saved-replies/{id}/use   Its text for a ticket, filled in; counts the use.
 *
 * Rules: SavedReplies.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Models\SavedReplyModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\SavedReplies;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestSavedReplyController class.
 */
class RestSavedReplyController extends RestController {

	/** Longest title, in characters. */
	private const TITLE_CHARS = 120;

	/**
	 * @var string
	 */
	protected $rest_base = 'support/saved-replies';

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
					'permission_callback' => array( $this, 'team_only' ),
					'args'                => array(
						'search' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'team_only' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'team_only' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'team_only' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/use',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'use_item' ),
					'permission_callback' => array( $this, 'team_only' ),
					'args'                => array(
						'ticket_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Only those who work tickets.
	 *
	 * @return bool|WP_Error
	 */
	public function team_only() {
		return Permissions::has_support_access() ? true : new WP_Error( 'rest_forbidden', __( 'Only your support team can use saved replies.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		SavedReplies::ensure_table();
		$me   = get_current_user_id();
		$data = array_map(
			static function ( SavedReplyModel $reply ) use ( $me ) {
				return SavedReplies::shape( $reply, $me );
			},
			SavedReplies::list_for( $me, (string) $request->get_param( 'search' ) )
		);

		return new WP_REST_Response(
			array(
				'data'      => $data,
				'can_share' => SavedReplies::can_share( $me ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		SavedReplies::ensure_table();
		$me     = get_current_user_id();
		$fields = $this->fields( $request, null );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( ! empty( $fields['shared'] ) && ! SavedReplies::can_share( $me ) ) {
			return $this->not_allowed();
		}

		$reply = SavedReplyModel::create( array_merge( array( 'shared' => false ), $fields, array( 'user_id' => $me, 'use_count' => 0 ) ) );
		if ( empty( $reply->id ) ) {
			return new WP_Error( 'saved_reply_failed', __( 'The reply could not be saved. Please try again.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( SavedReplies::shape( SavedReplyModel::find( (int) $reply->id ) ?? $reply, $me ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		SavedReplies::ensure_table();
		$me    = get_current_user_id();
		$reply = $this->reply( $request );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		if ( ! SavedReplies::can_change( $reply, $me ) ) {
			return $this->not_allowed();
		}
		$fields = $this->fields( $request, $reply );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( array_key_exists( 'shared', $fields ) && (bool) $fields['shared'] !== (bool) $reply->shared && ! SavedReplies::can_share( $me ) ) {
			return $this->not_allowed();
		}

		$reply->fill( $fields );
		$reply->save();

		return new WP_REST_Response( SavedReplies::shape( $reply, $me ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		SavedReplies::ensure_table();
		$reply = $this->reply( $request );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		if ( ! SavedReplies::can_change( $reply, get_current_user_id() ) ) {
			return $this->not_allowed();
		}
		$reply->delete();

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * The reply's text for one ticket, placeholders filled; counts the use.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function use_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		SavedReplies::ensure_table();
		$me    = get_current_user_id();
		$reply = $this->reply( $request );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		if ( ! SavedReplies::can_use( $reply, $me ) ) {
			return $this->not_found();
		}
		$ticket = TicketModel::find( (int) $request->get_param( 'ticket_id' ) );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		// The same rule as reading the ticket: their own, unless they see every ticket.
		if ( ! Permissions::can_manage_all_tickets() && (int) $ticket->agent_user_id !== $me ) {
			return new WP_Error( 'not_allowed', __( 'You can only access tickets assigned to you.', 'doublescale' ), array( 'status' => 403 ) );
		}

		SavedReplies::used( $reply );

		return new WP_REST_Response( array( 'content' => SavedReplies::fill( $reply, $ticket, $me ) ), 200 );
	}

	/**
	 * Title, content and shared from the request, cleaned; only the ones sent
	 * when changing a reply.
	 *
	 * @param WP_REST_Request      $request Request.
	 * @param SavedReplyModel|null $reply   The reply being changed, null for a new one.
	 * @return array<string, mixed>|WP_Error
	 */
	private function fields( WP_REST_Request $request, ?SavedReplyModel $reply ) {
		$fields = array();
		if ( null === $reply || $request->has_param( 'title' ) ) {
			$title = mb_substr( trim( sanitize_text_field( wp_strip_all_tags( (string) $request->get_param( 'title' ) ) ) ), 0, self::TITLE_CHARS, 'UTF-8' );
			if ( '' === $title ) {
				return new WP_Error( 'missing_title', __( 'Give the reply a title.', 'doublescale' ), array( 'status' => 400 ) );
			}
			$fields['title'] = $title;
		}
		if ( null === $reply || $request->has_param( 'content' ) ) {
			$content = wp_kses_post( (string) $request->get_param( 'content' ) );
			if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
				return new WP_Error( 'missing_content', __( 'Write the reply.', 'doublescale' ), array( 'status' => 400 ) );
			}
			if ( strlen( $content ) > SavedReplies::MAX_CONTENT ) {
				return new WP_Error( 'content_too_long', __( 'This reply is too long to save. Make it shorter.', 'doublescale' ), array( 'status' => 400 ) );
			}
			$fields['content'] = $content;
		}
		if ( $request->has_param( 'shared' ) ) {
			$fields['shared'] = rest_sanitize_boolean( $request->get_param( 'shared' ) );
		}

		return $fields;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return SavedReplyModel|WP_Error
	 */
	private function reply( WP_REST_Request $request ) {
		$reply = SavedReplyModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $reply || ! SavedReplies::can_use( $reply, get_current_user_id() ) ) {
			return $this->not_found();
		}
		return $reply;
	}

	/** @return WP_Error */
	private function not_found(): WP_Error {
		return new WP_Error( 'not_found', __( 'Saved reply not found.', 'doublescale' ), array( 'status' => 404 ) );
	}

	/** @return WP_Error */
	private function not_allowed(): WP_Error {
		return new WP_Error( 'not_allowed', __( 'Only the person who saved it can change it; shared replies are changed by a support manager.', 'doublescale' ), array( 'status' => 403 ) );
	}
}
