<?php

/**
 * Google Calendar / Meet Integration
 *
 * This class is responsible for handling the Google Calendar / Meet Integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Google;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\EventLocations\GoogleMeet;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Abstracts\Integration as Abstract_Integration;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Pro\Modules\Booking\Integrations\Google\Rest\REST_API;
use DoubleScale\Modules\Booking\BookingUtils;
use WP_Error;
/**
 * Google Integration class
 */
class Integration extends Abstract_Integration {


	/**
	 * Default cache time in minutes
	 */
	const DEFAULT_CACHE_TIME = 5;

	/**
	 * Integration Name
	 *
	 * @var string
	 */
	public $name = 'Google Calendar/Meet';

	/**
	 * Integration Slug
	 *
	 * @var string
	 */
	public $slug = 'google';

	/**
	 * Integration Description
	 *
	 * @var string
	 */
	public $description = 'Sync events and reminders across devices with Google Calendar. Stay organized and up-to-date on any device.';

	/**
	 * App
	 *
	 * @var App
	 */
	public $app;

	/**
	 * Classes
	 *
	 * @var array
	 */
	protected static $classes = array(
		'remote_data' => RemoteData::class,
		'rest_api'    => REST_API::class,
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		 $this->app = new App( $this );
		parent::__construct();
		\add_filter( 'doublescale_booking_get_available_slots', array( $this, 'get_available_slots' ), 10, 5 );
		add_action( 'doublescale_booking_initialize_default_settings', array( $this, 'initialize_default_settings' ) );
		add_action( 'doublescale_booking_created', array( $this, 'add_event_to_calendars' ) );
		add_action( 'doublescale_booking_cancelled', array( $this, 'remove_event_from_calendars' ) );
		add_action( 'doublescale_booking_rescheduled', array( $this, 'reschedule_event' ) );

	}

	/**
	 * Whether the booking location is Google Meet (null-safe for unset / in-person locations).
	 *
	 * @param object $booking Booking model with a `location` accessor.
	 */
	private function booking_has_google_meet_location( $booking ): bool {
		$location = $booking->location;
		return is_array( $location ) && ( $location['type'] ?? '' ) === GoogleMeet::instance()->slug;
	}

	/**
	 * Reschedule event in calendars
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|BookingModel $booking_id Booking id, numeric string, or model.
	 *
	 * @return BookingModel
	 */
	public function reschedule_event( $booking_id ) {
		$booking = $this->resolve_booking( $booking_id );
		if ( ! $booking ) {
			return $booking;
		}
		if ( ! $this->booking_has_google_meet_location( $booking ) ) {
			return $booking;
		}
		if ( ! $booking->hosts ) {
			return $booking;
		}

		$integration_calendar = $this->get_integration_host_calendar_for_booking( $booking );
		if ( ! $integration_calendar ) {
			return $booking;
		}
		$this->set_host( $integration_calendar );

		$google_events = $booking->get_meta( 'google_events_details', array() );
		if ( empty( $google_events ) ) {
			return $booking;
		}

		foreach ( $google_events as $storage_key => $google_event ) {
			$gcal_event_id = Arr::get( $google_event, 'event.id' );
			$account_id    = Arr::get( $google_event, 'account_id' );
			$calendar_id   = Arr::get( $google_event, 'calendar_id' );

			$api = $this->connect( $integration_calendar->id, $account_id );
			if ( ! $api || \is_wp_error( $api ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Google Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Google Account %2$s.', 'doublescale' ),
							$integration_calendar->name,
							$account_id
						),
					)
				);
				continue;
			}

			$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			$end_date   = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

			$event_data = array(
				'start' => array(
					'dateTime' => $start_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'end'   => array(
					'dateTime' => $end_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
			);

			$response = $api->update_event( $calendar_id, $gcal_event_id, $event_data );
			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error rescheduling event in Google Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error rescheduling event in Google Calendar %1$s: %2$s', 'doublescale' ),
							$calendar_id,
							Arr::get( $response, 'data.error.message' )
						),
					)
				);
				continue;
			}

			$event                = Arr::get( $response, 'data' );
			$meta                 = $booking->get_meta( 'google_events_details', array() );
			$meta[ $storage_key ] = array(
				'event'       => $event,
				'calendar_id' => $calendar_id,
				'account_id'  => $account_id,
			);

			$booking->update_meta(
				'google_events_details',
				$meta
			);

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event rescheduled in Google Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s rescheduled in Google Calendar %2$s.', 'doublescale' ),
						$gcal_event_id,
						$calendar_id
					),
				)
			);
		}

		$bookable = $booking->getBookableEntity();
		if ( $bookable && 'collective' === $bookable->type ) {
			$this->sync_cohost_busy_events( $booking, 'reschedule' );
		}

		return $booking;
	}

	/**
	 * Remove event from calendars
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|BookingModel $booking_id Booking id, numeric string, or model.
	 *
	 * @return BookingModel
	 */
	public function remove_event_from_calendars( $booking_id ) {
		$booking = $this->resolve_booking( $booking_id );
		if ( ! $booking ) {
			return $booking;
		}

		if ( ! $booking->hosts ) {
			return $booking;
		}

		$integration_calendar = $this->get_integration_host_calendar_for_booking( $booking );
		if ( ! $integration_calendar ) {
			return $booking;
		}
		$this->set_host( $integration_calendar );

		$google_events = $booking->get_meta( 'google_events_details', array() );
		if ( empty( $google_events ) ) {
			return $booking;
		}

		foreach ( $google_events as $event_id => $google_event ) {
			$account_id  = Arr::get( $google_event, 'account_id' );
			$calendar_id = Arr::get( $google_event, 'calendar_id' );

			$api = $this->connect( $integration_calendar->id, $account_id );
			if ( ! $api ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Google Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Google Account %2$s.', 'doublescale' ),
							$integration_calendar->name,
							$account_id
						),
					)
				);
				continue;
			}

			$response = $api->delete_event( $calendar_id, $event_id );
			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error removing event from Google Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error removing event from Google Calendar %1$s: %2$s', 'doublescale' ),
							$calendar_id,
							Arr::get( $response, 'data.error.message' )
						),
					)
				);
				continue;
			}

			$meta = $booking->get_meta( 'google_events_details', array() );
			Arr::forget( $meta, $event_id );

			$booking->update_meta(
				'google_events_details',
				$meta
			);

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event removed from Google Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s removed from Google Calendar %2$s.', 'doublescale' ),
						$event_id,
						$calendar_id
					),
				)
			);
		}

		$bookable = $booking->getBookableEntity();
		if ( $bookable && 'collective' === $bookable->type ) {
			$this->sync_cohost_busy_events( $booking, 'remove' );
		}

		return $booking;
	}

	/**
	 * Add one Google Calendar event on the host’s selected remote default calendar (Meet optional).
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|BookingModel $booking Booking id, numeric string, or model.
	 *
	 * @return BookingModel
	 */
	public function add_event_to_calendars( $booking, $is_google_meeting = true, $zoom_link = null, $is_zoom = false ) {
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) {
			return $booking;
		}
		try {

			if ( ! $booking->hosts ) {
				return $booking;
			}

			if ( ! $is_zoom ) {
				// Early return if booking location is not Google Meet
				if ( ! $this->booking_has_google_meet_location( $booking ) ) {
					return $booking;
				}
			}

			$bookable_entity  = $booking->getBookableEntity();
			$booking_calendar = $booking->calendar;

			if ( ! $bookable_entity || ! $booking_calendar ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Invalid event or calendar configuration.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$organizer   = $booking_calendar->user;
			$entity_name = $bookable_entity->name;
			$attendees   = array();

			$mainHost = array(
				'display_name'   => $organizer->display_name,
				'email'          => $organizer->user_email,
				'organizer'      => true,
				'responseStatus' => 'accepted',
			);

			$mainGuest = array(
				'display_name'   => $booking->getContactDisplayName(),
				'email'          => $booking->contact->email ?? '',
				'responseStatus' => 'accepted',
			);

			$additionalHosts  = array();
			$additionalGuests = array();

			foreach ( $booking->hosts as $host ) {
				$additionalHosts[] = array(
					'display_name'   => $host->display_name,
					'email'          => $host->user_email,
					'responseStatus' => 'accepted',
				);
			}

			if ( isset( $booking->fields['additional_guests'] ) && is_array( $booking->fields['additional_guests'] ) ) {
				foreach ( $booking->fields['additional_guests'] as $guest ) {
					$additionalGuests[] = array(
						'display_name'   => $guest,
						'email'          => $guest,
						'responseStatus' => 'accepted',
					);
				}
			}

			// remove main host from additional hosts
			$additionalHosts = array_filter(
				$additionalHosts,
				function ( $host ) use ( $mainHost ) {
					return $host['email'] !== $mainHost['email'];
				}
			);

			$attendees = array_merge(
				array( $mainGuest ),
				$additionalHosts,
				$additionalGuests
			);

			$integration_calendar = $this->get_integration_host_calendar_for_booking( $booking );
			if ( ! $integration_calendar ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Google Calendar integration could not resolve the host calendar for this booking.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$this->set_host( $integration_calendar );
			$google_integration = $this->host->get_meta( $this->meta_key, array() );

			if ( empty( $google_integration ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Google Calendar integration not configured.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$default_account = $this->pick_host_account_for_default_calendar_sync( $google_integration );

			if ( ! $default_account ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'No default calendar found in any Google account.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$api = $this->connect( $integration_calendar->id, $default_account['id'] );
			if ( ! $api ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Google Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Google Account %2$s.', 'doublescale' ),
							$this->host->name,
							$default_account['id']
						),
					)
				);
				return $booking;
			}

			$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			$end_date   = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

			$event_data = array(
				'summary'                 => sprintf( __( '%1$s: %2$s', 'doublescale' ), $booking->getContactDisplayName(), $entity_name ),
				'description'             => $this->get_event_description( $booking, $organizer ),
				'location'                => is_array( $booking->location ) ? (string) ( $booking->location['label'] ?? '' ) : '',
				'source'                  => array(
					'title' => $integration_calendar->name,
					'url'   => $booking->event_url,
				),
				'organizer'               => array(
					'email'        => $organizer->user_email,
					'display_name' => $organizer->display_name,
				),
				'attendees'               => $attendees,
				'guestsCanSeeOtherGuests' => Arr::get( $default_account['data'], 'config.settings.guests_can_see_others', true ),
				'sendUpdates'             => Arr::get( $default_account['data'], 'config.settings.enable_notifications', false ) ? 'all' : 'none',
				'start'                   => array(
					'dateTime' => $start_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'end'                     => array(
					'dateTime' => $end_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'guestsCanInviteOthers'   => false,
				'extendedProperties'      => array(
					'shared' => array(
						'created_by' => 'doublescale',
						'site_uid'   => $this->get_site_uid(),
						'event_id'   => $booking->event_id ?? '',
						'booking_id' => $booking->id,
					),
				),
				'transactionId'           => "{$this->get_site_uid()}-{$booking->id}",
			);

			if ( $is_google_meeting ) {
				$event_data['conferenceData'] = array(
					'createRequest' => array(
						'requestId'             => $booking->hash_id,
						'conferenceSolutionKey' => array(
							'type' => 'hangoutsMeet',
						),
					),
				);
			} else {
				$event_data['location'] = $zoom_link;
			}

			$response = $api->add_event( $default_account['default_calendar']['calendar_id'], $event_data );

			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error adding event to Google Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error adding event to Google Calendar %1$s: %2$s', 'doublescale' ),
							$default_account['default_calendar']['calendar_id'],
							Arr::get( $response, 'data.error.message' )
						),
					)
				);
				return $booking;
			}

			$event       = Arr::get( $response, 'data' );
			$id          = Arr::get( $event, 'id' );
			$meta        = $booking->get_meta( 'google_events_details', array() );
			$meta[ $id ] = array(
				'event'       => $event,
				'calendar_id' => $default_account['default_calendar']['calendar_id'],
				'account_id'  => $default_account['id'],
			);

			$booking->update_meta( 'google_events_details', $meta );

			if ( $is_google_meeting ) {
				$shared_hangout_link = Arr::get( $event, 'hangoutLink', '' );

				$booking->update_meta(
					'location',
					array(
						'type'  => GoogleMeet::instance()->slug,
						'label' => 'Google Meet',
						'value' => $shared_hangout_link,
					)
				);
			}

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event added to Google Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s added to Google Calendar %2$s.', 'doublescale' ),
						$id,
						$default_account['default_calendar']['calendar_id']
					),
				)
			);

			// For collective bookings, add a busy block on every co-host's
			// personal Google calendar (no meeting link, no attendees) so the
			// time is reserved in their remote calendar without creating
			// duplicate meetings.
			if ( $bookable_entity && 'collective' === $bookable_entity->type ) {
				$this->add_busy_block_to_team_members( $booking, $integration_calendar );
			}

			return $booking;
		} catch ( \Exception $e ) {
			if ( isset( $booking ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error adding event to Google Calendar.', 'doublescale' ),
						'details' => $e->getMessage(),
					)
				);
			}
			return $booking;
		}
	}

	/**
	 * For collective bookings, mirror the owner's meeting onto each non-owner
	 * host's personal Google calendar. The owner already has the real event
	 * (created via add_event_to_calendars) with the Google Meet link and full
	 * attendee list; the co-host event reuses that same link so the host can
	 * join from their own calendar UI without us minting a duplicate Meet.
	 *
	 * Differences from the owner's event:
	 *   - no `conferenceData` (we are NOT creating a second Meet)
	 *   - location/Meet URL is copied from the booking meta written by the
	 *     owner flow, so co-hosts click the same join URL
	 *   - no `attendees` (Google would email them again as if invited twice)
	 *
	 * Skips:
	 *   - the owner themselves (already has the real event)
	 *   - hosts without a `type=host` personal calendar
	 *   - hosts whose personal calendar has no connected Google account
	 *
	 * Failure to write to any single co-host's calendar is logged but does NOT
	 * fail the booking — the owner's meeting is the source of truth.
	 *
	 * @param BookingModel  $booking
	 * @param CalendarModel $owner_calendar Owner's host calendar (already written to).
	 */
	protected function add_busy_block_to_team_members( $booking, $owner_calendar ): void {
		if ( ! $booking->hosts ) {
			return;
		}
		$owner_user_id = (int) $owner_calendar->user_id;
		$bookable      = $booking->getBookableEntity();
		$entity_name   = $bookable ? $bookable->name : __( 'Meeting', 'doublescale' );
		$organizer     = $owner_calendar->user;
		$contact_name  = $booking->getContactDisplayName();

		// The owner's add_event_to_calendars persisted the Meet URL into the
		// 'location' booking meta after the create call returned. Re-read it
		// here (not $booking->location) so we always pick up the freshest URL.
		$owner_location = $booking->get_meta( 'location', array() );
		$meet_link      = is_array( $owner_location ) ? (string) Arr::get( $owner_location, 'value', '' ) : '';
		$location_label = is_array( $owner_location ) ? (string) Arr::get( $owner_location, 'label', '' ) : '';

		$shared_summary     = sprintf( __( '%1$s: %2$s', 'doublescale' ), $contact_name, $entity_name );
		$shared_description = $organizer ? $this->get_event_description( $booking, $organizer ) : '';

		$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
		$end_date   = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

		foreach ( $booking->hosts as $host ) {
			$host_user_id = (int) $host->ID;
			if ( $host_user_id === $owner_user_id ) {
				continue;
			}

			$host_calendar = \DoubleScale\Modules\Booking\Models\CalendarModel::where( 'user_id', $host_user_id )
				->where( 'type', 'host' )
				->first();
			if ( ! $host_calendar ) {
				continue;
			}

			$this->set_host( $host_calendar );
			$google_meta = $this->host->get_meta( $this->meta_key, array() );
			if ( empty( $google_meta ) ) {
				continue;
			}
			$account = $this->pick_host_account_for_default_calendar_sync( $google_meta );
			if ( ! $account ) {
				continue;
			}

			$api = $this->connect( $host_calendar->id, $account['id'] );
			if ( ! $api ) {
				continue;
			}

			$busy_event = array(
				'summary'            => $shared_summary,
				'description'        => $shared_description,
				'location'           => $meet_link ?: $location_label,
				'transparency'       => 'opaque',
				'start'              => array(
					'dateTime' => $start_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'end'                => array(
					'dateTime' => $end_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'extendedProperties' => array(
					'shared' => array(
						'created_by' => 'doublescale',
						'site_uid'   => $this->get_site_uid(),
						'booking_id' => $booking->id,
						'block_type' => 'co_host_busy',
					),
				),
				'transactionId'      => "{$this->get_site_uid()}-{$booking->id}-cohost-{$host_user_id}",
			);

			$response = $api->add_event( $account['default_calendar']['calendar_id'], $busy_event );
			if ( empty( $response['success'] ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'warning',
						'message' => __( 'Could not add busy block for co-host on Google Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Co-host user %1$d busy block failed: %2$s', 'doublescale' ),
							$host_user_id,
							(string) Arr::get( $response, 'data.error.message' )
						),
					)
				);
				continue;
			}

			$event_id              = Arr::get( $response, 'data.id' );
			$busy_meta             = $booking->get_meta( 'google_cohost_busy_events', array() );
			$busy_meta[ $event_id ] = array(
				'user_id'     => $host_user_id,
				'calendar_id' => $account['default_calendar']['calendar_id'],
				'account_id'  => $account['id'],
			);
			$booking->update_meta( 'google_cohost_busy_events', $busy_meta );

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Busy block added to co-host Google calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Co-host user %1$d: event %2$s on calendar %3$s', 'doublescale' ),
						$host_user_id,
						$event_id,
						$account['default_calendar']['calendar_id']
					),
				)
			);
		}
	}

	/**
	 * Reschedule or delete the busy blocks previously written to co-hosts'
	 * personal Google calendars by {@see add_busy_block_to_team_members()}.
	 *
	 * Reads the `google_cohost_busy_events` meta map (event_id => account/calendar
	 * pointer), iterates each entry, and either PATCHes the new start/end or
	 * DELETEs the event from the co-host's own integration account.
	 *
	 * Failures on individual co-host events are logged but never bubble up —
	 * the owner's meeting (the source of truth) has already been mutated by
	 * the caller and shouldn't be rolled back over a stale third-party event.
	 *
	 * @param BookingModel $booking
	 * @param string       $mode    'reschedule' or 'remove'.
	 */
	protected function sync_cohost_busy_events( $booking, string $mode ): void {
		$busy_meta = $booking->get_meta( 'google_cohost_busy_events', array() );
		if ( empty( $busy_meta ) ) {
			return;
		}

		$updated_meta = $busy_meta;
		$start_date   = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
		$end_date     = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

		foreach ( $busy_meta as $remote_event_id => $entry ) {
			$user_id     = (int) Arr::get( $entry, 'user_id' );
			$calendar_id = (string) Arr::get( $entry, 'calendar_id' );
			$account_id  = (string) Arr::get( $entry, 'account_id' );
			if ( ! $user_id || ! $calendar_id || ! $account_id ) {
				unset( $updated_meta[ $remote_event_id ] );
				continue;
			}

			$host_calendar = \DoubleScale\Modules\Booking\Models\CalendarModel::where( 'user_id', $user_id )
				->where( 'type', 'host' )
				->first();
			if ( ! $host_calendar ) {
				unset( $updated_meta[ $remote_event_id ] );
				continue;
			}

			$this->set_host( $host_calendar );
			$api = $this->connect( $host_calendar->id, $account_id );
			if ( ! $api ) {
				continue;
			}

			if ( 'reschedule' === $mode ) {
				$response = $api->update_event(
					$calendar_id,
					$remote_event_id,
					array(
						'start' => array(
							'dateTime' => $start_date->format( 'Y-m-d\TH:i:s' ),
							'timeZone' => 'UTC',
						),
						'end'   => array(
							'dateTime' => $end_date->format( 'Y-m-d\TH:i:s' ),
							'timeZone' => 'UTC',
						),
					)
				);
				if ( empty( $response['success'] ) ) {
					$booking->logs()->create(
						array(
							'type'    => 'warning',
							'message' => __( 'Could not reschedule co-host busy block on Google Calendar.', 'doublescale' ),
							'details' => sprintf(
								__( 'Co-host user %1$d, event %2$s: %3$s', 'doublescale' ),
								$user_id,
								$remote_event_id,
								(string) Arr::get( $response, 'data.error.message', '' )
							),
						)
					);
				} else {
					$booking->logs()->create(
						array(
							'type'    => 'info',
							'message' => __( 'Co-host busy block rescheduled on Google Calendar.', 'doublescale' ),
							'details' => sprintf( __( 'Co-host user %1$d, event %2$s', 'doublescale' ), $user_id, $remote_event_id ),
						)
					);
				}
			} elseif ( 'remove' === $mode ) {
				$response = $api->delete_event( $calendar_id, $remote_event_id );
				if ( empty( $response['success'] ) ) {
					$booking->logs()->create(
						array(
							'type'    => 'warning',
							'message' => __( 'Could not remove co-host busy block on Google Calendar.', 'doublescale' ),
							'details' => sprintf(
								__( 'Co-host user %1$d, event %2$s: %3$s', 'doublescale' ),
								$user_id,
								$remote_event_id,
								(string) Arr::get( $response, 'data.error.message', '' )
							),
						)
					);
				} else {
					unset( $updated_meta[ $remote_event_id ] );
					$booking->logs()->create(
						array(
							'type'    => 'info',
							'message' => __( 'Co-host busy block removed from Google Calendar.', 'doublescale' ),
							'details' => sprintf( __( 'Co-host user %1$d, event %2$s', 'doublescale' ), $user_id, $remote_event_id ),
						)
					);
				}
			}
		}

		if ( 'remove' === $mode ) {
			if ( empty( $updated_meta ) ) {
				$booking->delete_meta( 'google_cohost_busy_events' );
			} else {
				$booking->update_meta( 'google_cohost_busy_events', $updated_meta );
			}
		}
	}


	/**
	 * Get Google Meet event
	 *
	 * @since 1.0.0
	 *
	 * @param BookingModel $booking Booking model.
	 *
	 * @return array
	 */
	public function get_google_meet_event( $booking ) {
		$event = $booking->get_meta( 'google_events_details', array() );
		if ( empty( $event ) ) {
			return array();
		}
		return $event;
	}

	/**
	 * Get event description
	 *
	 * @since 1.0.0
	 *
	 * @param BookingModel $booking Booking model.
	 *
	 * @return string
	 */
	public function get_event_description( $booking, $organizer ) {
		$description = '';

		// When section
		$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( $booking->calendar->timezone ) );
		$end_date   = new \DateTime( $booking->end_time, new \DateTimeZone( $booking->calendar->timezone ) );

		$description .= sprintf(
			__( 'When:%1$s%2$s - %3$s, %4$s (%5$s)', 'doublescale' ),
			PHP_EOL,
			$start_date->format( 'g:i a' ), // 9:00 am
			$end_date->format( 'g:i a' ),   // 9:30 am
			$start_date->format( 'F j, Y' ), // September 22, 2025
			$booking->calendar->timezone    // Africa/Cairo
		);

		$description .= PHP_EOL . PHP_EOL;

		// Who section
		$description .= __( 'Who:', 'doublescale' ) . PHP_EOL;

		// Add organizer
		$description .= sprintf(
			__( '%s - Organizer', 'doublescale' ),
			$organizer->display_name
		) . PHP_EOL;
		$description .= $organizer->user_email . PHP_EOL;

		// Add main guest
		$description .= sprintf(
			__( '%s', 'doublescale' ),
			$booking->getContactDisplayName()
		) . PHP_EOL;
		$description .= ( $booking->contact->email ?? '' ) . PHP_EOL;

		// Add additional hosts if any (excluding the organizer)
		if ( $booking->hosts ) {
			foreach ( $booking->hosts as $host ) {
				if ( $host->ID !== $organizer->ID ) {
					$description .= sprintf(
						__( '%s', 'doublescale' ),
						$host->display_name
					) . PHP_EOL;
					$description .= $host->user_email . PHP_EOL;
				}
			}
		}

		// Add additional guests if any
		if ( isset( $booking->fields['additional_guests'] ) && is_array( $booking->fields['additional_guests'] ) ) {
			foreach ( $booking->fields['additional_guests'] as $guest ) {
				$description .= $guest . PHP_EOL;
			}
		}

		$description .= PHP_EOL;

		// Event Details section
		$entity       = $booking->getBookableEntity();
		$entity_name  = $entity ? $entity->name : __( 'Booking', 'doublescale' );
		$description .= sprintf(
			__( 'Event Details:', 'doublescale' ),
			$entity_name
		) . PHP_EOL;
		$description .= sprintf(
			__( 'Event: %s', 'doublescale' ),
			$entity_name
		) . PHP_EOL;

		return $description;
	}

	/**
	 * Get site UID
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_site_uid() {
		$site_uid = \get_option( 'doublescale_booking_site_uid' );
		if ( ! $site_uid ) {
			$site_uid = \wp_generate_uuid4();
			\update_option( 'doublescale_booking_site_uid', $site_uid );
		}
		return $site_uid;
	}

	/**
	 * Get available slots
	 *
	 * @since 1.0.0
	 *
	 * @param array      $slots Available slots.
	 * @param EventModel $event Event model.
	 * @param int        $start_date Start date timestamp.
	 * @param int        $end_date End date timestamp.
	 * @param string     $timezone Timezone.
	 *
	 * @return array
	 */
	public function get_available_slots( $slots, $event, $start_date, $end_date, $timezone ) {
		// Early return if event or calendar is not valid
		if ( ! $event || ! $event->calendar ) {
			return $slots;
		}

		$calendars = array();
		if ( $event->calendar->type === 'team' ) {
			$team_members = $event->calendar->getTeamMembers();
			if ( empty( $team_members ) ) {
				return $slots;
			}
			foreach ( $team_members as $member_id ) {
				$host = CalendarModel::where( 'user_id', $member_id )->where( 'type', 'host' )->first();
				if ( $host ) {
					$calendars[] = $host;
				}
			}
		} else {
			$calendars[] = $event->calendar;
		}

		// If no hosts found, return original slots
		if ( empty( $calendars ) ) {
			return $slots;
		}

		$host_slots = $slots;
		foreach ( $calendars as $calendar ) {
			$slots      = $this->get_available_slots_for_host( $host_slots, $calendar, $start_date, $end_date, $timezone, $calendar->user_id, $event->type );
			$host_slots = $slots;
		}
		return $host_slots;
	}


	/**
	 * Get Available Slots For Host
	 *
	 * @since 1.0.0
	 *
	 * @param array         $slots Available slots.
	 * @param CalendarModel $host Host calendar model.
	 * @param int           $start_date Start date timestamp.
	 * @param int           $end_date End date timestamp.
	 * @param string        $timezone Timezone.
	 *
	 * @return array
	 */
	private function get_available_slots_for_host( $slots, $host, $start_date, $end_date, $timezone, $user_id, $event_type ) {
		try {
			// Set host and get integration data
			$this->set_host( $host );
			$google_integration = $this->host->get_meta( $this->meta_key, array() );

			// Early return if no integration data
			if ( empty( $google_integration ) ) {
				return $slots;
			}

			foreach ( $google_integration as $account_id => $data ) {
				// Skip if account data is invalid
				if ( empty( $data ) || empty( $data['config'] ) || empty( $data['config']['calendars'] ) ) {
					continue;
				}

				$callback = function () use ( $host, $account_id, $start_date, $end_date, $timezone ) {
					return $this->get_account_data( $host->id, $account_id, $start_date, $end_date, $timezone );
				};

				// Get cache time from account data
				$settings    = $this->get_settings();
				$cache_time  = Arr::get( $settings, 'app.cache_time', null );
				$key         = "slots_{$start_date}_{$end_date}";
				$cached_data = $this->accounts->get_cache_data( $account_id, $key, $callback, $cache_time );

				if ( empty( $cached_data ) ) {
					continue;
				}

				foreach ( $cached_data as $calendar_id => $events ) {
					if ( ! is_array( $events ) ) {
						continue;
					}
					foreach ( $events as $event ) {
						if ( ! is_array( $event ) ) {
							continue;
						}
						$start = Arr::get( $event, 'start' );
						$end   = Arr::get( $event, 'end' );

						if ( ! $start || ! $end ) {
							continue;
						}

						$slots = $this->remove_booked_slot( $slots, $start, $end, $timezone, $user_id, $event_type );
					}
				}
			}

			return $slots;
		} catch ( \Exception $e ) {
			return $slots;
		}
	}
	/**
	 * Get account data
	 *
	 * @since 1.0.0
	 *
	 * @param int    $host_id Host ID.
	 * @param int    $account_id Account ID.
	 * @param int    $start_date Start date.
	 * @param int    $end_date End date.
	 * @param string $timezone Timezone.
	 *
	 * @return array
	 */
	public function get_account_data( $host_id, $account_id, $start_date, $end_date, $timezone ) {
		if ( ! $host_id || ! $account_id ) {
			return array();
		}

		$google_integration = $this->host->get_meta( $this->meta_key, array() );
		if ( empty( $google_integration ) ) {
			return array();
		}

		$account_data = Arr::get( $google_integration, $account_id, array() );
		if ( empty( $account_data ) ) {
			return array();
		}

		$calendars = Arr::get( $account_data, 'config.calendars', array() );
		if ( empty( $calendars ) ) {
			return array();
		}

		$api = $this->connect( $host_id, $account_id );
		if ( ! $api ) {
			return array();
		}

		$start_date = BookingUtils::create_date_time( $start_date, $timezone );
		$end_date   = BookingUtils::create_date_time( $end_date, $timezone );

		if ( ! $start_date || ! $end_date ) {
			return array();
		}

		$free_busy_args = array(
			'timeMin'  => $start_date->format( 'Y-m-d\TH:i:s\Z' ),
			'timeMax'  => $end_date->format( 'Y-m-d\TH:i:s\Z' ),
			'timeZone' => 'UTC',
		);

		$free_busy_response = $api->get_free_busy( $calendars, $free_busy_args );
		if ( ! $free_busy_response['success'] ) {
			return array();
		}

		$calendars_data = array();
		foreach ( Arr::get( $free_busy_response, 'data.calendars', array() ) as $calendar_id => $calendar_data ) {
			if ( Arr::has( $calendar_data, 'errors' ) ) {
				$calendar_events = $api->get_events( $calendar_id, $free_busy_args );
				if ( ! $calendar_events['success'] ) {
					continue;
				}
				$calendars_data[ $calendar_id ] = Arr::get( $calendar_events, 'data.items', array() );
				continue;
			}

			$busy_slots = Arr::get( $calendar_data, 'busy', array() );
			if ( ! empty( $busy_slots ) ) {
				$calendars_data[ $calendar_id ] = $busy_slots;
			}
		}

		return $calendars_data;
	}

	/**
	 * Remove booked slots from the given array of slots based on a Google Calendar event's time range.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $slots Multi-day slots.
	 * @param string $event_start Start date-time of the event in ISO 8601 format (UTC timezone).
	 * @param string $event_end End date-time of the event in ISO 8601 format (UTC timezone).
	 * @param string $timezone Timezone.
	 * @param int    $user_id User ID to remove from hosts_ids when slot conflicts.
	 *
	 * @return array Updated slots array.
	 */
	public function remove_booked_slot( $slots, $event_start, $event_end, $timezone, $user_id, $event_type ) {
		$event_start_timestamp = ( new \DateTime( $event_start, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$event_end_timestamp   = ( new \DateTime( $event_end, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		// Iterate through each day's slots.
		foreach ( $slots as $date => &$daily_slots ) {
			foreach ( $daily_slots as $slot_index => &$slot ) {
				$slot_start           = BookingUtils::create_date_time( $slot['start'], $timezone );
				$slot_end             = BookingUtils::create_date_time( $slot['end'], $timezone );
				$slot_start_timestamp = $slot_start->getTimestamp();
				$slot_end_timestamp   = $slot_end->getTimestamp();

				// Check if the slot overlaps with the event's time range
				$slot_overlaps = ! ( $slot_end_timestamp <= $event_start_timestamp || $slot_start_timestamp >= $event_end_timestamp );

				if ( $slot_overlaps ) {

					if ( $event_type === 'collective' ) {
						unset( $daily_slots[ $slot_index ] );
					}
					// Remove the user_id from hosts_ids if it exists
					if ( isset( $slot['hosts_ids'] ) && is_array( $slot['hosts_ids'] ) ) {
						$slot['hosts_ids'] = array_values(
							array_filter(
								$slot['hosts_ids'],
								function ( $host_id ) use ( $user_id ) {
									return (int) $host_id !== (int) $user_id;
								}
							)
						);

						// If hosts_ids is empty after removal, remove the entire slot
						if ( empty( $slot['hosts_ids'] ) ) {
							unset( $daily_slots[ $slot_index ] );
						}
					}
				}
			}

			// Re-index the array after potential removals
			$daily_slots = array_values( $daily_slots );

			// If no slots remain for a date, forget the entire date key.
			if ( empty( $daily_slots ) ) {
				Arr::forget( $slots, $date );
			}
		}

		return $slots;
	}

	/**
	 * Connect the integration
	 *
	 * @since 1.0.0
	 *
	 * @param int $host_id Host ID.
	 * @param int $account_id Account ID.
	 *
	 * @return bool|API
	 */
	public function connect( $host_id, $account_id ) {
		parent::connect( $host_id, $account_id );
		$account = $this->accounts->get_account( $account_id );

		$raw_tokens = Arr::get( $account, 'tokens', array() );
		if ( ! is_array( $raw_tokens ) ) {
			$raw_tokens = array();
		}
		$tokens        = App::normalize_token_array( $raw_tokens );
		$access_token  = isset( $tokens['access_token'] ) ? (string) $tokens['access_token'] : '';
		$refresh_token = isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : '';

		if ( '' === $access_token ) {
			return new \WP_Error( 'google_integration_error', __( 'Google Integration Error: Access token is empty.', 'doublescale' ) );
		}

		$this->api = new API( $access_token, $refresh_token, $this->app, $account_id );

		return $this->api;
	}

	/**
	 * Auth fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_auth_fields() {
		 return array(
			 'client_id'     => array(
				 'label'       => __( 'Client ID', 'doublescale' ),
				 'type'        => 'text',
				 'placeholder' => __( 'Enter your Google Client ID', 'doublescale' ),
				 'required'    => true,
			 ),
			 'client_secret' => array(
				 'label'       => __( 'Client Secret', 'doublescale' ),
				 'type'        => 'text',
				 'placeholder' => __( 'Enter your Google Client Secret', 'doublescale' ),
				 'required'    => true,
			 ),
		 );
	}

	/**
	 * Initialize default settings
	 *
	 * @since 1.0.0
	 */
	public function initialize_default_settings() {
		 $settings = $this->get_settings();
		if ( empty( $settings['app']['cache_time'] ) ) {
			$settings['app']['cache_time'] = self::DEFAULT_CACHE_TIME;
			$this->update_settings( $settings );
		}
	}
}
