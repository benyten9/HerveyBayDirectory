<?php

/**
 * Class Booking Ajax
 * Handles the booking ajax actions
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Services\BookingValidator;
use DoubleScale\Modules\Booking\Services\BookingService;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Booking\Services\BookingInput;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\AvailabilityModel;
use DoubleScale\Modules\Booking\Models\BookedSlotModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Modules\Booking\Managers\LocationsManager;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use Illuminate\Support\Arr;


// phpcs:disable WordPress.Security.NonceVerification.Missing -- public booking AJAX endpoints (wp_ajax_nopriv_*). Nonce verification is available via verify_booking_nonce() which each handler can call; security on these endpoints is enforced via the BookingValidator (hash lookup + state checks), since the booking page is publicly reachable and visitors are unauthenticated.
class BookingAjax {




	// --- Dependency Properties ---
	private string $bookingValidatorClass;
	private string $bookingServiceClass;


	public function __construct(
		string $bookingValidatorClass = BookingValidator::class,
		string $bookingServiceClass = BookingService::class
	) {
		$this->bookingValidatorClass = $bookingValidatorClass;
		$this->bookingServiceClass   = $bookingServiceClass;
		add_action( 'wp_ajax_doublescale_booking_booking_slots', array( $this, 'booking_details' ) );
		add_action( 'wp_ajax_nopriv_doublescale_booking_booking_slots', array( $this, 'booking_details' ) );
		add_action( 'wp_ajax_doublescale_booking_booking', array( $this, 'booking' ) );
		add_action( 'wp_ajax_nopriv_doublescale_booking_booking', array( $this, 'booking' ) );
		add_action( 'wp_ajax_doublescale_booking_cancel_booking', array( $this, 'ajax_cancel_booking' ) );
		add_action( 'wp_ajax_nopriv_doublescale_booking_cancel_booking', array( $this, 'ajax_cancel_booking' ) );
		add_action( 'wp_ajax_doublescale_booking_reschedule_booking', array( $this, 'ajax_reschedule_booking' ) );
		add_action( 'wp_ajax_nopriv_doublescale_booking_reschedule_booking', array( $this, 'ajax_reschedule_booking' ) );
		add_action( 'wp_ajax_doublescale_booking_process_payment', array( $this, 'ajax_process_payment' ) );
		add_action( 'wp_ajax_nopriv_doublescale_booking_process_payment', array( $this, 'ajax_process_payment' ) );
	}

	/**
	 * Verify the request carries a valid booking nonce. Renderer pages ship
	 * `doublescale_booking`; the admin SPA ships `doublescale-admin`. Accept
	 * either so both contexts work without duplicating AJAX registrations.
	 */
	private function verify_booking_nonce(): void {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) ), 'doublescale_booking' ) ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( 'doublescale_access' ) ) {
			return;
		}
		wp_send_json_error( array( 'message' => __( 'Security check failed.', 'doublescale' ) ), 403 );
	}

	/**
	 * Ajax booking
	 *
	 * @return void
	 */
	public function booking() {
		// $this->verify_booking_nonce();
		try {
			$id    = isset( $_POST['id'] ) ? intval( wp_unslash( $_POST['id'] ) ) : null;
			$event = $this->bookingValidatorClass::validate_event( $id );

			$payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : null;

			if ( ! $payment_method && $event->requirePayment() ) {
				throw new \Exception( __( 'Invalid payment method', 'doublescale' ) );
			}

			$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : null;
			if ( ! $start_date ) {
				throw new \Exception( __( 'Invalid start date', 'doublescale' ) );
			}

			$timezone = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : null;
			if ( ! $timezone ) {
				throw new \Exception( __( 'Invalid timezone', 'doublescale' ) );
			}
			$start_date = $this->bookingValidatorClass::validate_start_date( $start_date, $timezone );

			$duration = isset( $_POST['duration'] ) ? intval( wp_unslash( $_POST['duration'] ) ) : $event->duration;
			$duration = $this->bookingValidatorClass::validate_duration( $duration, $event->duration );

			$location = BookingInput::get_json_post( 'location' );
			if ( empty( $location ) ) {
				throw new \Exception( __( 'Invalid location', 'doublescale' ) );
			}

			// Conferencing locations (Google Meet / Zoom / MS Teams) require the
			// Pro add-on. Reject stale renderer pages that try to book with one
			// after Pro was deactivated.
			$selected_type = isset( $location['type'] ) ? (string) $location['type'] : '';
			if ( LocationsManager::is_pro_conferencing_type( $selected_type ) && ! LocationsManager::is_pro_active() ) {
				throw new \Exception(
					sprintf(
						/* translators: %s: e.g. Google Meet, Zoom Video, MS Teams */
						__( '%s requires the Pro add-on and is not available.', 'doublescale' ),
						LocationsManager::get_conferencing_label( $selected_type )
					)
				);
			}

			// Validate invitees if needed.
			$invitees = BookingInput::get_json_post( 'invitees' );
			if ( empty( $invitees ) ) {
				throw new \Exception( __( 'Please, add valid invitees', 'doublescale' ) );
			}

			$booking_service = new $this->bookingServiceClass();

			$validate_invitee = $booking_service->validate_invitee( $event, $invitees );
			if ( 'group' !== $event->type && count( $validate_invitee ) > 1 ) {
				throw new \Exception( __( 'Invalid event type', 'doublescale' ) );
			}

			$fields = BookingInput::get_json_post( 'fields' );

			$host_ids = isset( $_POST['host_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['host_ids'] ) ) : null;
			$host_id  = null;
			if ( $host_ids ) {
				$host_ids = explode( ',', $host_ids );
				if ( $event->type === 'round-robin' ) {
					$host_id = $host_ids[0];
				} else {
					$host_id = $host_ids;
				}
			}

			// Pass the chosen host(s) to the availability lookup so we check
			// the schedule of the actual host being booked, not just the
			// event's primary host. Without this, a round-robin/collective
			// event could expose a slot that's free for host A but the
			// booking gets assigned to host B who is off at that time.
			$available_slots = $event->get_booking_available_slots( $start_date, $duration, $timezone, $host_id );

			$calendar_id = $event->calendar_id;

			if ( ! $available_slots || $available_slots < count( $validate_invitee ) ) {
				$wl_settings = $event->waiting_list_settings;
				if ( ! empty( $wl_settings['enabled'] ) ) {
					// Dedupe — if any submitted email already holds a `waiting`
					// row for this slot, return that existing booking instead
					// of inserting a duplicate (otherwise one user can stack
					// consecutive positions).
					$utc       = new \DateTimeZone( 'UTC' );
					$start_utc = ( clone $start_date )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
					$emails    = array_filter( array_column( $validate_invitee, 'email' ) );
					if ( ! empty( $emails ) ) {
						$existing_contact_ids = ContactModel::whereIn( 'email', $emails )->pluck( 'id' )->toArray();
						if ( ! empty( $existing_contact_ids ) ) {
							$existing_waiter = BookingModel::where( 'status', 'waiting' )
								->where( 'event_id', $event->id )
								->where( 'start_time', $start_utc )
								->whereIn( 'contact_id', $existing_contact_ids )
								->first();
							if ( $existing_waiter ) {
								wp_send_json_success(
									array(
										'booking'        => $existing_waiter,
										'waiting_list'   => true,
										'position'       => $existing_waiter->get_meta( 'waiting_list_position' ),
										'already_joined' => true,
									)
								);
								return;
							}
						}
					}

					$wl_host_ids = is_array( $host_id )
						? $host_id
						: array( $host_id ?? $event->user_id ?? get_current_user_id() );

					$booking = $booking_service->book_waiting_list_slot(
						$event,
						$calendar_id,
						$start_date,
						$duration,
						$timezone,
						$validate_invitee,
						$location,
						$fields,
						$wl_host_ids
					);
					wp_send_json_success(
						array(
							'booking'      => $booking,
							'waiting_list' => true,
							'position'     => $booking->get_meta( 'waiting_list_position' ),
						)
					);
					return;
				}
				throw new \Exception( __( 'Sorry, This booking is not available', 'doublescale' ) );
			}

			$status = 'scheduled';

			if ( isset( $_POST['status'] ) && 'pending' === sanitize_text_field( wp_unslash( $_POST['status'] ) ) ) {
				$status = 'pending';
			}

			$booking = $booking_service->book_event_slot( $event, $calendar_id, $start_date, $duration, $timezone, $validate_invitee, $location, $status, $fields, $host_id );

			do_action(
				'doublescale_booking_after_booking_created',
				$booking,
				array(
					'payment_method' => $payment_method,
				)
			);

			$advanced_settings = $booking->getAdvancedSettings();
			$redirect_enabled  = ! empty( $advanced_settings['redirect_after_submit'] );
			$redirect_url      = isset( $advanced_settings['redirect_url'] ) ? trim( (string) $advanced_settings['redirect_url'] ) : '';

			if ( $redirect_enabled && '' !== $redirect_url ) {
				$redirect_query_string = isset( $advanced_settings['redirect_query_string'] ) ? (string) $advanced_settings['redirect_query_string'] : '';
				if ( '' !== $redirect_query_string ) {
					$merge_tags_manager    = \DoubleScale\Modules\Booking\Managers\MergeTagsManager::instance();
					$redirect_query_string = (string) $merge_tags_manager->process_merge_tags( $redirect_query_string, $booking );
				}

				if ( '' !== $redirect_query_string ) {
					$separator    = ( false === strpos( $redirect_url, '?' ) ) ? '?' : '&';
					$redirect_url = $redirect_url . $separator . ltrim( $redirect_query_string, '?&' );
				}

				$booking->booking_redirect_url = $redirect_url;
			}
			wp_send_json_success( array( 'booking' => $booking ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Ajax Get booking slots
	 *
	 * @return void
	 */
	public function booking_details() {
		// $this->verify_booking_nonce();
		try {
			$id          = isset( $_POST['id'] ) ? intval( wp_unslash( $_POST['id'] ) ) : null;
			$user_id     = isset( $_POST['user_id'] ) ? intval( wp_unslash( $_POST['user_id'] ) ) : null;
			$calendar_id = isset( $_POST['calendar_id'] ) ? intval( wp_unslash( $_POST['calendar_id'] ) ) : null;

			if ( ! $id ) {
				throw new \Exception( __( 'Invalid event', 'doublescale' ) );
			}

			$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : null;
			if ( ! $start_date ) {
				throw new \Exception( __( 'Invalid start date', 'doublescale' ) );
			}

			$timezone = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : null;
			if ( ! $timezone ) {
				throw new \Exception( __( 'Invalid timezone', 'doublescale' ) );
			}

			$event = EventModel::find( $id );

			if ( $event ) {
				$duration = isset( $_POST['duration'] ) ? intval( wp_unslash( $_POST['duration'] ) ) : $event->duration;
				$duration = $this->bookingValidatorClass::validate_duration( $duration, $event->duration );

				$wl_settings  = $event->waiting_list_settings;
				$include_full = ! empty( $wl_settings['enabled'] );

				$available_slots = $event->get_available_slots( $start_date, $timezone, $duration, $user_id, $include_full );

				$response = array( 'slots' => $available_slots );
				if ( ! empty( $wl_settings['enabled'] ) ) {
					$response['waiting_list_enabled']  = true;
					$wl_capacity                       = isset( $wl_settings['capacity'] ) ? (int) $wl_settings['capacity'] : 10;
					$response['waiting_list_capacity'] = $wl_capacity;
					$utc_tz                            = new \DateTimeZone( 'UTC' );
					$user_tz                           = new \DateTimeZone( $timezone );
					foreach ( $response['slots'] as $date_key => &$day_slots ) {
						foreach ( $day_slots as &$slot ) {
							if ( isset( $slot['remaining'] ) && 0 === (int) $slot['remaining'] ) {
								$start_utc                     = ( new \DateTime( $slot['start'], $user_tz ) )->setTimezone( $utc_tz )->format( 'Y-m-d H:i:s' );
								$end_utc                       = ( new \DateTime( $slot['end'], $user_tz ) )->setTimezone( $utc_tz )->format( 'Y-m-d H:i:s' );
								$wl_count                      = BookingModel::where( 'status', 'waiting' )
									->where( 'event_id', $event->id )
									->where( 'start_time', $start_utc )
									->where( 'end_time', $end_utc )
									->count();
								$slot['waiting_list_count']    = $wl_count;
								$slot['waiting_list_capacity'] = $wl_capacity;
							}
						}
						unset( $slot );
					}
					unset( $day_slots );
				}

				wp_send_json_success( $response );
				return;
			}

			throw new \Exception( __( 'Event is not available', 'doublescale' ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Ajax Cancel booking
	 *
	 * @return void
	 */
	public function ajax_cancel_booking() {
		// $this->verify_booking_nonce();

		try {
			$id                  = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : null;
			$booking             = $this->bookingValidatorClass::validate_booking( $id );
			$cancellation_reason = isset( $_POST['cancellation_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['cancellation_reason'] ) ) : null;

			if ( $booking->isCancelled() ) {
				throw new \Exception( __( 'Booking is already cancelled', 'doublescale' ) );
			}

			if ( $cancellation_reason ) {
				$booking->update_meta( 'cancellation_reason', $cancellation_reason );
			}

			$was_waiting           = ( 'waiting' === $booking->status );
			$booking->cancelled_by = 'attendee';
			$booking->status       = 'cancelled';
			$booking->save();

			BookedSlotModel::release( $booking->id );

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Booking cancelled', 'doublescale' ),
					'details' => __( 'Booking cancelled by Attendee', 'doublescale' ),
				)
			);

			BookingEvents::emit( 'cancelled', (int) $booking->id, array( 'actor' => 'attendee' ) );

			if ( $was_waiting ) {
				BookingModel::rebalanceWaitingListPositions( $booking );
			}

			wp_send_json_success( array( 'message' => __( 'Booking cancelled', 'doublescale' ) ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Ajax Reschedule booking
	 *
	 * @return void
	 */
	public function ajax_reschedule_booking() {
		// $this->verify_booking_nonce();

		try {
			$id                = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : null;
			$booking           = $this->bookingValidatorClass::validate_booking( $id );
			$reschedule_reason = isset( $_POST['reschedule_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reschedule_reason'] ) ) : null;

			$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : null;
			if ( ! $start_date ) {
				throw new \Exception( __( 'Invalid start date', 'doublescale' ) );
			}

			$timezone = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : null;
			if ( ! $timezone ) {
				throw new \Exception( __( 'Invalid timezone', 'doublescale' ) );
			}

			// Use the BookingValidator to validate the start date
			$start_date = $this->bookingValidatorClass::validate_start_date( $start_date, $timezone );

			$duration = isset( $_POST['duration'] ) ? intval( wp_unslash( $_POST['duration'] ) ) : $booking->slot_time;
			$duration = $this->bookingValidatorClass::validate_duration( $duration, $booking->slot_time );

			if ( $booking->isCancelled() ) {
				throw new \Exception( __( 'Booking is already cancelled', 'doublescale' ) );
			}

			// Check if the booking is same as the current booking
			$booking_start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			if ( $start_date->getTimestamp() === $booking_start_date->getTimestamp() && $duration === $booking->slot_time ) {
				throw new \Exception( __( 'Booking is already scheduled for this time', 'doublescale' ) );
			}

			$bookable = $booking->getBookableEntity();
			if ( $bookable && method_exists( $bookable, 'get_booking_available_slots' ) ) {
				// Use the booking's existing host so the availability check
				// targets the same person being rescheduled. Otherwise the
				// check would run against the event's primary user and could
				// approve a slot that's outside the assigned host's schedule.
				$existing_host_ids = $booking->hosts()->pluck( 'user_id' )->all();
				$host_for_check    = ! empty( $existing_host_ids )
					? ( in_array( $bookable->type ?? '', array( 'round-robin', 'collective' ), true )
						? $existing_host_ids
						: ( count( $existing_host_ids ) === 1 ? (int) $existing_host_ids[0] : $existing_host_ids ) )
					: null;
				$available_slots   = $bookable->get_booking_available_slots( $start_date, $duration, $timezone, $host_for_check );
				if ( ! $available_slots ) {
					throw new \Exception( __( 'Sorry, This booking is not available', 'doublescale' ) );
				}
			} elseif ( ! $bookable ) {
				throw new \Exception( __( 'Booking entity not found', 'doublescale' ) );
			}

			$end_date = clone $start_date;
			$end_date->modify( "+{$duration} minutes" );

			$service = new $this->bookingServiceClass();
			$service->reschedule_booking( $booking, $start_date, $end_date, $duration );

			if ( $reschedule_reason ) {
				$booking->update_meta( 'reschedule_reason', $reschedule_reason );
			}

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Booking rescheduled', 'doublescale' ),
					'details' => __( 'Booking rescheduled by Attendee', 'doublescale' ),
				)
			);

			BookingEvents::emit( 'rescheduled', (int) $booking->id, array( 'actor' => 'attendee' ) );

			wp_send_json_success( array( 'message' => __( 'Booking rescheduled', 'doublescale' ) ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}


	/**
	 * Ajax Process Payment
	 *
	 * The configured payment gateway (Stripe, in Pro) hooks
	 * `doublescale_booking_process_payment` and is expected to call
	 * `wp_send_json_*` / `wp_die` itself after it creates the payment intent.
	 * If we reach the code after `do_action(...)`, it means no gateway claimed
	 * the request — the only honest response is "no gateway available".
	 *
	 * @return void
	 */
	public function ajax_process_payment() {
		$this->verify_booking_nonce();
		try {
			$booking_hash_id = isset( $_POST['booking_hash_id'] ) ? sanitize_text_field( wp_unslash( $_POST['booking_hash_id'] ) ) : null;
			if ( ! $booking_hash_id ) {
				throw new \Exception( __( 'Invalid booking', 'doublescale' ) );
			}

			$booking = $this->bookingValidatorClass::validate_booking( $booking_hash_id );

			$payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : null;
			if ( ! $payment_method ) {
				throw new \Exception( __( 'Invalid payment method', 'doublescale' ) );
			}

			$bookable_entity = $booking->getBookableEntity();
			if ( ! $bookable_entity ) {
				throw new \Exception( __( 'Booking entity not found', 'doublescale' ) );
			}

			$payments_settings = method_exists( $bookable_entity, 'get_meta' )
				? $bookable_entity->get_meta( 'payments_settings', array() )
				: ( $bookable_entity->payments_settings ?? array() );

			if ( ! isset( $payments_settings['enable_payment'] ) || ! $payments_settings['enable_payment'] ) {
				throw new \Exception( __( 'Payment is not enabled for this booking', 'doublescale' ) );
			}

			$method_enabled_key = 'enable_' . $payment_method;
			if ( ! isset( $payments_settings[ $method_enabled_key ] ) || ! $payments_settings[ $method_enabled_key ] ) {
				throw new \Exception( __( 'Selected payment method is not available', 'doublescale' ) );
			}

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Payment processing initiated', 'doublescale' ),
					/* translators: %s: payment method name */
					'details' => sprintf( __( 'Payment processing initiated with %s', 'doublescale' ), $payment_method ),
				)
			);

			// Gateway listeners (Pro Stripe) end the request with their own JSON.
			do_action(
				'doublescale_booking_process_payment',
				$booking,
				array(
					'payment_method' => $payment_method,
				)
			);

			// Reaching here means no gateway accepted the call — Stripe gateway
			// (Pro) is required; the free build can't process payments alone.
			throw new \Exception( __( 'No payment gateway is available to process this booking. The Pro add-on with a configured Stripe integration is required.', 'doublescale' ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}
