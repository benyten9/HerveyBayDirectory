<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Contacts\Models\ContactModel;

class BookingModel extends Model {

	const NON_ACTIVE_STATUSES = array( 'cancelled', 'waiting' );

	protected $table = 'doublescale_bookings';

	protected $primary_key = 'id';

	public $timestamps = true;

	protected $fillable = array(
		'hash_id',
		'event_id',
		'calendar_id',
		'contact_id',
		'start_time',
		'end_time',
		'slot_time',
		'source',
		'status',
		'cancelled_by',
		'event_url',
	);

	protected $appends = array( 'timezone', 'fields', 'location' );

	protected $casts = array(
		'event_id'     => 'integer',
		'contact_id'   => 'integer',
		'slot_time'    => 'integer',
		'calendar_id'  => 'integer',
		'cancelled_by' => 'array',
	);

	protected $rules = array(
		'event_id'    => 'nullable|integer',
		'calendar_id' => 'required|integer',
		'contact_id'  => 'required|integer',
		'start_time'  => 'required|date_format:Y-m-d H:i:s',
		'end_time'    => 'required|date_format:Y-m-d H:i:s',
		'slot_time'   => 'required|integer',
	);

	protected $messages = array(
		'event_id.integer'       => 'The event ID must be a valid integer.',
		'contact_id.required'    => 'The contact ID is required.',
		'contact_id.integer'     => 'The contact ID must be a valid integer.',
		'start_time.required'    => 'The start time is required.',
		'start_time.date_format' => 'The start time must be in the format Y-m-d H:i:s.',
		'end_time.required'      => 'The end time is required.',
		'end_time.date_format'   => 'The end time must be in the format Y-m-d H:i:s.',
		'slot_time.required'     => 'The slot time is required.',
		'slot_time.integer'      => 'The slot time must be an integer.',
	);

	public function calendar() {
		return $this->belongsTo( CalendarModel::class, 'calendar_id', 'id' );
	}

	public function event() {
		return $this->belongsTo( EventModel::class, 'event_id', 'id' );
	}

	public function hosts() {
		return $this->hasManyThrough(
			UserModel::class,
			BookingHostsModel::class,
			'booking_id',
			'ID',
			'id',
			'user_id'
		);
	}

	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id' );
	}

	/**
	 * Render the contact's display name for templates and notifications. Falls back to
	 * the contact's email when no name is stored. Returns empty string if the contact
	 * relation is missing (dangling FK after a hard contact delete — see edge case #3
	 * in plans/zany-plotting-creek.md).
	 */
	public function getContactDisplayName(): string {
		if ( ! $this->contact ) {
			return '';
		}
		$name = trim( ( $this->contact->first_name ?? '' ) . ' ' . ( $this->contact->last_name ?? '' ) );
		return '' !== $name ? $name : (string) ( $this->contact->email ?? '' );
	}

	public function meta() {
		return $this->hasMany( BookingMetaModel::class, 'booking_id', 'id' );
	}

	public function logs() {
		return $this->hasMany( BookingLogModel::class, 'booking_id', 'id' );
	}

	public function order() {
		return $this->hasOne( BookingOrderModel::class, 'booking_id', 'id' );
	}

	public function getBookableEntity() {
		return $this->event_id && $this->event ? $this->event : null;
	}

	public function getBookableName() {
		$entity = $this->getBookableEntity();
		return $entity ? $entity->name : '';
	}

	public function processMergeTagsEvent() {
		$event = $this->event;
		if ( ! $event ) {
			return '';
		}
		$event->advanced_settings = $event->getAdvancedSettingsAttribute();
		$merge_tags_manager       = \DoubleScale\Modules\Booking\Managers\MergeTagsManager::instance();
		$result                   = $merge_tags_manager->process_merge_tags(
			$event->advanced_settings['event_title'] ?? $event->name,
			$this
		);
		return $result;
	}

	public function getNotificationSettings() {
		if ( $this->event_id && $this->event ) {
			return $this->event->email_notifications ?? array();
		}
		return array();
	}

	public function getSmsNotificationSettings() {
		if ( $this->event_id && $this->event ) {
			return $this->event->sms_notifications ?? array();
		}
		return array();
	}

	public function getAdvancedSettings() {
		if ( $this->event_id && $this->event ) {
			return $this->event->advanced_settings ?? array();
		}
		return array();
	}

	public function getPaymentSettingsFromEntity() {
		if ( $this->event_id && $this->event ) {
			return $this->event->payments_settings ?? array();
		}
		return array();
	}

	public function getHostTimezone() {
		if ( $this->event_id && $this->event && isset( $this->event->availability['timezone'] ) ) {
			return $this->event->availability['timezone'];
		}
		if ( $this->calendar ) {
			$calendar_meta = $this->calendar->get_meta( 'timezone', null );
			if ( $calendar_meta ) {
				return $calendar_meta;
			}
		}
		return $this->getTimezoneAttribute() ?? 'UTC';
	}

	/**
	 * WordPress user ID that owns the booking's event/calendar (team owner).
	 *
	 * @return int|null
	 */
	public function getOwnerUserId() {
		if ( $this->event_id && $this->event ) {
			$user_id = $this->event->user_id;
			return ( null !== $user_id && '' !== $user_id ) ? (int) $user_id : null;
		}
		if ( $this->calendar ) {
			$user_id = $this->calendar->user_id;
			return ( null !== $user_id && '' !== $user_id ) ? (int) $user_id : null;
		}
		return null;
	}

	/**
	 * Whether the user may view/manage this booking under own-scope permissions.
	 *
	 * Mirrors {@see \DoubleScale\Modules\Booking\Rest\Controllers\RestBookingController::apply_user_filter()}:
	 * personal host-calendar owners, team-event assigned hosts, and calendar owners.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function userCanAccessAsStaff( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$owner_id = $this->getOwnerUserId();
		if ( null !== $owner_id && $owner_id === $user_id ) {
			return true;
		}

		if ( ! $this->relationLoaded( 'calendar' ) ) {
			$this->load( 'calendar' );
		}
		$calendar = $this->calendar;
		if ( $calendar && 'host' === $calendar->type && (int) $calendar->user_id === $user_id ) {
			return true;
		}

		if ( ! $this->relationLoaded( 'hosts' ) ) {
			$this->load( 'hosts' );
		}
		foreach ( $this->hosts as $host ) {
			if ( (int) $host->ID === $user_id ) {
				return true;
			}
		}

		return false;
	}

	public function changeStatus( $status ) {
		$this->status = $status;
		$this->save();
	}

	public static function getByHashId( $hash_id ) {
		return self::where( 'hash_id', $hash_id )->with( 'event', 'contact' )->first();
	}

	public function get_meta( $key, $default = null ) {
		$meta = $this->meta()->where( 'meta_key', $key )->first();
		return $meta ? maybe_unserialize( $meta->meta_value ) : $default;
	}

	public function update_meta( $key, $value ) {
		$meta             = $this->meta()->where( 'meta_key', $key )->firstOrNew( array( 'meta_key' => $key ) );
		$meta->meta_value = maybe_serialize( $value );
		$meta->save();
	}

	public function getFieldsAttribute() {
		$value = $this->meta()->where( 'meta_key', 'fields' )->value( 'meta_value' );
		return $value ? maybe_unserialize( $value ) : null;
	}

	public function getTimezoneAttribute() {
		$value = $this->meta()->where( 'meta_key', 'timezone' )->value( 'meta_value' );
		return $value ? maybe_unserialize( $value ) : null;
	}

	public function getEventTimezoneAttribute() {
		if ( $this->event ) {
			return $this->event->timezone;
		}
		return $this->getTimezoneAttribute() ?? 'UTC';
	}

	public function setTimezoneAttribute( $value ) {
		$meta             = $this->meta()->firstOrNew( array( 'meta_key' => 'timezone' ) );
		$meta->meta_value = maybe_serialize( $value );
		$meta->save();
	}

	public function getLocationAttribute() {
		$location = $this->meta()->where( 'meta_key', 'location' )->value( 'meta_value' );
		return $location ? maybe_unserialize( $location ) : null;
	}

	public function setLocationAttribute( $value ) {
		$meta             = $this->meta()->firstOrNew( array( 'meta_key' => 'location' ) );
		$meta->meta_value = maybe_serialize( $value );
		$meta->save();
	}

	public function setFieldsAttribute( $value ) {
		$meta             = $this->meta()->firstOrNew( array( 'meta_key' => 'fields' ) );
		$meta->meta_value = maybe_serialize( $value );
		$meta->save();
	}

	public function isCompleted() {
		$end_time = new \DateTime( $this->end_time, new \DateTimeZone( 'UTC' ) );
		$now      = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		return $end_time < $now;
	}

	public function isCancelled() {
		return 'cancelled' === $this->status;
	}

	public function isWaiting() {
		return 'waiting' === $this->status;
	}

	public function scopeActive( $query ) {
		return $query->whereNotIn( 'status', self::NON_ACTIVE_STATUSES );
	}

	public function getWaitingListClaimUrl() {
		return add_query_arg(
			array(
				'doublescale_booking' => '1',
				'type'                => 'claim_waitlist',
				'id'                  => $this->hash_id,
			),
			home_url()
		);
	}

	public function getWaitingListPositionAttribute() {
		return $this->get_meta( 'waiting_list_position', null );
	}

	/**
	 * Recompute `waiting_list_position` meta for everyone still waiting on a
	 * given slot after one booking has just left the queue (cancel / promote /
	 * claim). Best-effort: a logged warning on failure is preferred over
	 * aborting the parent operation, since FIFO order is preserved by
	 * `created_at` and only the displayed position drifts.
	 *
	 * @param self $left The booking that just transitioned out of `waiting`.
	 */
	public static function rebalanceWaitingListPositions( self $left ): void {
		if ( empty( $left->event_id ) ) {
			return;
		}
		try {
			$waiting = self::where( 'status', 'waiting' )
				->where( 'event_id', $left->event_id )
				->where( 'start_time', $left->start_time )
				->where( 'end_time', $left->end_time )
				->orderBy( 'created_at', 'asc' )
				->get();
			foreach ( $waiting as $i => $wl ) {
				$wl->update_meta( 'waiting_list_position', $i + 1 );
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->warning(
				'Failed to rebalance waiting list positions',
				array(
					'source'     => 'booking-waitlist',
					'booking_id' => (int) $left->id,
					'event_id'   => (int) $left->event_id,
					'error'      => $e->getMessage(),
				)
			);
		}
	}

	public function getCancelUrl() {
		return add_query_arg(
			array(
				'doublescale_booking' => '1',
				'type'                => 'cancel',
				'id'                  => $this->hash_id,
			),
			home_url()
		);
	}

	public function getRescheduleUrl() {
		return add_query_arg(
			array(
				'doublescale_booking' => '1',
				'type'                => 'reschedule',
				'id'                  => $this->hash_id,
			),
			home_url()
		);
	}

	public function getDetailsUrl() {
		$admin_url = admin_url( 'admin.php?page=doublescale&path=booking/bookings&id=' . $this->id );

		/**
		 * Filter the booking "details" URL used by the {{booking.details_url}}
		 * merge tag. The Client Portal module re-points this to the
		 * customer-facing portal booking detail when a portal page exists,
		 * falling back to this admin URL otherwise.
		 *
		 * @param string       $admin_url Default admin SPA URL.
		 * @param BookingModel $booking   Booking instance.
		 */
		return (string) apply_filters( 'doublescale_booking_details_url', $admin_url, $this );
	}

	public function getConfirmUrl() {
		return add_query_arg(
			array(
				'doublescale_booking' => '1',
				'type'                => 'confirm',
				'id'                  => $this->hash_id,
			),
			home_url()
		);
	}

	public function getRejectUrl() {
		return add_query_arg(
			array(
				'doublescale_booking_action' => 'reject',
				'id'                         => $this->hash_id,
			),
			$this->event_url
		);
	}

	public function requiresPayment() {
		if ( $this->event ) {
			return $this->event->requirePayment();
		}
		return false;
	}

	public function getPaymentStatus() {
		return $this->get_meta( 'payment_status', 'pending' );
	}

	public function setPaymentStatus( $status ) {
		$this->update_meta( 'payment_status', $status );

		if ( $status === 'completed' ) {
			$this->status = 'scheduled';
			$this->save();

			$this->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Payment completed', 'doublescale' ),
					'details' => __( 'Payment has been successfully processed', 'doublescale' ),
				)
			);

			do_action( 'doublescale_booking_payment_completed', $this );

			BookingEvents::emit( 'created', (int) $this->id, array( 'actor' => 'attendee' ) );
		} elseif ( $status === 'failed' ) {
			$this->status       = 'cancelled';
			$this->cancelled_by = array(
				'type'   => 'system',
				'reason' => 'payment_failed',
			);
			$this->save();

			$this->logs()->create(
				array(
					'type'    => 'error',
					'message' => __( 'Booking cancelled due to payment failure', 'doublescale' ),
					'details' => __( 'The booking has been automatically cancelled because the payment failed.', 'doublescale' ),
				)
			);

			do_action( 'doublescale_booking_payment_failed', $this );

			BookingEvents::emit(
				'cancelled',
				(int) $this->id,
				array(
					'actor'  => 'system',
					'reason' => 'payment_failed',
				)
			);
		}
	}

	public function getPaymentAmount() {
		return (float) $this->get_meta( 'payment_amount', 0 );
	}

	public function getPaymentCurrency() {
		return $this->get_meta( 'payment_currency', 'USD' );
	}

	public function save( array $options = array() ) {
		if ( ! $this->event_id ) {
			throw new \Exception( esc_html__( 'Booking must reference an event', 'doublescale' ) );
		}

		$event = EventModel::find( $this->event_id );
		if ( ! $event ) {
			throw new \Exception( esc_html__( 'Event does not exist', 'doublescale' ) );
		}

		return parent::save( $options );
	}

	public static function boot() {
		parent::boot();

		static::creating(
			function ( $booking ) {
				$booking->hash_id = wp_generate_uuid4();
				if ( ! $booking->status ) {
					$booking->status = 'scheduled';
				}
			}
		);

		// Cancelled emission must run on `deleting`, not `deleted`: after the row
		// is removed, {@see BookingEvents::emit()} cannot reload the booking, so
		// integrations (Google, Outlook, Apple, Zoom) would never remove remote events.
		static::deleting(
			function ( $booking ) {
				BookingEvents::emit(
					'cancelled',
					(int) $booking->id,
					array(
						'actor'  => 'system',
						'reason' => 'deleted',
					)
				);
			}
		);

		static::deleted(
			function ( $booking ) {
				BookedSlotModel::release( $booking->id );
				$booking->meta()->delete();
				$booking->logs()->delete();
				BookingHostsModel::where( 'booking_id', $booking->id )->delete();
			}
		);
	}

	public function get_transaction_id() {
		if ( $this->order ) {
			return $this->order->transaction_id;
		}
	}

	/**
	 * Email addresses for organizer-facing notifications (new booking, cancellations to host, etc.).
	 *
	 * For team calendars with round-robin or collective events, notifications go to the WordPress
	 * user(s) linked via booking_hosts — the actual meeting host(s) — not the team calendar owner's inbox.
	 *
	 * @return string[] Non-empty sanitized emails; falls back to the calendar owner's email.
	 */
	public function getOrganizerRecipientEmails() {
		if ( ! $this->calendar ) {
			$this->load( 'calendar.user' );
		}
		$calendar = $this->calendar;
		$fallback = '';
		if ( $calendar && $calendar->user ) {
			$fallback = sanitize_email( $calendar->user->user_email ?? '' );
		}

		if ( ! $this->event_id ) {
			return $fallback ? array( $fallback ) : array();
		}
		if ( ! $this->event ) {
			$this->load( 'event' );
		}
		$event = $this->event;

		if (
			$calendar && $event &&
			'team' === $calendar->type &&
			in_array( $event->type, array( 'round-robin', 'collective' ), true )
		) {
			$this->load( 'hosts' );
			$emails = array();
			foreach ( $this->hosts as $host_user ) {
				$e = sanitize_email( $host_user->user_email ?? '' );
				if ( $e ) {
					$emails[] = $e;
				}
			}
			$emails = array_values( array_unique( $emails ) );
			if ( ! empty( $emails ) ) {
				return $emails;
			}
		}

		return $fallback ? array( $fallback ) : array();
	}
}
