<?php
/**
 * Public guest access to support tickets via ticket hash.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\AttachmentService;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestGuestController class.
 */
class RestGuestController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'support/public/tickets';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ticket' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/conversation',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})/replies',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_reply' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_ticket( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$ticket = $this->resolve_ticket_by_hash( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		return new WP_REST_Response( $this->shape_public_ticket( $ticket ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$ticket = $this->resolve_ticket_by_hash( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$allowed = array( ActivityTypes::SUPPORT_REPLY, ActivityTypes::SUPPORT_EVENT );
		$query   = ActivityModel::forTicket( $ticket->id )
			->whereIn( 'activity_type', $allowed )
			->with( 'user' );

		$paginator       = $query->paginate( 100, array( '*' ), 'page', 1 );
		$items           = $paginator->items();
		$ids             = array_map(
			static function ( $activity ) {
				return (int) $activity->id;
			},
			$items
		);
		$attachments_map = ( new AttachmentService() )->map_for_activities( $ids );

		$data = array();
		foreach ( $items as $activity ) {
			$data[] = $this->shape_public_activity( $activity, $attachments_map );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'        => $paginator->total(),
					'per_page'     => 100,
					'current_page' => 1,
					'last_page'    => max( 1, (int) ceil( $paginator->total() / 100 ) ),
					'ticket_id'    => (int) $ticket->id,
				),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_reply( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$ticket = $this->resolve_ticket_by_hash( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$content = isset( $params['content'] ) ? wp_kses_post( (string) $params['content'] ) : '';
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'missing_content', __( 'Please type a reply first.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$payload = array(
			'content'        => $content,
			'source'         => 'web',
			'author_user_id' => null,
		);
		if ( ! empty( $params['attachment_hashes'] ) && is_array( $params['attachment_hashes'] ) ) {
			$payload['attachment_hashes'] = $params['attachment_hashes'];
		}

		$activity = $this->service()->add_reply( $ticket, $payload );
		if ( is_wp_error( $activity ) ) {
			return $activity;
		}

		if ( in_array( $ticket->status, array( TicketStatus::RESOLVED, TicketStatus::CLOSED ), true ) ) {
			$this->service()->update_ticket( $ticket, array( 'status' => TicketStatus::OPEN ) );
		}

		$attachments_map = ( new AttachmentService() )->map_for_activities( array( (int) $activity->id ) );
		return new WP_REST_Response( $this->shape_public_activity( $activity, $attachments_map ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return TicketModel|WP_Error
	 */
	private function resolve_ticket_by_hash( WP_REST_Request $request ) {
		$hash   = (string) $request->get_param( 'hash' );
		$ticket = TicketModel::get_by_hash( $hash );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		return $ticket;
	}

	/**
	 * @param TicketModel $ticket Ticket.
	 * @return array
	 */
	private function shape_public_ticket( TicketModel $ticket ): array {
		$ticket->loadMissing( 'contact' );

		return array(
			'id'             => (int) $ticket->id,
			'hash'           => (string) $ticket->hash,
			'title'          => (string) $ticket->title,
			'status'         => (string) $ticket->status,
			'priority'       => (string) $ticket->priority,
			'response_count' => (int) $ticket->response_count,
			'customer'       => array(
				'display_name' => $this->contact_display_name( $ticket ),
			),
			'created_at'     => $ticket->created_at ? (string) $ticket->created_at : null,
			'updated_at'     => $ticket->updated_at ? (string) $ticket->updated_at : null,
		);
	}

	/**
	 * @param ActivityModel                                $activity Activity.
	 * @param array<int, array<int, array<string, mixed>>> $attachments_map Attachments by activity id.
	 * @return array
	 */
	private function shape_public_activity( ActivityModel $activity, array $attachments_map ): array {
		$kind_map = array(
			ActivityTypes::SUPPORT_REPLY => 'reply',
			ActivityTypes::SUPPORT_EVENT => 'event',
		);
		$kind     = $kind_map[ $activity->activity_type ] ?? 'event';
		$data     = is_array( $activity->data ) ? $activity->data : array();

		$is_customer = $this->activity_is_customer_authored( $activity );

		// Resolve the `user` shown to the guest. We expose only the WP
		// display_name — never the agent's WP id or email:
		// - Customer-authored row → the customer's own id (their reply).
		// - Staff REPLY → the agent's display_name, so the customer sees who
		// helped them (no id, no email).
		// - Events (ticket created, status changed, …) → authorless system rows.
		$user = null;
		if ( $is_customer ) {
			if ( $activity->relationLoaded( 'user' ) && $activity->user ) {
				$user = array(
					'id'           => (int) $activity->user->ID,
					'display_name' => (string) $activity->user->display_name,
				);
			}
		} elseif (
			ActivityTypes::SUPPORT_REPLY === $activity->activity_type
			&& $activity->relationLoaded( 'user' )
			&& $activity->user
		) {
			$user = array(
				'display_name' => (string) $activity->user->display_name,
			);
		}

		$payload = array(
			'id'          => (int) $activity->id,
			'kind'        => $kind,
			'type'        => $activity->activity_type,
			'user_id'     => $is_customer && $activity->user_id ? (int) $activity->user_id : null,
			'data'        => $data,
			'is_self'     => $is_customer,
			'is_customer' => $is_customer,
			'user'        => $user,
			'created_at'  => $activity->created_at ? (string) $activity->created_at : null,
			'updated_at'  => $activity->updated_at ? (string) $activity->updated_at : null,
		);

		$aid = (int) $activity->id;
		if ( isset( $attachments_map[ $aid ] ) ) {
			$payload['attachments'] = $attachments_map[ $aid ];
		} else {
			$payload['attachments'] = array();
		}

		return $payload;
	}

	/**
	 * Customer-authored when there is no WP user, or the user is not support staff.
	 *
	 * @param ActivityModel $activity Activity row.
	 * @return bool
	 */
	private function activity_is_customer_authored( ActivityModel $activity ): bool {
		if ( empty( $activity->user_id ) ) {
			return true;
		}

		return ! Permissions::has_support_access( (int) $activity->user_id );
	}

	/**
	 * Human-readable customer name for the ticket owner.
	 *
	 * @param TicketModel $ticket Ticket with contact loaded.
	 * @return string
	 */
	private function contact_display_name( TicketModel $ticket ): string {
		$contact = $ticket->contact;
		if ( ! $contact ) {
			return '';
		}

		$name = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
		if ( '' !== $name ) {
			return $name;
		}

		return ! empty( $contact->email ) ? (string) $contact->email : '';
	}

	/**
	 * @return bool
	 */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for rate-limit key.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'ds_support_pub_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count > 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * @return TicketService
	 */
	private function service(): TicketService {
		$override = apply_filters( 'doublescale_support_ticket_service_instance', null );
		if ( $override instanceof TicketService ) {
			return $override;
		}
		return new TicketService( new ContactResolver() );
	}
}
