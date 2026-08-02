<?php
/**
 * Client Portal booking endpoints.
 *
 *   GET  /doublescale/v1/portal/bookings                  (filter: upcoming|past|cancelled)
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
use DoubleScale\Modules\Booking\Models\BookedSlotModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalBookingController.
 */
class RestPortalBookingController extends RestController {

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
							'enum'    => array( 'upcoming', 'past', 'cancelled' ),
							'default' => 'upcoming',
						),
					),
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

		$query = BookingModel::with( array( 'event', 'order' ) )
			->where( 'contact_id', (int) $contact->id );

		// `active()` excludes BOTH `cancelled` and `waiting` (see
		// BookingModel::NON_ACTIVE_STATUSES), which previously left waitlisted
		// bookings invisible in every tab. Fold them in by excluding only
		// `cancelled`, so a waiting booking surfaces in Upcoming (future slot)
		// or Past (slot already elapsed) with its own "Waiting" status badge.
		switch ( $filter ) {
			case 'cancelled':
				$query->where( 'status', 'cancelled' )->orderBy( 'start_time', 'desc' );
				break;
			case 'past':
				$query->whereNotIn( 'status', array( 'cancelled' ) )->where( 'end_time', '<', $now )->orderBy( 'start_time', 'desc' );
				break;
			case 'upcoming':
			default:
				$query->whereNotIn( 'status', array( 'cancelled' ) )->where( 'end_time', '>=', $now )->orderBy( 'start_time', 'asc' );
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

		$can_modify = ! $booking->isCancelled() && ! $booking->isCompleted();

		return array(
			'id'             => (int) $booking->id,
			'hash_id'        => (string) $booking->hash_id,
			'status'         => (string) $booking->status,
			'event'          => array(
				'name'     => $event ? (string) $event->name : __( 'Booking', 'doublescale' ),
				'duration' => $event ? (int) $event->duration : null,
			),
			'start_time'     => (string) $booking->start_time,
			'end_time'       => (string) $booking->end_time,
			'timezone'       => $booking->timezone ? (string) $booking->timezone : 'UTC',
			'location'       => $this->shape_location( $booking->location ),
			'payment'        => $payment,
			'can_cancel'     => $can_modify,
			'can_reschedule' => $can_modify,
		);
	}

	/**
	 * Normalise the booking location meta into `{ label, value }` or null.
	 *
	 * @param mixed $location Raw location meta (array|string|null).
	 * @return array<string, string>|null
	 */
	private function shape_location( $location ): ?array {
		if ( is_array( $location ) ) {
			$label = isset( $location['label'] ) ? (string) $location['label'] : '';
			$value = isset( $location['value'] ) ? (string) $location['value'] : '';
			if ( '' === $label && '' === $value ) {
				return null;
			}
			return array(
				'label' => $label,
				'value' => $value,
			);
		}

		if ( is_string( $location ) && '' !== $location ) {
			return array(
				'label' => '',
				'value' => $location,
			);
		}

		return null;
	}
}
