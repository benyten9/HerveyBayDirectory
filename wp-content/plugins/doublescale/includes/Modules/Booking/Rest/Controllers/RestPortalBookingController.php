<?php
/**
 * Client Portal booking endpoints.
 *
 *   GET  /doublescale/v1/portal/bookings                  (filter: all|upcoming|past|cancelled)
 *   GET  /doublescale/v1/portal/bookings/{id}             (one booking, ownership-gated)
 *   POST /doublescale/v1/portal/bookings/{id}/cancel      (attendee cancel)
 *   GET  /doublescale/v1/portal/bookings/{id}/reschedule-url
 *
 * All routes reuse {@see PortalIdentity} for the login + lowercased-email
 * contact resolve, and gate ownership on `contact_id` returning 404 (not 403)
 * on a mismatch so booking ids can't be enumerated. Payloads are shaped to the
 * data a customer may see — no host emails, internal logs, or gateway internals.
 *
 * @package DoubleScale\Modules\Booking
 */

namespace DoubleScale\Modules\Booking\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Booking\Data\LocationLink;
use DoubleScale\Modules\Booking\Models\BookedSlotModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Services\AttendeeChangePolicy;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Portal\Services\PortalBookingOptions;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalBookingController.
 */
class RestPortalBookingController extends RestController {

	/** Bookings that are off: cancelled, or declined by the host. */
	public const OFF_STATUSES = array( 'cancelled', 'rejected' );

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bookings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bookings' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => array(
						'filter' => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'upcoming', 'past', 'cancelled' ),
							'default' => 'all',
						),
					),
				),
			)
		);

		// What the client may book from the portal (Settings → Client Portal → Book a meeting).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bookable-events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bookable_events' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bookings/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_booking' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bookings/(?P<id>[\d]+)/cancel',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_booking' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bookings/(?P<id>[\d]+)/reschedule-url',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_reschedule_url' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * The meetings this client may book, each with its booking page.
	 *
	 * @return WP_REST_Response
	 */
	public function get_bookable_events() {
		return new WP_REST_Response(
			array( 'data' => PortalBookingOptions::for_contact( PortalIdentity::current_contact() ) ),
			200
		);
	}

	/**
	 * List the contact's bookings for one tab.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_bookings( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'booking' );
		if ( $disabled ) {
			return $disabled;
		}

		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_REST_Response( array( 'data' => array() ), 200 );
		}

		$filter = (string) $request->get_param( 'filter' );
		$now    = gmdate( 'Y-m-d H:i:s' );

		$query = BookingModel::with( array( 'event', 'order', 'hosts' ) )
			->where( 'contact_id', (int) $contact->id );

		// `active()` excludes BOTH `cancelled` and `waiting` (see
		// BookingModel::NON_ACTIVE_STATUSES), which previously left waitlisted
		// bookings invisible in every tab. Fold them in by excluding only
		// `cancelled`, so a waiting booking surfaces in Upcoming (future slot)
		// or Past (slot already elapsed) with its own "Waiting" status badge.
		switch ( $filter ) {
			case 'all':
				$query->orderBy( 'start_time', 'desc' );
				break;
			// A booking the host declined is as off as a cancelled one.
			case 'cancelled':
				$query->whereIn( 'status', self::OFF_STATUSES )->orderBy( 'start_time', 'desc' );
				break;
			case 'past':
				$query->whereNotIn( 'status', self::OFF_STATUSES )->where( 'end_time', '<', $now )->orderBy( 'start_time', 'desc' );
				break;
			case 'upcoming':
			default:
				$query->whereNotIn( 'status', self::OFF_STATUSES )->where( 'end_time', '>=', $now )->orderBy( 'start_time', 'asc' );
				break;
		}

		$bookings = $query->limit( 100 )->get();

		$data = array();
		foreach ( $bookings as $booking ) {
			$data[] = $this->shape_booking( $booking );
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * One booking detail.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_booking( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'booking' );
		if ( $disabled ) {
			return $disabled;
		}

		$booking = $this->resolve_own_booking( $request );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}

		return new WP_REST_Response( $this->shape_booking( $booking ), 200 );
	}

	/**
	 * Cancel a booking as the attendee. Replicates the public attendee cancel
	 * path ({@see \DoubleScale\Modules\Booking\Services\BookingAjax::ajax_cancel_booking})
	 * so slot release, lifecycle event, integrations and notifications all fire.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_booking( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'booking' );
		if ( $disabled ) {
			return $disabled;
		}

		$booking = $this->resolve_own_booking( $request );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}

		if ( $booking->isCancelled() ) {
			return new WP_Error( 'already_cancelled', __( 'This booking is already cancelled.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( $booking->isCompleted() ) {
			return new WP_Error( 'already_completed', __( 'Past bookings cannot be cancelled.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// The event's "Attendee cannot cancel" rule, as on the public pages.
		$policy = AttendeeChangePolicy::for_booking( $booking, AttendeeChangePolicy::CANCEL );
		if ( ! $policy['allowed'] ) {
			return new WP_Error( 'booking_cancel_not_allowed', $policy['message'], array( 'status' => 403 ) );
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'cancellation_reason' ) );
		if ( '' !== $reason ) {
			$booking->update_meta( 'cancellation_reason', $reason );
		}

		$was_waiting           = $booking->isWaiting();
		$booking->cancelled_by = 'attendee';
		$booking->status       = 'cancelled';
		$booking->save();

		BookedSlotModel::release( (int) $booking->id );

		$booking->logs()->create(
			array(
				'type'    => 'info',
				'message' => __( 'Booking cancelled', 'doublescale' ),
				'details' => __( 'Booking cancelled by Attendee via client portal', 'doublescale' ),
			)
		);

		BookingEvents::emit( 'cancelled', (int) $booking->id, array( 'actor' => 'attendee' ) );

		if ( $was_waiting && method_exists( BookingModel::class, 'rebalanceWaitingListPositions' ) ) {
			BookingModel::rebalanceWaitingListPositions( $booking );
		}

		return new WP_REST_Response(
			array(
				'message' => __( 'Booking cancelled', 'doublescale' ),
				'booking' => $this->shape_booking( $booking->fresh( array( 'event', 'order' ) ) ),
			),
			200
		);
	}

	/**
	 * Return the existing public reschedule URL (hash flow) — we don't
	 * reimplement rescheduling in the portal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_reschedule_url( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'booking' );
		if ( $disabled ) {
			return $disabled;
		}

		$booking = $this->resolve_own_booking( $request );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}

		if ( $booking->isCancelled() || $booking->isCompleted() ) {
			return new WP_Error( 'not_reschedulable', __( 'This booking can no longer be rescheduled.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$policy = AttendeeChangePolicy::for_booking( $booking, AttendeeChangePolicy::RESCHEDULE );
		if ( ! $policy['allowed'] ) {
			return new WP_Error( 'booking_reschedule_not_allowed', $policy['message'], array( 'status' => 403 ) );
		}

		return new WP_REST_Response( array( 'url' => (string) $booking->getRescheduleUrl() ), 200 );
	}

	/**
	 * Resolve a booking owned by the current contact, or a 404 WP_Error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return BookingModel|WP_Error
	 */
	private function resolve_own_booking( WP_REST_Request $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return PortalIdentity::not_found( __( 'Booking not found.', 'doublescale' ) );
		}

		$id      = (int) $request->get_param( 'id' );
		$booking = BookingModel::with( array( 'event', 'order' ) )
			->where( 'id', $id )
			->where( 'contact_id', (int) $contact->id )
			->first();

		if ( ! $booking instanceof BookingModel ) {
			return PortalIdentity::not_found( __( 'Booking not found.', 'doublescale' ) );
		}

		return $booking;
	}

	/**
	 * Shape a booking into a customer-safe payload.
	 *
	 * @param BookingModel $booking Booking.
	 * @return array<string, mixed>
	 */
	private function shape_booking( BookingModel $booking ): array {
		$event = $booking->event;
		$order = $booking->order;

		$payment = null;
		if ( $order ) {
			$payment = array(
				'total'    => $order->total,
				'currency' => $order->currency,
				'status'   => (string) $order->status,
			);
		}

		// Over, declined or under way first, then the event's attendee rules,
		// each with its own message.
		$cancel     = AttendeeChangePolicy::for_booking( $booking, AttendeeChangePolicy::CANCEL );
		$reschedule = AttendeeChangePolicy::for_booking( $booking, AttendeeChangePolicy::RESCHEDULE );

		return array(
			'id'             => (int) $booking->id,
			'hash_id'        => (string) $booking->hash_id,
			'status'         => (string) $booking->status,
			'event'          => array(
				'name'     => $event ? (string) $event->name : __( 'Booking', 'doublescale' ),
				'duration' => $event ? (int) $event->duration : null,
			),
			// The booking's own length: the client may have picked another
			// duration than the event's default.
			'duration'       => self::minutes( $booking, $event ),
			// Who the client meets (display names only).
			'hosts'          => self::host_names( $booking ),
			'start_time'     => (string) $booking->start_time,
			'end_time'       => (string) $booking->end_time,
			'timezone'       => $booking->timezone ? (string) $booking->timezone : 'UTC',
			'location'       => $this->shape_location( $booking->location, $booking ),
			'payment'        => $payment,
			'can_cancel'                => $cancel['allowed'],
			'cancel_denied_message'     => $cancel['message'],
			'can_reschedule'            => $reschedule['allowed'],
			'reschedule_denied_message' => $reschedule['message'],
			// Over or off: the same meeting again, when the client may still book it.
			'book_again_url'            => self::is_over( $booking )
				? PortalBookingOptions::book_again_url( $event, PortalIdentity::current_contact() )
				: null,
		);
	}

	/**
	 * Whether the meeting is behind the client: ended, completed or cancelled.
	 *
	 * @param BookingModel $booking Booking.
	 * @return bool
	 */
	private static function is_over( BookingModel $booking ): bool {
		if ( in_array( (string) $booking->status, array( 'completed', 'cancelled', 'rejected', 'no-show' ), true ) ) {
			return true;
		}
		$end = strtotime( (string) $booking->end_time . ' UTC' );

		return $end && $end <= time();
	}

	/**
	 * The booking's length in minutes: its own slot, else its start and end,
	 * else the event's default.
	 *
	 * @param BookingModel $booking Booking.
	 * @param mixed        $event   Its event, if any.
	 * @return int|null
	 */
	private static function minutes( BookingModel $booking, $event ): ?int {
		if ( (int) $booking->slot_time > 0 ) {
			return (int) $booking->slot_time;
		}
		$span = strtotime( (string) $booking->end_time ) - strtotime( (string) $booking->start_time );
		if ( $span > 0 ) {
			return (int) round( $span / MINUTE_IN_SECONDS );
		}

		return $event && (int) $event->duration > 0 ? (int) $event->duration : null;
	}

	/**
	 * The hosts' display names.
	 *
	 * @param BookingModel $booking Booking.
	 * @return string[]
	 */
	private static function host_names( BookingModel $booking ): array {
		$names = array();
		foreach ( $booking->hosts as $host ) {
			$name = trim( (string) ( $host->display_name ?? '' ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Normalise the booking location meta into `{ type, label, value, url,
	 * kind }` or null. `url` is what the place opens and `kind` what that is
	 * (see LocationLink): the meeting, a map of the host's address, or a call
	 * to the host's phone. Both are null when the place opens nothing.
	 *
	 * @param mixed             $location Raw location meta (array|string|null).
	 * @param BookingModel|null $booking  Booking (for the Zoom link written later).
	 * @return array<string, string>|null
	 */
	private function shape_location( $location, ?BookingModel $booking = null ): ?array {
		if ( is_string( $location ) && '' !== $location ) {
			$location = array( 'value' => $location );
		}
		if ( ! is_array( $location ) ) {
			return null;
		}

		$type  = isset( $location['type'] ) ? strtolower( (string) $location['type'] ) : '';
		$label = isset( $location['label'] ) ? (string) $location['label'] : '';
		$value = isset( $location['value'] ) ? (string) $location['value'] : '';

		// As the public confirm page: the Zoom link is written after the
		// booking (Action Scheduler), on the booking's Zoom details.
		if ( 'zoom' === $type && ! LocationLink::for_location( $type, $value ) && $booking ) {
			$details  = (array) $booking->get_meta( 'zoom_event_details', array() );
			$join_url = (string) ( $details['meeting']['join_url'] ?? '' );
			if ( LocationLink::for_location( $type, $join_url ) ) {
				$value = $join_url;
				$label = '' !== $label ? $label : __( 'Zoom', 'doublescale' );
			}
		}

		if ( '' === $label && '' === $value ) {
			return null;
		}

		$link = LocationLink::for_location( $type, $value );

		return array(
			'type'  => $type,
			'label' => $label,
			'value' => $value,
			'url'   => $link ? $link['url'] : null,
			'kind'  => $link ? $link['kind'] : null,
		);
	}
}
