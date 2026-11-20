<?php
/**
 * Whether the attendee may cancel or reschedule a booking, by the event's own
 * rules ("Attendee cannot cancel / reschedule": at all, or within a time before
 * the start), with the event's refusal message.
 *
 * One answer for every place an attendee acts: the public confirm, cancel and
 * reschedule pages ({@see \DoubleScale\Modules\Booking\Renderer\BaseTemplateRenderer}),
 * the requests those pages send ({@see BookingAjax}), and the Client Portal
 * (`RestPortalBookingController`), which used to offer and accept both on
 * every upcoming booking. The emailed links used to cancel a past, declined or
 * running meeting.
 *
 * @package DoubleScale\Modules\Booking
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Managers\MergeTagsManager;

/**
 * AttendeeChangePolicy.
 */
final class AttendeeChangePolicy {

	public const CANCEL     = 'cancel';
	public const RESCHEDULE = 'reschedule';

	/** States the attendee can no longer change, whatever the event allows. */
	public const CLOSED_STATUSES = array( 'cancelled', 'rejected', 'completed', 'no-show' );

	/**
	 * Before the event's own rules: a booking that is over (cancelled, declined
	 * by the host, completed, missed) or already under way is no longer the
	 * attendee's to change. Null when it still is.
	 *
	 * @param BookingModel $booking Booking.
	 * @param string       $action  self::CANCEL or self::RESCHEDULE.
	 * @return array{allowed: bool, message: string}|null
	 */
	public static function closed( BookingModel $booking, string $action ): ?array {
		$cancel = self::CANCEL === $action;
		if ( in_array( (string) $booking->status, self::CLOSED_STATUSES, true ) ) {
			return array(
				'allowed' => false,
				'message' => $cancel
					? __( 'This booking can no longer be cancelled.', 'doublescale' )
					: __( 'This booking can no longer be rescheduled.', 'doublescale' ),
			);
		}

		$start = strtotime( (string) $booking->start_time . ' UTC' );
		if ( $start && $start <= time() ) {
			return array(
				'allowed' => false,
				'message' => $cancel
					? __( 'This booking has already started, so it can no longer be cancelled.', 'doublescale' )
					: __( 'This booking has already started, so it can no longer be rescheduled.', 'doublescale' ),
			);
		}

		return null;
	}

	/**
	 * The answer for a booking: its state first, then its event's settings.
	 *
	 * @param BookingModel $booking Booking.
	 * @param string       $action  self::CANCEL or self::RESCHEDULE.
	 * @return array{allowed: bool, message: string}
	 */
	public static function for_booking( BookingModel $booking, string $action ): array {
		$closed = self::closed( $booking, $action );
		if ( null !== $closed ) {
			return $closed;
		}

		return self::check(
			$action,
			(array) $booking->getAdvancedSettings(),
			(string) $booking->start_time,
			(string) ( $booking->timezone ?: 'UTC' ),
			$booking
		);
	}

	/**
	 * The rule itself.
	 *
	 * @param string            $action            self::CANCEL or self::RESCHEDULE.
	 * @param array             $advanced_settings Event advanced settings.
	 * @param string            $start_utc         Booking start (UTC, Y-m-d H:i:s).
	 * @param string            $timezone          Booking timezone.
	 * @param BookingModel|null $booking           For the message's merge tags.
	 * @return array{allowed: bool, message: string}
	 */
	public static function check( string $action, array $advanced_settings, string $start_utc, string $timezone, ?BookingModel $booking ): array {
		$cancel = self::CANCEL === $action;
		$keys   = $cancel
			? array( 'attendee_cannot_cancel', 'cannot_cancel_time', 'cannot_cancel_time_value', 'cannot_cancel_time_unit', 'permission_denied_message' )
			: array( 'attendee_cannot_reschedule', 'cannot_reschedule_time', 'cannot_reschedule_time_value', 'cannot_reschedule_time_unit', 'reschedule_denied_message' );
		list( $flag, $time_key, $value_key, $unit_key, $message_key ) = $keys;

		if ( empty( $advanced_settings[ $flag ] ) ) {
			return array(
				'allowed' => true,
				'message' => '',
			);
		}

		$message = (string) ( $advanced_settings[ $message_key ] ?? '' );
		if ( '' !== $message && $booking ) {
			$message = (string) MergeTagsManager::instance()->process_merge_tags( $message, $booking );
		}

		// Legacy events may carry the flag without a time: they behave like the
		// editor's default choice, "at event start" (never), not like "allowed".
		$restriction = $advanced_settings[ $time_key ] ?? 'event_start';

		if ( 'event_start' === $restriction ) {
			return array(
				'allowed' => false,
				'message' => '' !== $message ? $message : ( $cancel
					? __( 'You do not have permission to cancel this booking.', 'doublescale' )
					: __( 'You do not have permission to reschedule this booking.', 'doublescale' ) ),
			);
		}

		if ( 'less_than' !== $restriction ) {
			return array(
				'allowed' => true,
				'message' => '',
			);
		}

		$value = (int) ( $advanced_settings[ $value_key ] ?? 24 );
		$unit  = (string) ( $advanced_settings[ $unit_key ] ?? 'hours' );
		$value = $value > 0 ? $value : 24;
		$unit  = in_array( $unit, array( 'minutes', 'hours', 'days' ), true ) ? $unit : 'hours';

		try {
			$start = new \DateTime( $start_utc, new \DateTimeZone( 'UTC' ) );
			$start->setTimezone( new \DateTimeZone( '' !== $timezone ? $timezone : 'UTC' ) );
			$now      = new \DateTime( 'now', $start->getTimezone() );
			$deadline = clone $start;
			$deadline->modify( "-{$value} {$unit}" );
		} catch ( \Exception $e ) {
			// A date that cannot be read never locks the attendee out.
			return array(
				'allowed' => true,
				'message' => '',
			);
		}

		if ( $now < $deadline ) {
			return array(
				'allowed' => true,
				'message' => '',
			);
		}

		return array(
			'allowed' => false,
			'message' => '' !== $message ? $message : ( $cancel
				? __( 'Cancellation is no longer allowed for this booking.', 'doublescale' )
				: __( 'Rescheduling is no longer allowed for this booking.', 'doublescale' ) ),
		);
	}
}
