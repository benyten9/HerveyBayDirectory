<?php

/**
 * Apple Calendar / Meet Integration
 *
 * This class is responsible for handling the Apple Calendar / Meet Integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Apple;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Abstracts\Integration as Abstract_Integration;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Pro\Modules\Booking\Integrations\Apple\Rest\REST_API;
use DoubleScale\Modules\Booking\BookingUtils;
use WP_Error;

/**
 * Apple Integration class
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
	public $name = 'Apple Calendar';

	/**
	 * Integration Slug
	 *
	 * @var string
	 */
	public $slug = 'apple';

	/**
	 * Integration Description
	 *
	 * @var string
	 */
	public $description = 'Sync your events across Apple devices with iCloud. Never miss an event, whether on Mac, iPhone, or iPad.';

	/**
	 * Client
	 *
	 * @var Client
	 */
	public $client;

	/**
	 * Auth type
	 *
	 * @var string
	 */
	public $auth_type = 'basic';

	/**
	 * Classes
	 *
	 * @var array
	 */
	protected static $classes = array(
		'rest_api'    => REST_API::class,
		'remote_data' => RemoteData::class,
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		 parent::__construct();
		add_filter( 'doublescale_booking_get_available_slots', array( $this, 'get_available_slots' ), 10, 5 );
		add_action( 'doublescale_booking_initialize_default_settings', array( $this, 'initialize_default_settings' ) );
		add_action( 'doublescale_booking_created', array( $this, 'add_event_to_calendars' ) );
		add_action( 'doublescale_booking_cancelled', array( $this, 'remove_event_from_calendars' ) );
		add_action( 'doublescale_booking_rescheduled', array( $this, 'reschedule_event' ) );
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
		if ( ! $this->is_global_integration_enabled() ) {
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

		$apple_events = $booking->get_meta( 'apple_events_details', array() );
		if ( empty( $apple_events ) ) {
			return $booking;
		}

		foreach ( $apple_events as $event_uid => $apple_event ) {
			$event = Arr::get( $apple_event, 'event', array() );
			if ( empty( $event ) ) {
				continue;
			}

			$calendar_id = Arr::get( $apple_event, 'calendar_id', '' );
			$account_id  = Arr::get( $apple_event, 'account_id', '' );

			$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			$end_date   = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

			$event_data            = $event;
			$event_data['DTSTART'] = $start_date;
			$event_data['DTEND']   = $end_date;

			$api = $this->connect( $integration_calendar->id, $account_id );
			if ( ! $api || \is_wp_error( $api ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Apple Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Apple Account %2$s.', 'doublescale' ),
							$integration_calendar->name,
							$account_id
						),
					)
				);
				continue;
			}

			$response = $api->update_event( $account_id, $calendar_id, $event_data );
			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error rescheduling event in Apple Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error rescheduling event in Apple Calendar %1$s: %2$s', 'doublescale' ),
							$calendar_id,
							Arr::get( $response, 'data.error.message' )
						),
					)
				);
				continue;
			}

			$event              = Arr::get( $response, 'data' );
			$meta               = $booking->get_meta( 'apple_events_details', array() );
			$meta[ $event_uid ] = array(
				'event'       => $event,
				'calendar_id' => $calendar_id,
				'account_id'  => $account_id,
			);

			$booking->update_meta(
				'apple_events_details',
				$meta
			);

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event rescheduled in Apple Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s rescheduled in Apple Calendar %2$s.', 'doublescale' ),
						$event_uid,
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

		if ( ! $this->is_global_integration_enabled() ) {
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

		$apple_events = $booking->get_meta( 'apple_events_details', array() );
		if ( empty( $apple_events ) ) {
			return $booking;
		}

		foreach ( $apple_events as $event_uid => $apple_event ) {
			$event = Arr::get( $apple_event, 'event', array() );
			if ( empty( $event ) ) {
				continue;
			}

			$calendar_id = Arr::get( $apple_event, 'calendar_id', '' );
			$account_id  = Arr::get( $apple_event, 'account_id', '' );

			$api = $this->connect( $integration_calendar->id, $account_id );
			if ( ! $api ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Apple Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Apple Account %2$s.', 'doublescale' ),
							$integration_calendar->name,
							$account_id
						),
					)
				);
				continue;
			}

			$response = $api->delete_event( $account_id, $calendar_id, $event['UID'] );
			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Failed to remove event from Apple Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Failed to remove event %1$s from Apple Calendar %2$s.', 'doublescale' ),
							$event['UID'],
							$calendar_id
						),
					)
				);
				continue;
			}

			$meta = $booking->get_meta( 'apple_events_details', array() );
			Arr::forget( $meta, $event['UID'] );

			$booking->update_meta(
				'apple_events_details',
				$meta
			);

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event removed from Apple Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s removed from Apple Calendar %2$s.', 'doublescale' ),
						$event_uid,
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
	 * Add event to calendars
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|BookingModel $booking_id Booking id, numeric string, or model.
	 *
	 * @return BookingModel
	 */
	public function add_event_to_calendars( $booking_id ) {
		$booking = $this->resolve_booking( $booking_id );
		if ( ! $booking ) {
			return $booking;
		}
		try {

			if ( ! $this->is_global_integration_enabled() ) {
				return $booking;
			}

			if ( ! $booking->hosts ) {
				return $booking;
			}

			$bookable_entity  = $booking->getBookableEntity();
			$booking_calendar = $booking->calendar;

			if ( ! $bookable_entity || ! $booking_calendar ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Booking does not have a valid event/service or calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Booking %1$s does not have a valid event/service or calendar.', 'doublescale' ),
							$booking->hash_id
						),
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
				array( $mainHost ),
				array( $mainGuest ),
				$additionalHosts,
				$additionalGuests
			);

			$integration_calendar = $this->get_integration_host_calendar_for_booking( $booking );
			if ( ! $integration_calendar ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Apple Calendar integration could not resolve the host calendar for this booking.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$this->set_host( $integration_calendar );
			$apple_integration = $this->host->get_meta( $this->meta_key, array() );

			if ( empty( $apple_integration ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Apple Calendar integration not configured.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$default_account = $this->pick_host_account_for_default_calendar_sync( $apple_integration );

			if ( ! $default_account ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'No default calendar found in any Apple account.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$api = $this->connect( $integration_calendar->id, $default_account['id'] );
			if ( ! $api ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Apple Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Apple Account %2$s.', 'doublescale' ),
							$this->host->name,
							$default_account['id']
						),
					)
				);
				return $booking;
			}

			$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			$end_date   = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

			$account = $this->accounts->get_account( $default_account['id'] );
			$email   = Arr::get( $account, 'credentials.apple_id', '' );

			$apple_attendees = array();
			foreach ( $attendees as $attendee ) {
				$apple_attendees[] = array(
					'CN'   => $attendee['display_name'],
					'MAIL' => $attendee['email'],
				);
			}

			$event_data        = array(
				'DESCRIPTION'    => $this->get_event_description( $booking, $organizer ),
				'DTSTART'        => $start_date,
				'DTEND'          => $end_date,
				'LOCATION'       => $booking->location['label'],
				'SUMMARY'        => sprintf( __( '%1$s: %2$s', 'doublescale' ), $booking->getContactDisplayName(), $entity_name ),
				'ORGANIZER'      => "mailto:{$email}",
				'ORGANIZER_NAME' => $organizer->display_name,
				'ATTENDEES'      => $apple_attendees,
			);
			$calendar_id       = Arr::get( $default_account['default_calendar'], 'calendar_id', '' );
			$event_data['UID'] = md5( $booking->hash_id . '-' . $calendar_id ) . '-' . wp_generate_uuid4();

			$response = $api->create_event( $default_account['id'], $calendar_id, $event_data );

			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error adding event to Apple Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error adding event to Apple Calendar %1$s: %2$s', 'doublescale' ),
							$calendar_id,
							Arr::get( $response, 'data.error.message' )
						),
					)
				);
				return $booking;
			}

			$event        = Arr::get( $response, 'data' );
			$uid          = Arr::get( $event, 'UID' );
			$meta         = $booking->get_meta( 'apple_events_details', array() );
			$meta[ $uid ] = array(
				'event'       => $event,
				'calendar_id' => $calendar_id,
				'account_id'  => $default_account['id'],
			);

			$booking->update_meta( 'apple_events_details', $meta );

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event added to Apple Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s added to Apple Calendar %2$s.', 'doublescale' ),
						$uid,
						$calendar_id
					),
				)
			);

			// Collective fan-out: add a busy block on co-hosts' personal
			// Apple calendars (no attendees, no meeting URL).
			if ( $bookable_entity && 'collective' === $bookable_entity->type ) {
				$this->add_busy_block_to_team_members( $booking, $integration_calendar );
			}

			return $booking;
		} catch ( \Exception $e ) {
			if ( isset( $booking ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error adding event to Apple Calendar.', 'doublescale' ),
						'details' => $e->getMessage(),
					)
				);
			}
			return $booking;
		}
	}

	/**
	 * Collective fan-out for Apple Calendar: mirror the owner's VEVENT onto
	 * each co-host's personal Apple calendar with the same SUMMARY,
	 * DESCRIPTION, and LOCATION (which carries the Meet/Teams join URL
	 * persisted by the owner flow). We intentionally omit ATTENDEES so the
	 * co-host's CalDAV server doesn't re-send invites.
	 *
	 * @param BookingModel  $booking
	 * @param CalendarModel $owner_calendar
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
			$apple_meta = $this->host->get_meta( $this->meta_key, array() );
			if ( empty( $apple_meta ) ) {
				continue;
			}
			$account = $this->pick_host_account_for_default_calendar_sync( $apple_meta );
			if ( ! $account ) {
				continue;
			}

			$api = $this->connect( $host_calendar->id, $account['id'] );
			if ( ! $api ) {
				continue;
			}

			$calendar_id_co = Arr::get( $account['default_calendar'], 'calendar_id', '' );
			$event_data     = array(
				'DTSTART'     => $start_date,
				'DTEND'       => $end_date,
				'SUMMARY'     => $shared_summary,
				'DESCRIPTION' => $shared_description,
				'LOCATION'    => $meet_link ?: $location_label,
				'TRANSP'      => 'OPAQUE',
				'UID'         => md5( $booking->hash_id . '-cohost-' . $host_user_id . '-' . $calendar_id_co ) . '-' . wp_generate_uuid4(),
			);

			$response = $api->create_event( $account['id'], $calendar_id_co, $event_data );
			if ( empty( $response['success'] ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'warning',
						'message' => __( 'Could not add busy block for co-host on Apple Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Co-host user %1$d busy block failed: %2$s', 'doublescale' ),
							$host_user_id,
							(string) Arr::get( $response, 'data.error.message', '' )
						),
					)
				);
				continue;
			}

			$uid_co                = Arr::get( $response, 'data.UID' );
			$busy_meta             = $booking->get_meta( 'apple_cohost_busy_events', array() );
			$busy_meta[ $uid_co ]  = array(
				'user_id'     => $host_user_id,
				'calendar_id' => $calendar_id_co,
				'account_id'  => $account['id'],
				'event'       => Arr::get( $response, 'data', array() ),
			);
			$booking->update_meta( 'apple_cohost_busy_events', $busy_meta );

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Busy block added to co-host Apple calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Co-host user %1$d: event %2$s on calendar %3$s', 'doublescale' ),
						$host_user_id,
						$uid_co,
						$calendar_id_co
					),
				)
			);
		}
	}

	/**
	 * Reschedule or delete the busy blocks previously written to co-hosts'
	 * personal Apple calendars by {@see add_busy_block_to_team_members()}.
	 *
	 * Apple's update_event needs the full VEVENT body (not a delta), so the
	 * cohost meta now stores the original 'event' alongside its pointers.
	 *
	 * @param BookingModel $booking
	 * @param string       $mode    'reschedule' or 'remove'.
	 */
	protected function sync_cohost_busy_events( $booking, string $mode ): void {
		$busy_meta = $booking->get_meta( 'apple_cohost_busy_events', array() );
		if ( empty( $busy_meta ) ) {
			return;
		}

		$updated_meta = $busy_meta;
		$start_date   = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
		$end_date     = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

		foreach ( $busy_meta as $remote_uid => $entry ) {
			$user_id     = (int) Arr::get( $entry, 'user_id' );
			$calendar_id = (string) Arr::get( $entry, 'calendar_id' );
			$account_id  = (string) Arr::get( $entry, 'account_id' );
			$event       = Arr::get( $entry, 'event', array() );
			if ( ! $user_id || ! $calendar_id || ! $account_id || empty( $event ) ) {
				unset( $updated_meta[ $remote_uid ] );
				continue;
			}

			$host_calendar = \DoubleScale\Modules\Booking\Models\CalendarModel::where( 'user_id', $user_id )
				->where( 'type', 'host' )
				->first();
			if ( ! $host_calendar ) {
				unset( $updated_meta[ $remote_uid ] );
				continue;
			}

			$this->set_host( $host_calendar );
			$api = $this->connect( $host_calendar->id, $account_id );
			if ( ! $api || \is_wp_error( $api ) ) {
				continue;
			}

			if ( 'reschedule' === $mode ) {
				$event_data            = $event;
				$event_data['DTSTART'] = $start_date;
				$event_data['DTEND']   = $end_date;

				$response = $api->update_event( $account_id, $calendar_id, $event_data );
				if ( empty( $response['success'] ) ) {
					$booking->logs()->create(
						array(
							'type'    => 'warning',
							'message' => __( 'Could not reschedule co-host busy block on Apple Calendar.', 'doublescale' ),
							'details' => sprintf(
								__( 'Co-host user %1$d, event %2$s: %3$s', 'doublescale' ),
								$user_id,
								$remote_uid,
								(string) Arr::get( $response, 'data.error.message', '' )
							),
						)
					);
				} else {
					$updated_event                    = Arr::get( $response, 'data', $event_data );
					$updated_meta[ $remote_uid ]['event'] = $updated_event;
					$booking->logs()->create(
						array(
							'type'    => 'info',
							'message' => __( 'Co-host busy block rescheduled on Apple Calendar.', 'doublescale' ),
							'details' => sprintf( __( 'Co-host user %1$d, event %2$s', 'doublescale' ), $user_id, $remote_uid ),
						)
					);
				}
			} elseif ( 'remove' === $mode ) {
				$response = $api->delete_event( $account_id, $calendar_id, $remote_uid );
				if ( empty( $response['success'] ) ) {
					$booking->logs()->create(
						array(
							'type'    => 'warning',
							'message' => __( 'Could not remove co-host busy block on Apple Calendar.', 'doublescale' ),
							'details' => sprintf(
								__( 'Co-host user %1$d, event %2$s: %3$s', 'doublescale' ),
								$user_id,
								$remote_uid,
								(string) Arr::get( $response, 'data.error.message', '' )
							),
						)
					);
				} else {
					unset( $updated_meta[ $remote_uid ] );
					$booking->logs()->create(
						array(
							'type'    => 'info',
							'message' => __( 'Co-host busy block removed from Apple Calendar.', 'doublescale' ),
							'details' => sprintf( __( 'Co-host user %1$d, event %2$s', 'doublescale' ), $user_id, $remote_uid ),
						)
					);
				}
			}
		}

		if ( empty( $updated_meta ) && 'remove' === $mode ) {
			$booking->delete_meta( 'apple_cohost_busy_events' );
		} else {
			$booking->update_meta( 'apple_cohost_busy_events', $updated_meta );
		}
	}

	/**
	 * Get event description
	 *
	 * @since 1.0.0
	 *
	 * @param BookingModel $booking Booking model.
	 * @param object       $organizer Organizer user object.
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
		if ( ! $this->is_global_integration_enabled() ) {
			return $slots;
		}

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
			$apple_integration = $this->host->get_meta( $this->meta_key, array() );

			// Early return if no integration data
			if ( empty( $apple_integration ) ) {
				return $slots;
			}

			foreach ( $apple_integration as $account_id => $data ) {
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
						$start          = Arr::get( $event, 'DTSTART' );
						$end            = Arr::get( $event, 'DTEND' );
						$event_timezone = Arr::get( $event, 'TZID', 'UTC' );

						if ( ! $start || ! $end ) {
							continue;
						}

						$slots = $this->remove_booked_slot( $slots, $start, $end, $timezone, $event_timezone, $user_id, $event_type );
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
		$apple_integration = $this->host->get_meta( $this->meta_key, array() );
		if ( empty( $apple_integration ) ) {
			return array();
		}

		$account_data = Arr::get( $apple_integration, $account_id, array() );
		$calendars    = Arr::get( $account_data, 'config.calendars', array() );
		if ( empty( $calendars ) ) {
			return array();
		}

		$api = $this->connect( $host_id, $account_id );
		if ( ! $api ) {
			return array();
		}

		$start_date = BookingUtils::create_date_time( $start_date, $timezone );
		$end_date   = BookingUtils::create_date_time( $end_date, $timezone );

		$start_date = $start_date->format( 'Ymd\THis\Z' );
		$end_date   = $end_date->format( 'Ymd\THis\Z' );

		/** @var Client $client */
		$client = $this->client;

		$calendars_data = array();
		foreach ( $calendars as $calendar_id ) {
			$events = $client->get_events( $account_id, $calendar_id, $start_date, $end_date );

			$calendars_data[ $calendar_id ] = $events;
		}

		return $calendars_data;
	}

	/**
	 * Remove booked slots from the given array of slots based on a Apple Calendar event's time range.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $slots Multi-day slots.
	 * @param string $event_start Start date-time of the event in ISO 8601 format (UTC timezone).
	 * @param string $event_end End date-time of the event in ISO 8601 format (UTC timezone).
	 * @param string $timezone Timezone.
	 * @param string $event_timezone Event timezone.
	 * @param int    $user_id User ID to remove from hosts_ids when slot conflicts.
	 *
	 * @return array Updated slots array.
	 */
	public function remove_booked_slot( $slots, $event_start, $event_end, $timezone, $event_timezone, $user_id = null, $event_type = null ) {
		$event_start = BookingUtils::create_date_time( $event_start, $event_timezone );
		$event_end   = BookingUtils::create_date_time( $event_end, $event_timezone );

		$event_start_timestamp = $event_start->getTimestamp();
		$event_end_timestamp   = $event_end->getTimestamp();

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
					if ( isset( $slot['hosts_ids'] ) && is_array( $slot['hosts_ids'] ) && $user_id ) {
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
		$account      = $this->accounts->get_account( $account_id );
		$apple_id     = Arr::get( $account, 'credentials.apple_id', '' );
		$app_password = Arr::get( $account, 'credentials.app_password', '' );

		if ( empty( $apple_id ) || empty( $app_password ) ) {
			return new \WP_Error( 'missing_credentials', __( 'Apple ID and App-specific password are required.', 'doublescale' ) );
		}

		$this->client = new Client( $apple_id, $app_password );

		return $this->client;
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'apple_id'     => array(
				'label'       => __( 'Apple ID', 'doublescale' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => __( 'Enter your Apple ID', 'doublescale' ),
				'description' => __( 'Your Apple ID is the email address you use to sign in to iCloud.', 'doublescale' ),
			),
			'app_password' => array(
				'label'       => __( 'App-specific Password', 'doublescale' ),
				'type'        => 'password',
				'required'    => true,
				'placeholder' => __( 'Enter your App-specific Password', 'doublescale' ),
				'description' => __( 'An app-specific password is a single-use password for your Apple ID that lets you sign in to your account securely when you use third-party apps.', 'doublescale' ),
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
		$changed  = false;
		if ( empty( $settings['app']['cache_time'] ) ) {
			$settings['app']['cache_time'] = self::DEFAULT_CACHE_TIME;
			$changed                       = true;
		}
		if ( ! isset( $settings['app']['enabled'] ) ) {
			$settings['app']['enabled'] = true;
			$changed                    = true;
		}
		if ( $changed ) {
			parent::update_settings( $settings );
		}
	}

	/**
	 * Persist settings merged into existing options so partial REST updates do not wipe keys.
	 *
	 * @param array $settings Settings partial or full.
	 * @return void
	 */
	public function update_settings( $settings ) {
		$existing = $this->get_settings();
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		parent::update_settings( array_replace_recursive( $existing, $settings ) );
	}

	/**
	 * Whether Apple Calendar sync is enabled globally (admin toggle).
	 *
	 * Defaults to true when unset so existing installs keep prior behaviour.
	 *
	 * @return bool
	 */
	private function is_global_integration_enabled(): bool {
		return (bool) $this->get_setting( 'app.enabled', true );
	}
}
