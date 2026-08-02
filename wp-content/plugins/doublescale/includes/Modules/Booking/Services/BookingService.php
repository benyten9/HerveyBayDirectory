<?php

namespace DoubleScale\Modules\Booking\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Helpers\IntegrationsHelper;
use DoubleScale\Modules\Booking\Models\BookingHostsModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\BookedSlotModel;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use Exception;
use Illuminate\Support\Arr;

class BookingService {


	/**
	 * Book an event slot.
	 *
	 * @param EventModel $event The event being booked.
	 * @param int        $calendar_id The calendar ID.
	 * @param \DateTime  $start_date The start date/time of the booking.
	 * @param int        $duration The duration of the booking in minutes.
	 * @param string     $timezone The timezone of the booking.
	 * @param array      $invitees The invitees for the booking.
	 * @param string     $location The location of the booking.
	 * @param string     $status The status of the booking.
	 * @param array      $fields Additional fields for the booking.
	 * @param int|null   $user_id The user ID for the booking (optional).
	 *
	 * @return BookingModel
	 * @throws \Exception If booking fails.
	 */
	public function book_event_slot( $event, $calendar_id, $start_date, $duration, $timezone, $invitees, $location, $status = 'scheduled', $fields = array(), $user_id = null, $skip_availability_check = false ) {
		// Collective bookings issue the meeting link from the team owner's
		// integrated calendar (see Integration::get_integration_host_calendar_for_booking).
		// Without a connected integration on the owner there is no calendar
		// to write the event to and no provider to mint the meeting URL, so
		// reject the booking up-front rather than leaving an orphan record.
		if ( 'collective' === $event->type ) {
			$booking_calendar = CalendarModel::find( $calendar_id );
			if ( $booking_calendar && 'team' === $booking_calendar->type ) {
				$owner_host_calendar = CalendarModel::where( 'user_id', (int) $booking_calendar->user_id )
					->where( 'type', 'host' )
					->first();
				if ( ! IntegrationsHelper::host_has_any_calendar_integration( $owner_host_calendar ) ) {
					throw new \Exception( esc_html__( 'This event can\'t be booked yet — the team owner needs to connect a calendar integration (Google, Outlook, or Apple) before collective meetings can be scheduled.', 'doublescale' ) );
				}
			}
		}

		$end_date = clone $start_date;
		$end_date->modify( "+{$duration} minutes" );

		// Defense in depth: re-verify the slot is inside the event's
		// availability (weekly_hours / override) and within all configured
		// limits. Callers do this too, but we re-run it here so a missed
		// check at the boundary can't quietly book a slot outside hours.
		// `$skip_availability_check` is only honored when an admin route
		// has already gated the request behind a manage-bookings cap.
		//
		// For round-robin we pass the host array so the schedule lookup
		// can target any of the chosen hosts; for one-to-one / group we
		// pass the single host id. Collective ignores $user_id at the
		// schedule layer (it always walks team_members), so the value
		// doesn't matter there.
		if ( ! $skip_availability_check ) {
			$host_for_check = $user_id;
			if ( is_array( $user_id ) && 'round-robin' !== $event->type ) {
				$host_for_check = $user_id[0] ?? null;
			}
			$slot_count = $event->get_booking_available_slots( $start_date, $duration, $timezone, $host_for_check );
			if ( ! $slot_count ) {
				throw new \Exception( esc_html__( 'Sorry, this time slot is not available.', 'doublescale' ) );
			}
		}

		$utc              = new \DateTimeZone( 'UTC' );
		$start_utc_string = ( clone $start_date )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_utc_string   = ( clone $end_date )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$overlap_check = function () use ( $event, $user_id, $calendar_id, $start_utc_string, $end_utc_string ) {
			if ( in_array( $event->type, array( 'round-robin', 'collective' ), true ) ) {
				$host_ids_to_check = is_array( $user_id ) ? $user_id : array( $user_id );
				foreach ( $host_ids_to_check as $host_to_check ) {
					if ( self::host_has_overlap( (int) $host_to_check, $start_utc_string, $end_utc_string ) ) {
						throw new \Exception( esc_html__( 'This time slot has just been booked. Please choose another.', 'doublescale' ) );
					}
				}
			} elseif ( 'group' === $event->type ) {
				// Group events allow multiple concurrent bookings up to
				// `group_settings.max_invites` (default 2 — matches
				// EventModel::check_available_slots). Reject only once
				// capacity is reached, otherwise we silently cap at 1.
				$max_invites = (int) Arr::get( $event->group_settings, 'max_invites', 2 );
				if ( $max_invites < 1 ) {
					$max_invites = 1;
				}
				$current = BookedSlotModel::count_overlaps( $calendar_id, $start_utc_string, $end_utc_string );
				if ( $current >= $max_invites ) {
					throw new \Exception( esc_html__( 'This time slot has just been booked. Please choose another.', 'doublescale' ) );
				}
			} elseif ( BookedSlotModel::has_overlap( $calendar_id, $start_utc_string, $end_utc_string ) ) {
				throw new \Exception( esc_html__( 'This time slot has just been booked. Please choose another.', 'doublescale' ) );
			}
		};

		$host_ids = is_array( $user_id )
			? $user_id
			: array( $user_id ?? $event->user_id ?? get_current_user_id() );

		return $this->create_booking(
			array(
				'entity'          => $event,
				'calendar_id'     => $calendar_id,
				'start_date'      => $start_date,
				'end_date'        => $end_date,
				'duration'        => $duration,
				'timezone'        => $timezone,
				'invitees'        => $invitees,
				'location'        => $location,
				'status'          => $status,
				'fields'          => $fields,
				'source'          => 'event-page',
				'booking_ref'     => array( 'event_id' => $event->id ),
				'slot_acquire'    => array( $event->id ),
				'host_ids'        => $host_ids,
				'overlap_check'   => $overlap_check,
				'log_message'     => __( 'Booking created', 'doublescale' ),
				/* translators: 2: actor or contact display name */
				'log_details_fmt' => __( 'Booking created by %2$s', 'doublescale' ),
			)
		);
	}

	/**
	 * Core booking creation logic for event flows.
	 *
	 * @param array $args {
	 *     @type object   $entity          Event model.
	 *     @type int      $calendar_id     Calendar ID.
	 *     @type \DateTime $start_date     Start datetime.
	 *     @type \DateTime $end_date       End datetime.
	 *     @type int      $duration        Duration in minutes.
	 *     @type string   $timezone        Booking timezone.
	 *     @type array    $invitees        Validated invitees.
	 *     @type mixed    $location        Location data.
	 *     @type string   $status          Initial status.
	 *     @type array    $fields          Custom fields.
	 *     @type string   $source          Booking source identifier.
	 *     @type array    $booking_ref     Entity reference (event_id).
	 *     @type array    $slot_acquire    Extra args for BookedSlotModel::acquire.
	 *     @type array    $host_ids        Host user IDs.
	 *     @type callable $overlap_check   Overlap validation callback.
	 *     @type array    $extra_meta      Additional meta to save on the booking.
	 *     @type string   $log_message     Log message.
	 *     @type string   $log_details_fmt Log details sprintf format.
	 * }
	 *
	 * @return BookingModel
	 * @throws \Exception
	 */
	private function create_booking( $args ) {
		global $wpdb;

		$entity        = $args['entity'];
		$calendar_id   = $args['calendar_id'];
		$start_date    = $args['start_date'];
		$end_date      = $args['end_date'];
		$duration      = $args['duration'];
		$timezone      = $args['timezone'];
		$invitees      = $args['invitees'];
		$location      = $args['location'];
		$status        = $args['status'];
		$fields        = $args['fields'];
		$source        = $args['source'];
		$booking_ref   = $args['booking_ref'];
		$slot_acquire  = $args['slot_acquire'];
		$host_ids      = $args['host_ids'];
		$overlap_check = $args['overlap_check'];
		$extra_meta    = $args['extra_meta'] ?? array();

		$pending_type = null;
		if ( $entity->requireConfirmation( $start_date ) && 'scheduled' !== $status ) {
			$pending_type = 'confirmation';
			$status       = 'pending';
		}
		if ( $entity->requirePayment() && 'scheduled' !== $status ) {
			$pending_type = 'payment';
			$status       = 'pending';
		}

		$utc              = new \DateTimeZone( 'UTC' );
		$start_utc_string = ( clone $start_date )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_utc_string   = ( clone $end_date )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$wpdb->query( 'START TRANSACTION' );
		$created_bookings = array();
		try {
			$overlap_check();

			foreach ( $invitees as $invitee ) {
				$contact = $this->resolve_or_create_contact_for_invitee( $invitee );
				if ( ! $contact ) {
					throw new \Exception( esc_html__( 'Failed to book', 'doublescale' ) );
				}

				$booking = new BookingModel();
				foreach ( $booking_ref as $key => $value ) {
					$booking->$key = $value;
				}
				$booking->calendar_id = $calendar_id;
				$booking->contact_id  = (int) $contact->id;
				$booking->start_time  = $start_utc_string;
				$booking->end_time    = $end_utc_string;
				$booking->status      = $status;
				$booking->event_url   = home_url();
				$booking->source      = $source;
				$booking->slot_time   = $duration;

				if ( ! $booking->save() ) {
					throw new \Exception( esc_html__( 'Failed to book', 'doublescale' ) );
				}

				$booking->location = $location;
				$booking->timezone = $timezone;
				$booking->save();

				BookedSlotModel::acquire(
					$calendar_id,
					$start_utc_string,
					$end_utc_string,
					$booking->id,
					...$slot_acquire
				);

				if ( ! empty( $fields ) ) {
					$booking->update_meta( 'fields', $fields );
				}

				foreach ( $extra_meta as $meta_key => $meta_value ) {
					$booking->update_meta( $meta_key, $meta_value );
				}

				$contact_display_name = trim( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) );
				if ( '' === $contact_display_name ) {
					$contact_display_name = $invitee['name'] ?? $contact->email;
				}

				$booking->logs()->create(
					array(
						'type'    => 'info',
						'message' => $args['log_message'],
						'details' => sprintf( $args['log_details_fmt'], $entity->name, $contact_display_name ),
					)
				);

				foreach ( $host_ids as $host_user_id ) {
					$booking_hosts             = new BookingHostsModel();
					$booking_hosts->booking_id = $booking->id;
					$booking_hosts->user_id    = $host_user_id;
					$booking_hosts->status     = $status;
					if ( ! $booking_hosts->save() ) {
						$booking->delete();
						throw new \Exception( esc_html__( 'Failed to book', 'doublescale' ) );
					}
				}

				if ( $entity->requirePayment() && 'pending' === $status ) {
					do_action( 'doublescale_booking_payment_status', $booking );
				}

				$created_bookings[] = $booking;
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		// Fire lifecycle events post-commit, once per created booking, so a
		// group booking with N invitees emits N events. Previously the COMMIT
		// + return were inside the loop, so only invitee #1 ever materialized.
		foreach ( $created_bookings as $created ) {
			if ( 'pending' === $status && 'confirmation' === $pending_type ) {
				BookingEvents::emit(
					'pending',
					(int) $created->id,
					array(
						'actor'  => 'attendee',
						'reason' => 'confirmation',
					)
				);
			} elseif ( 'pending' !== $status ) {
				BookingEvents::emit( 'created', (int) $created->id, array( 'actor' => 'attendee' ) );
			}
		}

		// Preserve the existing single-booking return contract for non-group
		// flows (which always pass exactly one invitee). Group bookings now
		// correctly persist all N invitees in the DB; callers that need the
		// full set can read them via the slot/event relationship.
		return end( $created_bookings );
	}

	/**
	 * Add a customer to the waiting list for an event slot.
	 *
	 * Does NOT acquire a BookedSlotModel record (waiting bookings don't
	 * consume slots) and does NOT trigger payment flow.
	 *
	 * @param EventModel $entity     Event being booked.
	 * @param int        $calendar_id Calendar ID.
	 * @param \DateTime  $start_date  Start datetime.
	 * @param int        $duration    Duration in minutes.
	 * @param string     $timezone    Booking timezone.
	 * @param array      $invitees    Validated invitees.
	 * @param string     $location    Location data.
	 * @param array      $fields      Custom fields.
	 * @param array      $host_ids    Host user IDs.
	 *
	 * @return BookingModel
	 * @throws \Exception
	 */
	public function book_waiting_list_slot( $entity, $calendar_id, $start_date, $duration, $timezone, $invitees, $location, $fields = array(), $host_ids = array() ) {
		global $wpdb;

		$end_date = clone $start_date;
		$end_date->modify( "+{$duration} minutes" );

		$utc              = new \DateTimeZone( 'UTC' );
		$start_utc_string = ( clone $start_date )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_utc_string   = ( clone $end_date )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$wl_settings = $entity->waiting_list_settings;
		$wl_capacity = isset( $wl_settings['capacity'] ) ? (int) $wl_settings['capacity'] : 10;

		if ( ! empty( $wl_settings['limit_additional_people'] ) ) {
			$max_people    = (int) ( $wl_settings['additional_people_limit'] ?? 0 );
			$total_allowed = $max_people + 1;
			if ( count( $invitees ) > $total_allowed ) {
				throw new \Exception(
					esc_html(
						sprintf(
						/* translators: %d: maximum allowed additional people */
							__( 'You can bring at most %d additional people when joining the waiting list', 'doublescale' ),
							$max_people
						)
					)
				);
			}
		}

		$wpdb->query( 'START TRANSACTION' );
		try {
			// Serialize concurrent joins on the same (event, slot) using a
			// MySQL named lock. Without this, the count() below is racy: two
			// parallel requests can both read N waiters and both insert,
			// blowing past the configured capacity.
			//
			// Lock name is 63 bytes max in MySQL — hash the composite key to
			// stay under the limit regardless of datetime formatting.
			$lock_token = 'ds_wl_' . substr(
				md5( $entity->id . '|' . $start_utc_string . '|' . $end_utc_string ),
				0,
				56
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_token ) );
			if ( 1 !== $got_lock ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \Exception( esc_html__( 'Waiting list is busy, please retry', 'doublescale' ) );
			}

			$position_query = BookingModel::where( 'status', 'waiting' )
				->where( 'start_time', $start_utc_string )
				->where( 'end_time', $end_utc_string )
				->where( 'event_id', $entity->id );

			$current_waiting = $position_query->count();
			$batch_size      = count( $invitees );

			if ( $current_waiting + $batch_size > $wl_capacity ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_token ) );
				$wpdb->query( 'ROLLBACK' );
				throw new \Exception( esc_html__( 'The waiting list for this time slot is full', 'doublescale' ) );
			}

			$last_booking = null;
			foreach ( $invitees as $idx => $invitee ) {
				$contact = $this->resolve_or_create_contact_for_invitee( $invitee );
				if ( ! $contact ) {
					throw new \Exception( esc_html__( 'Failed to join waiting list', 'doublescale' ) );
				}

				$booking              = new BookingModel();
				$booking->event_id    = $entity->id;
				$booking->calendar_id = $calendar_id;
				$booking->contact_id  = (int) $contact->id;
				$booking->start_time  = $start_utc_string;
				$booking->end_time    = $end_utc_string;
				$booking->status      = 'waiting';
				$booking->event_url   = home_url();
				$booking->source      = 'event-page';
				$booking->slot_time   = $duration;

				if ( ! $booking->save() ) {
					throw new \Exception( esc_html__( 'Failed to join waiting list', 'doublescale' ) );
				}

				$booking->location = $location;
				$booking->timezone = $timezone;
				$booking->save();

				if ( ! empty( $fields ) ) {
					$booking->update_meta( 'fields', $fields );
				}

				$booking->update_meta( 'waiting_list_position', $current_waiting + $idx + 1 );

				$contact_display_name = trim( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) );
				if ( '' === $contact_display_name ) {
					$contact_display_name = $invitee['name'] ?? $contact->email;
				}

				$booking->logs()->create(
					array(
						'type'    => 'info',
						'message' => __( 'Joined waiting list', 'doublescale' ),
						/* translators: 1: contact display name, 2: queue position */
						'details' => sprintf( __( '%1$s joined the waiting list at position #%2$d', 'doublescale' ), $contact_display_name, $current_waiting + $idx + 1 ),
					)
				);

				foreach ( $host_ids as $host_user_id ) {
					$booking_hosts             = new BookingHostsModel();
					$booking_hosts->booking_id = $booking->id;
					$booking_hosts->user_id    = $host_user_id;
					$booking_hosts->status     = 'waiting';
					if ( ! $booking_hosts->save() ) {
						$booking->delete();
						throw new \Exception( esc_html__( 'Failed to join waiting list', 'doublescale' ) );
					}
				}

				$last_booking = $booking;
			}

			$wpdb->query( 'COMMIT' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_token ) );

			if ( $last_booking ) {
				BookingEvents::emit( 'waiting_list_joined', (int) $last_booking->id );
			}

			return $last_booking;
		} catch ( \Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( isset( $lock_token ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_token ) );
			}
			throw $e;
		}
	}

	/**
	 * Sanitize and validate an invitee list.
	 *
	 * @param array  $invitee    Raw invitee array of {name, email}.
	 * @param bool   $allow_many Whether multiple invitees are allowed.
	 * @param string $multi_error Error message when multiple invitees are not allowed.
	 *
	 * @throws \Exception
	 * @return array Sanitized invitee list.
	 */
	public function validate_invitee_list( $invitee, $allow_many = false, $multi_error = '' ) {
		$invitee = array_map(
			function ( $item ) {
				$name  = sanitize_text_field( Arr::get( $item, 'name', null ) );
				$email = sanitize_email( Arr::get( $item, 'email', null ) );

				if ( ! $name || ! $email ) {
					throw new \Exception( esc_html__( 'Invalid invitee', 'doublescale' ) );
				}

				$guest = array(
					'name'  => $name,
					'email' => $email,
				);

				if ( $user = get_user_by( 'email', $email ) ) {
					$guest['user_id'] = $user->ID;
				}

				return $guest;
			},
			$invitee
		);

		if ( ! $allow_many && count( $invitee ) > 1 ) {
			throw new \Exception( esc_html( $multi_error ?: __( 'Multiple invitees are not allowed', 'doublescale' ) ) );
		}

		return $invitee;
	}

	/**
	 * Validate invitee for event bookings.
	 *
	 * @param EventModel $event
	 * @param array      $invitee
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function validate_invitee( $event, $invitee ) {
		return $this->validate_invitee_list(
			$invitee,
			'group' === $event->type,
			__( 'Invalid event type', 'doublescale' )
		);
	}

	/**
	 * Check if a specific host (user) has any non-cancelled booking that
	 * overlaps the given UTC time range. Used for round-robin / collective
	 * events where the overlap must be checked per-host, not per-calendar.
	 *
	 * @param int    $host_user_id WordPress user ID of the host.
	 * @param string $start        UTC datetime string.
	 * @param string $end          UTC datetime string.
	 *
	 * @return bool True if the host already has a conflicting booking.
	 */
	public static function host_has_overlap( $host_user_id, $start, $end ) {
		$count = BookingModel::query()
			->whereHas(
				'hosts',
				function ( $q ) use ( $host_user_id ) {
					$q->where( 'user_id', $host_user_id );
				}
			)
			->whereNotIn( 'status', BookingModel::NON_ACTIVE_STATUSES )
			->where( 'start_time', '<', $end )
			->where( 'end_time', '>', $start )
			->count();

		return $count > 0;
	}

	/**
	 * Reschedule an existing booking: release the old slot, acquire the new
	 * one, and update the booking row — all inside one DB transaction so a
	 * crash mid-way can't leave the booked_slots table inconsistent.
	 *
	 * Host-based overlap (round-robin / collective) is checked against the
	 * existing host_ids on the booking; group events keep their per-calendar
	 * capacity check; one-to-one events use the calendar overlap with the
	 * current booking excluded.
	 *
	 * @param BookingModel $booking  The booking to reschedule.
	 * @param \DateTime    $start    New start datetime (any timezone — converted to UTC).
	 * @param \DateTime    $end      New end datetime (any timezone — converted to UTC).
	 * @param int          $duration New duration in minutes.
	 *
	 * @throws \Exception If the new slot is no longer available.
	 */
	public function reschedule_booking( BookingModel $booking, \DateTime $start, \DateTime $end, $duration ): void {
		global $wpdb;

		$utc       = new \DateTimeZone( 'UTC' );
		$start_utc = ( clone $start )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_utc   = ( clone $end )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$entity = $booking->getBookableEntity();
			$type   = $entity ? $entity->type : null;

			if ( in_array( $type, array( 'round-robin', 'collective' ), true ) ) {
				// `$booking->hosts` is a hasManyThrough to UserModel (primary key `ID`),
				// not the `booking_hosts` join row, so we pluck `ID` here. Plucking
				// `user_id` returns a column of nulls and silently disables the
				// per-host overlap guard — letting reschedules collide with
				// existing bookings for the same host.
				$host_ids = $booking->hosts->pluck( 'ID' )->toArray();
				foreach ( $host_ids as $host_id ) {
					if ( $this->host_has_overlap_excluding( (int) $host_id, $start_utc, $end_utc, (int) $booking->id ) ) {
						throw new \Exception( esc_html__( 'This time slot has just been booked. Please choose another.', 'doublescale' ) );
					}
				}
			} elseif ( 'group' === $type ) {
				$max_invites = (int) Arr::get( $entity->group_settings, 'max_invites', 2 );
				if ( $max_invites < 1 ) {
					$max_invites = 1;
				}
				$current = BookedSlotModel::count_overlaps( $booking->calendar_id, $start_utc, $end_utc );
				if ( $current >= $max_invites ) {
					throw new \Exception( esc_html__( 'This time slot has just been booked. Please choose another.', 'doublescale' ) );
				}
			} elseif ( BookedSlotModel::has_overlap_excluding( $booking->calendar_id, $start_utc, $end_utc, (int) $booking->id ) ) {
				throw new \Exception( esc_html__( 'This time slot has just been booked. Please choose another.', 'doublescale' ) );
			}

			BookedSlotModel::release( $booking->id );

			$booking->start_time = $start_utc;
			$booking->end_time   = $end_utc;
			$booking->slot_time  = $duration;
			$booking->save();

			BookedSlotModel::acquire(
				$booking->calendar_id,
				$start_utc,
				$end_utc,
				$booking->id,
				$booking->event_id
			);

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Same as host_has_overlap() but excludes a specific booking. Used during
	 * reschedule so the booking's own slot doesn't block its new time.
	 */
	public static function host_has_overlap_excluding( $host_user_id, $start, $end, $exclude_booking_id ) {
		$count = BookingModel::query()
			->where( 'id', '!=', $exclude_booking_id )
			->whereHas(
				'hosts',
				function ( $q ) use ( $host_user_id ) {
					$q->where( 'user_id', $host_user_id );
				}
			)
			->whereNotIn( 'status', BookingModel::NON_ACTIVE_STATUSES )
			->where( 'start_time', '<', $end )
			->where( 'end_time', '>', $start )
			->count();

		return $count > 0;
	}

	/**
	 * Resolve a CRM contact for a booking invitee, creating one if needed.
	 *
	 * Every booker becomes a CRM contact (HubSpot-style). Lookup is by email
	 * via firstOrCreate, which is atomic against the contacts.email UNIQUE KEY
	 * so two parallel bookings can't double-insert. Name policy is latest-write-wins:
	 * each booking overwrites first_name/last_name with the invitee-supplied values
	 * (only when non-empty, so a blank submission doesn't blank a stored name).
	 *
	 * @param array $invitee Invitee payload — expects `email` and `name` keys.
	 * @return ContactModel|null Resolved contact, or null when email is invalid.
	 */
	private function resolve_or_create_contact_for_invitee( array $invitee ): ?ContactModel {
		$email = sanitize_email( $invitee['email'] ?? '' );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return null;
		}

		$name_parts = explode( ' ', trim( $invitee['name'] ?? '' ), 2 );
		$first_name = isset( $name_parts[0] ) ? sanitize_text_field( $name_parts[0] ) : '';
		$last_name  = isset( $name_parts[1] ) ? sanitize_text_field( $name_parts[1] ) : '';

		$contact = ContactModel::firstOrCreate(
			array( 'email' => $email ),
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'source'     => 'booking',
			)
		);

		if ( ! $contact ) {
			return null;
		}

		$dirty = false;
		if ( '' !== $first_name && $contact->first_name !== $first_name ) {
			$contact->first_name = $first_name;
			$dirty               = true;
		}
		if ( '' !== $last_name && $contact->last_name !== $last_name ) {
			$contact->last_name = $last_name;
			$dirty              = true;
		}
		if ( $dirty ) {
			$contact->save();
		}

		return $contact;
	}
}
