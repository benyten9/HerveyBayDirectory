<?php
/**
 * Booking SMS Notifier — bridge between the booking lifecycle hooks and the
 * CRM unified messaging stack (Modules/Inbox/MessageProviderRegistry).
 *
 * Replaces the deleted `Modules/Booking/Integrations/Twilio/Notifications`
 * class. Booking no longer owns a Twilio integration — SMS delivery is
 * delegated to whichever SMS provider the CRM has configured.
 *
 * Subscribes to one hook per lifecycle event (no more dual `_attendee_*` /
 * `_organizer_*` shapes). The actor variant is read from `$context['actor']`
 * and used to pick the right SMS template via `event_to_keys`.
 *
 * @package DoubleScale
 * @tier    free (shell); the actual SMS provider is pro-gated.
 */

namespace DoubleScale\Pro\Modules\Booking\Services;

use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Managers\MergeTagsManager;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Utils\Utils as CoreUtils;
use Illuminate\Support\Arr;

defined( 'ABSPATH' ) || exit;

class BookingSmsNotifier {

	/**
	 * Map (lifecycle event, actor) → list of SMS template keys to consider.
	 *
	 * The first dimension is the lifecycle event name as emitted by
	 * {@see BookingEvents::emit()}. The second dimension keys on the actor
	 * from `$context['actor']`; when the lifecycle event has no per-actor
	 * variant the only key is `'*'` and the actor is ignored.
	 *
	 * @var array<string,array<string,array<int,string>>>
	 */
	private static $event_to_keys = array(
		'created'     => array(
			'*' => array( 'organizer_confirmation', 'attendee_confirmation' ),
		),
		'cancelled'   => array(
			'attendee'  => array( 'attendee_cancellation' ),
			'organizer' => array( 'organizer_cancellation' ),
			// System-driven (payment timeout, deletion) → fall back to attendee variant.
			'system'    => array( 'attendee_cancellation' ),
			'*'         => array( 'organizer_cancellation', 'attendee_cancellation' ),
		),
		'rescheduled' => array(
			'attendee'  => array( 'attendee_reschedule' ),
			'organizer' => array( 'organizer_reschedule' ),
			'*'         => array( 'organizer_reschedule', 'attendee_reschedule' ),
		),
		// `confirmed` / `pending` hooks subscribed for wiring-test parity but no-op until EventFields seeds matching templates.
	);

	public function __construct() {
		// Listen on the EventBus bare-hook tail (`doublescale_booking_*`)
		// rather than the raw `doublescale_booking_*` lifecycle hooks, so the
		// bus's structured handlers run first and the booking's location/meta
		// state is fully populated by the time SMS templates render their merge tags.
		add_action( 'doublescale_booking_created',     array( $this, 'on_created' ), 10, 2 );
		add_action( 'doublescale_booking_confirmed',   array( $this, 'on_confirmed' ), 10, 2 );
		add_action( 'doublescale_booking_pending',     array( $this, 'on_pending' ), 10, 2 );
		add_action( 'doublescale_booking_cancelled',   array( $this, 'on_cancelled' ), 10, 2 );
		add_action( 'doublescale_booking_rescheduled', array( $this, 'on_rescheduled' ), 10, 2 );

		// Reminder hooks are scheduled by Free's BookingTasks (which reads the email
		// notification settings). When those hooks fire we independently check the
		// SMS-side flag and send the SMS reminder.
		add_action( 'booking_organizer_reminder', array( $this, 'on_organizer_reminder' ), 10, 1 );
		add_action( 'booking_attendee_reminder',  array( $this, 'on_attendee_reminder' ),  10, 1 );
	}

	public function on_created( $booking, $context = array() ): void {
		$this->dispatch_send( 'created', $booking, $context );
	}

	public function on_cancelled( $booking, $context = array() ): void {
		$this->dispatch_send( 'cancelled', $booking, $context );
	}

	public function on_rescheduled( $booking, $context = array() ): void {
		$this->dispatch_send( 'rescheduled', $booking, $context );
	}

	public function on_confirmed( $booking, $context = array() ): void {
		$this->dispatch_send( 'confirmed', $booking, $context );
	}

	public function on_pending( $booking, $context = array() ): void {
		$this->dispatch_send( 'pending', $booking, $context );
	}

	public function on_organizer_reminder( $booking_id ): void {
		$this->dispatch_reminder( (int) $booking_id, 'organizer_reminder' );
	}

	public function on_attendee_reminder( $booking_id ): void {
		$this->dispatch_reminder( (int) $booking_id, 'attendee_reminder' );
	}

	/**
	 * Send a reminder SMS for a specific template key.
	 *
	 * Called from the `booking_organizer_reminder` / `booking_attendee_reminder`
	 * WP-Cron hooks (scheduled by Free's {@see BookingTasks::schedule_reminders}).
	 * Unlike `send_for_event()`, the template key is known up-front so we skip
	 * the `$event_to_keys` lookup entirely.
	 */
	private function dispatch_reminder( int $booking_id, string $key ): void {
		try {
			$registry = doublescale_resolve( \DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry::class );
			if ( ! $registry ) {
				return;
			}
			$provider = $registry->get_provider( 'sms' );
			if ( ! $provider || ! $provider->is_configured() ) {
				return;
			}

			$booking = BookingModel::find( $booking_id );
			if ( ! $booking ) {
				return;
			}

			$sms_settings = $this->get_sms_settings( $booking );
			$notification = Arr::get( $sms_settings, $key );
			if ( empty( $notification ) ) {
				return;
			}

			$enabled = Arr::get( $notification, 'enabled', Arr::get( $notification, 'default', false ) );
			if ( ! $enabled ) {
				return;
			}

			$body = Arr::get( $notification, 'template.message', '' );
			if ( '' === $body ) {
				return;
			}

			$body = MergeTagsManager::instance()->process_merge_tags( $body, $booking );

			$recipient_type = $this->infer_recipient( $key );
			$phone          = $this->resolve_phone( $booking, $recipient_type );
			if ( '' === $phone ) {
				return;
			}

			$contact = $this->resolve_contact( $booking, $recipient_type );
			if ( ! $contact ) {
				return;
			}

			$result      = $provider->send_message( 'sms', array(
				'To'   => $phone,
				'Body' => $body,
			), $contact );

			$succeeded   = ! empty( $result['success'] );
			$external_id = $succeeded ? (string) ( $result['message_id'] ?? '' ) : '';

			$this->record_tracking_row(
				$booking,
				$contact,
				$phone,
				$succeeded ? TrackingStatus::SENT : TrackingStatus::FAILED,
				$external_id
			);

			if ( $succeeded ) {
				$booking->logs()->create( array(
					'type'    => 'info',
					'message' => sprintf( __( 'SMS sent to %s', 'doublescale' ), $phone ),
					'details' => sprintf( __( 'SMS notification "%s" sent for reminder', 'doublescale' ), $key ),
				) );
			} else {
				$error = (string) ( $result['error'] ?? __( 'Unknown provider error', 'doublescale' ) );
				$booking->logs()->create( array(
					'type'    => 'error',
					'message' => sprintf( __( 'SMS failed to %s', 'doublescale' ), $phone ),
					'details' => $error,
				) );
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Booking SMS reminder dispatch failed',
				array(
					'source'     => 'booking-pro-sms',
					'booking_id' => $booking_id,
					'key'        => $key,
					'exception'  => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Run the synchronous SMS send for an event. Wrapped in try/catch so a
	 * misconfigured SMS provider cannot break the request that emitted the
	 * lifecycle event — failures are logged on the booking instead.
	 */
	private function dispatch_send( string $event, $booking, $context ): void {
		if ( ! ( $booking instanceof BookingModel ) ) {
			return;
		}

		try {
			$this->send_for_event( (int) $booking->id, $event, is_array( $context ) ? $context : array() );
		} catch ( \Throwable $e ) {
			$booking->logs()->create( array(
				'type'    => 'error',
				'message' => __( 'SMS notification failed', 'doublescale' ),
				'details' => $e->getMessage(),
			) );
		}
	}

	/**
	 * Send SMS notifications for a booking lifecycle event.
	 *
	 * Reads the bookable entity's `sms_notifications` meta, picks the template
	 * keys for the (event, actor) pair, processes merge tags, resolves
	 * recipient phone & CRM contact, and delegates to the CRM messaging stack.
	 */
	public function send_for_event( int $booking_id, string $event, array $context = array() ): void {
		$registry = doublescale_resolve( \DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry::class );
		if ( ! $registry ) {
			return;
		}

		$provider = $registry->get_provider( 'sms' );
		if ( ! $provider || ! $provider->is_configured() ) {
			return;
		}

		$booking = BookingModel::find( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$sms_settings = $this->get_sms_settings( $booking );
		if ( empty( $sms_settings ) ) {
			return;
		}

		$keys = $this->resolve_template_keys( $event, $context );
		if ( empty( $keys ) ) {
			return;
		}

		$merge_tags = MergeTagsManager::instance();

		foreach ( $keys as $key ) {
			$notification = Arr::get( $sms_settings, $key );
			if ( empty( $notification ) ) {
				continue;
			}

			$enabled = Arr::get( $notification, 'enabled', Arr::get( $notification, 'default', false ) );
			if ( ! $enabled ) {
				continue;
			}

			$body = Arr::get( $notification, 'template.message', '' );
			if ( empty( $body ) ) {
				continue;
			}

			$body = $merge_tags->process_merge_tags( $body, $booking );

			$recipient_type = Arr::get( $notification, 'recipient', $this->infer_recipient( $key ) );
			$phone          = $this->resolve_phone( $booking, $recipient_type );
			if ( empty( $phone ) ) {
				continue;
			}

			$contact = $this->resolve_contact( $booking, $recipient_type );
			if ( ! $contact ) {
				$booking->logs()->create( array(
					'type'    => 'warning',
					'message' => sprintf( __( 'SMS skipped for "%s"', 'doublescale' ), $key ),
					'details' => __( 'No CRM contact email available for the recipient.', 'doublescale' ),
				) );
				continue;
			}

			try {
				$result = $provider->send_message( 'sms', array(
					'To'   => $phone,
					'Body' => $body,
				), $contact );

				$succeeded   = ! empty( $result['success'] );
				$external_id = $succeeded ? (string) ( $result['message_id'] ?? '' ) : '';

				$this->record_tracking_row(
					$booking,
					$contact,
					$phone,
					$succeeded ? TrackingStatus::SENT : TrackingStatus::FAILED,
					$external_id
				);

				if ( $succeeded ) {
					$booking->logs()->create( array(
						'type'    => 'info',
						'message' => sprintf( __( 'SMS sent to %s', 'doublescale' ), $phone ),
						'details' => sprintf( __( 'SMS notification "%s" sent for %s', 'doublescale' ), $key, $event ),
					) );
				} else {
					$error = (string) ( $result['error'] ?? __( 'Unknown provider error', 'doublescale' ) );
					$booking->logs()->create( array(
						'type'    => 'error',
						'message' => sprintf( __( 'SMS failed to %s', 'doublescale' ), $phone ),
						'details' => $error,
					) );
				}
			} catch ( \Throwable $e ) {
				$this->record_tracking_row( $booking, $contact, $phone, TrackingStatus::FAILED, '' );

				$booking->logs()->create( array(
					'type'    => 'error',
					'message' => sprintf( __( 'SMS failed to %s', 'doublescale' ), $phone ),
					'details' => $e->getMessage(),
				) );
			}
		}
	}

	/**
	 * Persist a `CommunicationTracking` row for an outbound booking SMS so that
	 * per-contact SMS history, dashboards and analytics include lifecycle sends
	 * (parity with email — see {@see EmailNotifications::record_tracking_row()}).
	 *
	 * A failed write must NOT abort the SMS dispatch; we swallow the exception
	 * and log it.
	 */
	private function record_tracking_row(
		BookingModel $booking,
		ContactModel $contact,
		string $recipient,
		int $status,
		string $external_id
	): void {
		try {
			CommunicationTrackingModel::create( array(
				'contact_id'  => (int) $contact->id,
				'template_id' => null,
				'hash_key'    => CoreUtils::generate_hash_key(),
				'mode'        => CommunicationTrackingModel::MODE_SMS,
				'direction'   => MessageDirection::OUTBOUND,
				'source_type' => MessageSourceTypes::BOOKING,
				'source_id'   => (int) $booking->id,
				'author_id'   => null,
				'recipient'   => $recipient,
				'status'      => $status,
				'external_id' => $external_id ?: null,
				'sent_at'     => current_time( 'mysql', true ),
			) );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Failed to record booking SMS tracking row',
				array(
					'source'     => 'booking-pro-sms',
					'booking_id' => (int) $booking->id,
					'exception'  => $e->getMessage(),
				)
			);
		}
	}

	private function resolve_template_keys( string $event, array $context ): array {
		$variants = self::$event_to_keys[ $event ] ?? array();
		if ( empty( $variants ) ) {
			return array();
		}

		$actor = isset( $context['actor'] ) ? (string) $context['actor'] : '';
		if ( '' !== $actor && isset( $variants[ $actor ] ) ) {
			return $variants[ $actor ];
		}

		return $variants['*'] ?? array();
	}

	private function get_sms_settings( BookingModel $booking ): array {
		if ( $booking->event_id && $booking->event ) {
			return $booking->event->sms_notifications ?? array();
		}
		return array();
	}

	private function infer_recipient( string $key ): string {
		if ( strpos( $key, 'attendee' ) !== false ) {
			return 'attendee';
		}
		return 'organizer';
	}

	private function resolve_phone( BookingModel $booking, string $type ): string {
		if ( 'attendee' === $type ) {
			$contact = $booking->contact;
			if ( $contact ) {
				$phone = $contact->phone ?? '';
				if ( $phone ) {
					return $phone;
				}
			}
			return '';
		}

		[ $phone, ] = self::resolve_organizer_phone_for( $booking->event ?? null, $booking->calendar ?? null );
		return $phone;
	}

	/**
	 * Resolve the organizer's phone for a known booking.
	 *
	 * Thin wrapper over {@see resolve_organizer_phone_for()} kept for callers
	 * that already have a `BookingModel` in hand.
	 *
	 * @return array{0: string, 1: ?string}
	 */
	public static function resolve_organizer_phone( BookingModel $booking ): array {
		return self::resolve_organizer_phone_for( $booking->event ?? null, $booking->calendar ?? null );
	}

	/**
	 * Resolve the organizer's phone, walking a fallback chain.
	 *
	 * Sources, in order:
	 *   1. Event meta location of `type === 'person_phone'`
	 *   2. Calendar owner's `wp_usermeta.phone`
	 *   3. Calendar owner's `wp_usermeta.billing_phone` (WooCommerce convention)
	 *   4. Calendar owner's CRM contact `phone` (matched by user email)
	 *
	 * Returns `[$phone, $source]` where `$source` is `'location' | 'usermeta_phone'
	 * | 'usermeta_billing_phone' | 'contact' | null`. Empty `$phone` + null
	 * source means nothing matched.
	 *
	 * Takes the event + calendar directly rather than a `BookingModel` so the
	 * REST `sms-organizer-phone-status` endpoint (which has no booking) can
	 * call the same resolver.
	 *
	 * @param mixed $event    EventModel or null.
	 * @param mixed $calendar CalendarModel or null.
	 * @return array{0: string, 1: ?string}
	 */
	public static function resolve_organizer_phone_for( $event, $calendar ): array {
		if ( $event && method_exists( $event, 'get_meta' ) ) {
			$locations = $event->get_meta( 'location' );
			if ( is_string( $locations ) && strpos( $locations, 'a:' ) === 0 ) {
				$locations = maybe_unserialize( $locations );
			}
			if ( is_array( $locations ) ) {
				foreach ( $locations as $item ) {
					if ( ! is_array( $item ) || ( $item['type'] ?? '' ) !== 'person_phone' ) {
						continue;
					}
					$raw = $item['fields']['phone'] ?? '';
					if ( '' === $raw ) {
						continue;
					}
					$normalized = self::normalize_phone( (string) $raw );
					if ( '' !== $normalized ) {
						return array( $normalized, 'location' );
					}
				}
			}
		}

		$owner_id = $calendar ? (int) ( $calendar->user_id ?? 0 ) : 0;
		if ( $owner_id > 0 ) {
			foreach ( array( 'phone' => 'usermeta_phone', 'billing_phone' => 'usermeta_billing_phone' ) as $meta_key => $source_label ) {
				$meta       = (string) get_user_meta( $owner_id, $meta_key, true );
				$normalized = self::normalize_phone( $meta );
				if ( '' !== $normalized ) {
					return array( $normalized, $source_label );
				}
			}

			$owner       = get_userdata( $owner_id );
			$owner_email = $owner ? sanitize_email( (string) $owner->user_email ) : '';
			if ( '' !== $owner_email ) {
				$crm_contact = ContactModel::where( 'email', $owner_email )->first();
				$normalized  = self::normalize_phone( (string) ( $crm_contact->phone ?? '' ) );
				if ( '' !== $normalized ) {
					return array( $normalized, 'contact' );
				}
			}
		}

		// Final fallback: the Twilio From number itself. The site has configured Twilio
		// with a real phone number; treat it as the organizer's "default" SMS line so
		// that organizer notifications land *somewhere* even when no personal phone is
		// set. (Routes to the same number that's sending the SMS — visible in the
		// Twilio dashboard and any SMS forwarding the org has set up.)
		$twilio_phone = self::twilio_from_number();
		if ( '' !== $twilio_phone ) {
			return array( $twilio_phone, 'twilio_default' );
		}

		return array( '', null );
	}

	/**
	 * Look up the configured Twilio sending number for the fallback chain.
	 *
	 * Reads directly from the integration option to avoid coupling to the
	 * provider's `connect()` lifecycle (which may not be initialized at filter
	 * time for the REST status endpoint).
	 */
	private static function twilio_from_number(): string {
		$settings = get_option( 'doublescale_twilio_settings', array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		$raw = (string) ( $settings['phone_number'] ?? '' );
		return self::normalize_phone( $raw );
	}

	private static function normalize_phone( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		return str_starts_with( $raw, '+' ) ? $raw : '+' . $raw;
	}

	/**
	 * Resolve the CRM contact tied to this SMS recipient. Returns null when no
	 * real email is available — callers should skip the send rather than us
	 * fabricating a synthetic placeholder row in `wp_contacts`.
	 *
	 * For attendees we use the guest email; for organizers we use the calendar
	 * owner's WP user email. In both cases we `firstOrCreate` so the contact
	 * exists for future inbox/timeline correlation, but only when the email is real.
	 */
	private function resolve_contact( BookingModel $booking, string $type ): ?ContactModel {
		$email      = '';
		$first_name = '';
		$last_name  = '';

		if ( 'attendee' === $type && $booking->contact ) {
			// Attendee branch: $booking->contact IS the CRM contact already — just return it
			// directly. The firstOrCreate-on-email below is still correct for the organizer
			// branch, where $type === 'organizer' and we're looking up the calendar-owner WP user.
			return $booking->contact;
		}

		if ( 'organizer' === $type ) {
			$calendar = $booking->calendar;
			if ( $calendar && $calendar->user ) {
				$email      = sanitize_email( $calendar->user->user_email ?? '' );
				$first_name = $calendar->user->first_name ?? '';
				$last_name  = $calendar->user->last_name ?? '';
			}
		}

		if ( empty( $email ) ) {
			return null;
		}

		return ContactModel::firstOrCreate(
			array( 'email' => $email ),
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'source'     => 'booking',
			)
		);
	}
}
