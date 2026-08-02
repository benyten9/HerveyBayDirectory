<?php
/**
 * Booking Notifications Handler
 *
 * Subscribes to booking lifecycle events emitted by Free's EventBus and
 * creates in-app notifications (bell / browser / push) for the meeting
 * host(s). Email channel is owned by Free's EmailNotifications; this class
 * intentionally defaults the email channel off in NotificationPreferences
 * so hosts don't receive duplicate emails from the two pipelines.
 *
 * Audience selection mirrors BookingModel::getOrganizerRecipientEmails():
 * for team calendars with round-robin/collective events, all linked hosts
 * are notified; otherwise the calendar owner.
 *
 * @listens doublescale_booking_created
 * @listens doublescale_booking_cancelled
 * @listens doublescale_booking_rescheduled
 *
 * @since 2.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications;

use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Notifications\Services\NotificationService;

defined( 'ABSPATH' ) || exit;

/**
 * BookingNotifications class
 */
class BookingNotifications {

	/**
	 * Subscribe to the bare-hook tail emitted by Free's EventBus after the
	 * structured handlers run. Same priority (10) as Free's EmailNotifications
	 * so this is a peer subscriber, not a special case.
	 *
	 * Skips hook registration when the booking module is disabled — Free's
	 * BookingModule won't boot in that case so events never fire anyway, but
	 * skipping the add_action() calls keeps the hook table clean.
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::BOOKING ) ) {
			return;
		}
		add_action( 'doublescale_booking_created', array( $this, 'on_created' ), 10, 2 );
		add_action( 'doublescale_booking_cancelled', array( $this, 'on_cancelled' ), 10, 2 );
		add_action( 'doublescale_booking_rescheduled', array( $this, 'on_rescheduled' ), 10, 2 );
	}

	/**
	 * Handle booking.created — notify every host on the calendar.
	 *
	 * The actor for created events is always 'attendee' (public renderer or
	 * authenticated booking flow), so no self-skip is needed here.
	 *
	 * @param mixed $booking BookingModel instance (validated in safely()).
	 * @param array $context Optional event context (e.g. ['actor' => 'attendee']).
	 */
	public function on_created( $booking, $context = array() ) {
		$this->safely(
			$booking,
			function ( BookingModel $b ) {
				$invitee     = $this->invitee_label( $b );
				$when        = $this->format_when( $b );
				$event_label = $this->event_label( $b );
				$links       = $this->links_for( $b );

				foreach ( $this->resolve_host_user_ids( $b ) as $user_id ) {
					NotificationService::create(
						$user_id,
						/* translators: %s: invitee name */
						sprintf( __( 'New booking from %s', 'doublescale' ), $invitee ),
						/* translators: 1: event label, 2: time */
						sprintf( __( '%1$s scheduled for %2$s.', 'doublescale' ), $event_label, $when ),
						$links,
						NotificationCategories::BOOKING_CREATED,
						array( 'booking_id' => (int) $b->id )
					);
				}
			}
		);
	}

	/**
	 * Handle booking.cancelled — notify hosts, except the organizer who
	 * triggered the cancellation themselves.
	 *
	 * @param mixed $booking BookingModel instance (validated in safely()).
	 * @param array $context Event context with optional 'actor' key.
	 */
	public function on_cancelled( $booking, $context = array() ) {
		$this->safely(
			$booking,
			function ( BookingModel $b ) use ( $context ) {
				$invitee     = $this->invitee_label( $b );
				$event_label = $this->event_label( $b );
				$links       = $this->links_for( $b );
				$skip_id     = $this->actor_skip_user_id( $context );

				foreach ( $this->resolve_host_user_ids( $b ) as $user_id ) {
					if ( $user_id === $skip_id ) {
						continue;
					}
					NotificationService::create(
						$user_id,
						/* translators: %s: event label */
						sprintf( __( '%s cancelled', 'doublescale' ), $event_label ),
						/* translators: %s: invitee name */
						sprintf( __( '%s cancelled their booking.', 'doublescale' ), $invitee ),
						$links,
						NotificationCategories::BOOKING_CANCELLED,
						array( 'booking_id' => (int) $b->id )
					);
				}
			}
		);
	}

	/**
	 * Handle booking.rescheduled — notify hosts of the new time, skipping the
	 * organizer who initiated the reschedule.
	 *
	 * @param mixed $booking BookingModel instance (validated in safely()).
	 * @param array $context Event context with optional 'actor' key.
	 */
	public function on_rescheduled( $booking, $context = array() ) {
		$this->safely(
			$booking,
			function ( BookingModel $b ) use ( $context ) {
				$invitee     = $this->invitee_label( $b );
				$when        = $this->format_when( $b );
				$event_label = $this->event_label( $b );
				$links       = $this->links_for( $b );
				$skip_id     = $this->actor_skip_user_id( $context );

				foreach ( $this->resolve_host_user_ids( $b ) as $user_id ) {
					if ( $user_id === $skip_id ) {
						continue;
					}
					NotificationService::create(
						$user_id,
						/* translators: %s: invitee name */
						sprintf( __( '%s rescheduled', 'doublescale' ), $invitee ),
						/* translators: 1: event label, 2: new time */
						sprintf( __( '%1$s moved to %2$s.', 'doublescale' ), $event_label, $when ),
						$links,
						NotificationCategories::BOOKING_RESCHEDULED,
						array( 'booking_id' => (int) $b->id )
					);
				}
			}
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Run a callback against the booking, swallowing any Throwable. The bare
	 * `doublescale_booking_{event}` hook is fired by EventBus::dispatch()
	 * with no exception guard, so an unhandled fatal here would abort the
	 * booking-create request the customer is sitting on.
	 *
	 * @param mixed    $booking BookingModel instance (or anything — validated here).
	 * @param callable $fn      Callback receiving the validated BookingModel.
	 */
	private function safely( $booking, callable $fn ): void {
		if ( ! ( $booking instanceof BookingModel ) ) {
			return;
		}
		try {
			$fn( $booking );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Booking in-app notification failed',
				array(
					'source'     => 'booking-pro-notifications',
					'booking_id' => (int) $booking->id,
					'exception'  => $e->getMessage(),
					'file'       => $e->getFile(),
					'line'       => $e->getLine(),
				)
			);
		}
	}

	/**
	 * User ID to skip when sending notifications. Only skip when the actor
	 * is the organizer — for attendee- or system-initiated events, every
	 * host should be notified.
	 *
	 * @param array $context Event context with optional 'actor' key.
	 * @return int Zero means don't skip anyone.
	 */
	private function actor_skip_user_id( array $context ): int {
		$actor = isset( $context['actor'] ) ? (string) $context['actor'] : '';
		return ( 'organizer' === $actor ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Resolve WordPress user IDs to notify for this booking. Mirrors the
	 * logic in BookingModel::getOrganizerRecipientEmails(): team calendars
	 * with round-robin/collective events fan out to all linked hosts;
	 * everything else falls back to the calendar owner.
	 *
	 * @param BookingModel $booking The booking.
	 * @return int[] Unique non-zero user IDs.
	 */
	private function resolve_host_user_ids( BookingModel $booking ): array {
		$ids      = array();
		$calendar = $booking->calendar ?? null;
		$event    = $booking->event ?? null;

		if (
			$calendar && $event
			&& 'team' === $calendar->type
			&& in_array( $event->type, array( 'round-robin', 'collective' ), true )
		) {
			$booking->load( 'hosts' );
			foreach ( $booking->hosts as $host_user ) {
				$uid = (int) ( $host_user->ID ?? 0 );
				if ( $uid ) {
					$ids[] = $uid;
				}
			}
		}

		if ( empty( $ids ) ) {
			$owner = (int) $booking->getOwnerUserId();
			if ( $owner ) {
				$ids[] = $owner;
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Human label for the booking's invitee — preferring contact name,
	 * falling back to email, then a generic placeholder.
	 *
	 * @param BookingModel $booking The booking.
	 * @return string Non-empty label.
	 */
	private function invitee_label( BookingModel $booking ): string {
		if ( $booking->contact ) {
			$name = trim( ( $booking->contact->first_name ?? '' ) . ' ' . ( $booking->contact->last_name ?? '' ) );
			if ( '' !== $name ) {
				return $name;
			}
			if ( ! empty( $booking->contact->email ) ) {
				return (string) $booking->contact->email;
			}
		}
		return __( 'An invitee', 'doublescale' );
	}

	/**
	 * Human label for the booking's event — event name when available,
	 * generic fallback otherwise.
	 *
	 * @param BookingModel $booking The booking.
	 * @return string Non-empty label.
	 */
	private function event_label( BookingModel $booking ): string {
		if ( $booking->event && ! empty( $booking->event->name ) ) {
			return (string) $booking->event->name;
		}
		return __( 'Booking', 'doublescale' );
	}

	/**
	 * Format the booking start time in the host's timezone using the site's
	 * date/time format. Column is `start_time` (not `start_date`); timezone
	 * comes from `getHostTimezone()` (not `getEffectiveTimezone()`).
	 *
	 * @param BookingModel $booking The booking.
	 * @return string Formatted timestamp, or empty string when start_time is missing.
	 */
	private function format_when( BookingModel $booking ): string {
		$start = $booking->start_time ?? null;
		if ( ! $start ) {
			return '';
		}
		try {
			$tz = $booking->getHostTimezone();
			$tz = ( is_string( $tz ) && '' !== $tz ) ? $tz : 'UTC';
			$dt = new \DateTimeImmutable( $start, new \DateTimeZone( 'UTC' ) );
			$dt = $dt->setTimezone( new \DateTimeZone( $tz ) );
			return $dt->format( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ) );
		} catch ( \Exception $e ) {
			return $start;
		}
	}

	/**
	 * Build the link payload for the notification — admin URL for web,
	 * relative path for mobile, matching TaskNotifications' convention.
	 *
	 * @param BookingModel $booking The booking.
	 * @return array{web:string,mobile:string}
	 */
	private function links_for( BookingModel $booking ): array {
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=booking/bookings/' . (int) $booking->id ),
			'mobile' => '/bookings/' . (int) $booking->id,
		);
	}
}
