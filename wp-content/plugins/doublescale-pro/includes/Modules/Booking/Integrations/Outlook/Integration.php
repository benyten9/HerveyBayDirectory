<?php

/**
 * Outlook Calendar / Meet Integration
 *
 * This class is responsible for handling the Outlook Calendar / Meet Integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Outlook;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\EventLocations\MsTeams;
use DoubleScale\Modules\Booking\Abstracts\Integration as Abstract_Integration;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Pro\Modules\Booking\Integrations\Outlook\Rest\REST_API;
use DoubleScale\Modules\Booking\BookingUtils;
use WP_Error;

/**
 * Outlook Integration class
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
	public $name = 'Outlook Calendar/MS Teams Conferencing';

	/**
	 * Integration Slug
	 *
	 * @var string
	 */
	public $slug = 'outlook';

	/**
	 * Integration Description
	 *
	 * @var string
	 */
	public $description = 'Manage appointments and sync across devices with Microsoft Calendar. Stay in control, wherever you are.';

	/**
	 * App
	 *
	 * @var App
	 */
	public $app;

	/**
	 * API
	 *
	 * @var API
	 */
	public $api;

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
	 * Reschedule event
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|BookingModel $booking Booking id, numeric string, or model.
	 *
	 * @return BookingModel
	 */
	public function reschedule_event( $booking ) {
		$booking = $this->resolve_booking( $booking );
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

		$outlook_events = $booking->get_meta( 'outlook_events_details', array() );
		if ( empty( $outlook_events ) ) {
			return $booking;
		}

		foreach ( $outlook_events as $event_id => $outlook_event ) {
			$account_id  = Arr::get( $outlook_event, 'account_id' );
			$calendar_id = Arr::get( $outlook_event, 'calendar_id' );

			$api = $this->connect( $integration_calendar->id, $account_id );
			if ( ! $api || is_wp_error( $api ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Outlook Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Outlook Account %2$s.', 'doublescale' ),
							$integration_calendar->name,
							$account_id
						),
					)
				);
				continue;
			}

			$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			$end_date   = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

			$data = array(
				'start' => array(
					'dateTime' => $start_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'end'   => array(
					'dateTime' => $end_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
			);

			$response = $api->update_event( $calendar_id, $event_id, $data );
			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error rescheduling event in Outlook Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error rescheduling event in Outlook Calendar %1$s: %2$s', 'doublescale' ),
							$calendar_id,
							Arr::get( $response, 'data.error.message', '' )
						),
					)
				);
				continue;
			}

			$meta = $booking->get_meta( 'outlook_events_details', array() );
			Arr::set( $meta, "{$event_id}.event", $response['data'] );
			$booking->update_meta( 'outlook_events_details', $meta );

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event rescheduled in Outlook Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s rescheduled in Outlook Calendar %2$s.', 'doublescale' ),
						$event_id,
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
	 * @param int|string|BookingModel $booking Booking id, numeric string, or model.
	 *
	 * @return BookingModel
	 */
	public function remove_event_from_calendars( $booking ) {
		$booking = $this->resolve_booking( $booking );
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

		$outlook_events = $booking->get_meta( 'outlook_events_details', array() );
		if ( empty( $outlook_events ) ) {
			return $booking;
		}

		foreach ( $outlook_events as $event_id => $outlook_event ) {
			$account_id = Arr::get( $outlook_event, 'account_id' );

			$api = $this->connect( $integration_calendar->id, $account_id );
			if ( ! $api ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Outlook Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Outlook Account %2$s.', 'doublescale' ),
							$integration_calendar->name,
							$account_id
						),
					)
				);
				continue;
			}

			$response = $api->delete_event( $event_id );
			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error removing event from Outlook Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error removing event from Outlook Calendar %1$s: %2$s', 'doublescale' ),
							$event_id,
							Arr::get( $response, 'data.error.message', '' )
						),
					)
				);
				continue;
			}

			$meta = $booking->get_meta( 'outlook_events_details', array() );
			Arr::forget( $meta, $event_id );
			$booking->update_meta( 'outlook_events_details', $meta );

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event removed from Outlook Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s removed from Outlook Calendar.', 'doublescale' ),
						$event_id
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
	 * Add one Outlook event on the host’s selected remote default calendar (optional Teams).
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|BookingModel $booking Booking id, numeric string, or model.
	 *
	 * @return BookingModel
	 */
	public function add_event_to_calendars( $booking ) {
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) {
			return $booking;
		}
		try {

			if ( ! $booking->hosts ) {
				return $booking;
			}

			// Check if location is MS Teams
			if ( $booking->location['type'] !== MsTeams::instance()->slug ) {
				return $booking;
			}

			$bookable_entity  = $booking->getBookableEntity();
			$booking_calendar = $booking->calendar;

			if ( ! $bookable_entity || ! $booking_calendar ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Invalid event/service or calendar configuration.', 'doublescale' ),
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
						'message' => __( 'Outlook Calendar integration could not resolve the host calendar for this booking.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$this->set_host( $integration_calendar );
			$outlook_integration = $this->host->get_meta( $this->meta_key, array() );

			if ( empty( $outlook_integration ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Outlook Calendar integration not configured.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$default_account = $this->pick_host_account_for_default_calendar_sync( $outlook_integration );

			if ( ! $default_account ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'No default calendar found in any Outlook account.', 'doublescale' ),
					)
				);
				return $booking;
			}

			$api = $this->connect( $integration_calendar->id, $default_account['id'] );
			if ( ! $api || \is_wp_error( $api ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error connecting to Outlook Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error connecting host %1$s with Outlook Account %2$s.', 'doublescale' ),
							$integration_calendar->name,
							$default_account['id']
						),
					)
				);
				return $booking;
			}

			$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			$end_date   = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );

			$outlook_attendees = array();
			foreach ( $attendees as $attendee ) {
				$outlook_attendees[] = array(
					'emailAddress' => array(
						'address' => $attendee['email'],
						'name'    => $attendee['display_name'],
					),
					'type'         => 'required',
				);
			}

			$event_data  = array(
				'subject'               => sprintf( __( '%1$s: %2$s', 'doublescale' ), $booking->getContactDisplayName(), $entity_name ),
				'location'              => array(
					'displayName' => $booking->location['label'],
				),
				'organizer'             => array(
					'emailAddress' => array(
						'address' => $organizer->user_email,
						'name'    => $organizer->display_name,
					),
				),
				'attendees'             => $outlook_attendees,
				'start'                 => array(
					'dateTime' => $start_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'end'                   => array(
					'dateTime' => $end_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'allowNewTimeProposals' => false,
				'body'                  => array(
					'contentType' => 'text',
					'content'     => $this->get_event_description( $booking, $organizer ),
				),
				'transactionId'         => "{$this->get_site_uid()}-{$booking->id}",
			);
			$enableTeams = Arr::get( $default_account['data'], 'config.settings.enable_teams', false );

			if ( $enableTeams ) {
				$event_data['isOnlineMeeting']       = true;
				$event_data['onlineMeetingProvider'] = 'teamsForBusiness';
			}

			$event_data = array_filter( $event_data );

			$response = $api->create_event( $default_account['default_calendar']['calendar_id'], $event_data );

			if ( ! $response['success'] ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error adding event to Outlook Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error adding event to Outlook Calendar %1$s: %2$s', 'doublescale' ),
							$default_account['default_calendar']['calendar_id'],
							Arr::get( $response, 'data.error.message', '' )
						),
					)
				);
				return $booking;
			}

			$event = Arr::get( $response, 'data' );
			$id    = Arr::get( $event, 'id' );

			$meta        = $booking->get_meta( 'outlook_events_details', array() );
			$meta[ $id ] = array(
				'event'       => $event,
				'calendar_id' => $default_account['default_calendar']['calendar_id'],
				'account_id'  => $default_account['id'],
			);

			$booking->update_meta( 'outlook_events_details', $meta );

			if ( $enableTeams ) {
				$shared_teams_link = Arr::get( $event, 'onlineMeeting.joinUrl', '' );
				$booking->update_meta(
					'location',
					array(
						'type'  => MsTeams::instance()->slug,
						'label' => 'Microsoft Teams',
						'value' => $shared_teams_link,
					)
				);
			} else {
				$value = Arr::get( $event, 'webLink', '' );
				$booking->update_meta(
					'location',
					array(
						'type'  => MsTeams::instance()->slug,
						'label' => 'Microsoft Teams',
						'value' => $value,
					)
				);
			}

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Event added to Outlook Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event %1$s added to Outlook Calendar %2$s.', 'doublescale' ),
						$id,
						$default_account['default_calendar']['calendar_id']
					),
				)
			);

			// Collective fan-out: add a busy block on co-hosts' personal
			// Outlook calendars without creating duplicate Teams meetings.
			if ( $bookable_entity && 'collective' === $bookable_entity->type ) {
				$this->add_busy_block_to_team_members( $booking, $integration_calendar );
			}

			return $booking;
		} catch ( \Exception $e ) {
			if ( isset( $booking ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error adding event to Outlook Calendar.', 'doublescale' ),
						'details' => $e->getMessage(),
					)
				);
			}
			return $booking;
		}
	}

	/**
	 * Collective fan-out for Outlook: mirror the owner's meeting onto each
	 * co-host's personal Outlook calendar, reusing the SAME Teams join URL
	 * (or web link) that the owner's create call produced. We never set
	 * `isOnlineMeeting` here — that would mint a second Teams meeting.
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

		// Pull the Teams URL (or fallback webLink) that the owner's
		// add_event_to_calendars persisted into the booking 'location' meta
		// just before this fan-out runs.
		$owner_location = $booking->get_meta( 'location', array() );
		$teams_link     = is_array( $owner_location ) ? (string) Arr::get( $owner_location, 'value', '' ) : '';
		$location_label = is_array( $owner_location ) ? (string) Arr::get( $owner_location, 'label', '' ) : '';

		$shared_subject     = sprintf( __( '%1$s: %2$s', 'doublescale' ), $contact_name, $entity_name );
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
			$outlook_meta = $this->host->get_meta( $this->meta_key, array() );
			if ( empty( $outlook_meta ) ) {
				continue;
			}
			$account = $this->pick_host_account_for_default_calendar_sync( $outlook_meta );
			if ( ! $account ) {
				continue;
			}

			$api = $this->connect( $host_calendar->id, $account['id'] );
			if ( ! $api || \is_wp_error( $api ) ) {
				continue;
			}

			$busy_event = array(
				'subject'               => $shared_subject,
				'showAs'                => 'busy',
				'body'                  => array(
					'contentType' => 'text',
					'content'     => $shared_description,
				),
				'location'              => array(
					'displayName' => $teams_link ?: $location_label,
				),
				'start'                 => array(
					'dateTime' => $start_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'end'                   => array(
					'dateTime' => $end_date->format( 'Y-m-d\TH:i:s' ),
					'timeZone' => 'UTC',
				),
				'allowNewTimeProposals' => false,
				'transactionId'         => "{$this->get_site_uid()}-{$booking->id}-cohost-{$host_user_id}",
			);

			$response = $api->create_event( $account['default_calendar']['calendar_id'], $busy_event );
			if ( empty( $response['success'] ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'warning',
						'message' => __( 'Could not add busy block for co-host on Outlook Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Co-host user %1$d busy block failed: %2$s', 'doublescale' ),
							$host_user_id,
							(string) Arr::get( $response, 'data.error.message', '' )
						),
					)
				);
				continue;
			}

			$event_id              = Arr::get( $response, 'data.id' );
			$busy_meta             = $booking->get_meta( 'outlook_cohost_busy_events', array() );
			$busy_meta[ $event_id ] = array(
				'user_id'     => $host_user_id,
				'calendar_id' => $account['default_calendar']['calendar_id'],
				'account_id'  => $account['id'],
			);
			$booking->update_meta( 'outlook_cohost_busy_events', $busy_meta );

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Busy block added to co-host Outlook calendar.', 'doublescale' ),
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
	 * personal Outlook calendars by {@see add_busy_block_to_team_members()}.
	 *
	 * Mirrors the Google integration's sync_cohost_busy_events but uses the
	 * Outlook API surface (update_event takes calendar_id + event_id;
	 * delete_event takes event_id only).
	 *
	 * @param BookingModel $booking
	 * @param string       $mode    'reschedule' or 'remove'.
	 */
	protected function sync_cohost_busy_events( $booking, string $mode ): void {
		$busy_meta = $booking->get_meta( 'outlook_cohost_busy_events', array() );
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
			if ( ! $user_id || ! $account_id ) {
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
			if ( ! $api || \is_wp_error( $api ) ) {
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
							'message' => __( 'Could not reschedule co-host busy block on Outlook Calendar.', 'doublescale' ),
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
							'message' => __( 'Co-host busy block rescheduled on Outlook Calendar.', 'doublescale' ),
							'details' => sprintf( __( 'Co-host user %1$d, event %2$s', 'doublescale' ), $user_id, $remote_event_id ),
						)
					);
				}
			} elseif ( 'remove' === $mode ) {
				$response = $api->delete_event( $remote_event_id );
				if ( empty( $response['success'] ) ) {
					$booking->logs()->create(
						array(
							'type'    => 'warning',
							'message' => __( 'Could not remove co-host busy block on Outlook Calendar.', 'doublescale' ),
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
							'message' => __( 'Co-host busy block removed from Outlook Calendar.', 'doublescale' ),
							'details' => sprintf( __( 'Co-host user %1$d, event %2$s', 'doublescale' ), $user_id, $remote_event_id ),
						)
					);
				}
			}
		}

		if ( 'remove' === $mode ) {
			if ( empty( $updated_meta ) ) {
				$booking->delete_meta( 'outlook_cohost_busy_events' );
			} else {
				$booking->update_meta( 'outlook_cohost_busy_events', $updated_meta );
			}
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

	private function get_available_slots_for_host( $slots, $host, $start_date, $end_date, $timezone, $user_id, $event_type ) {
		try {
			$this->set_host( $host );
			$outlook_integration = $this->host->get_meta( $this->meta_key, array() );
			if ( empty( $outlook_integration ) ) {
				return $slots;
			}

			foreach ( $outlook_integration as $account_id => $data ) {
				$callback    = function () use ( $host, $account_id, $start_date, $end_date, $timezone ) {
					return $this->get_account_data( $host->id, $account_id, $start_date, $end_date, $timezone );
				};
				$settings    = $this->get_settings();
				$cache_time  = Arr::get( $settings, 'app.cache_time', null );
				$key         = "slots_{$start_date}_{$end_date}";
				$cached_data = $this->accounts->get_cache_data( $account_id, $key, $callback, $cache_time );
				if ( empty( $cached_data ) ) {
					continue;
				}

				foreach ( $cached_data as $calendar_id => $events ) {
					foreach ( $events as $event ) {
						$start = Arr::get( $event, 'start.dateTime' );
						$end   = Arr::get( $event, 'end.dateTime' );

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
		$outlook_integration = $this->host->get_meta( $this->meta_key, array() );
		if ( empty( $outlook_integration ) ) {
			return array();
		}

		$account_data = Arr::get( $outlook_integration, $account_id, array() );
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

		$args = array(
			'startdatetime' => $start_date->format( 'Y-m-d\TH:i:s\Z' ),
			'enddatetime'   => $end_date->format( 'Y-m-d\TH:i:s\Z' ),
			'$select'       => 'subject,recurrence,showAs,start,end,subject,isAllDay,transactionId',
			'$top'          => 100,
		);

		$calendars_data = array();
		foreach ( $calendars as $calendar_id ) {
			$response = $api->get_events( $calendar_id, $args );
			if ( ! $response['success'] ) {
				continue;
			}

			$calendars_data[ $calendar_id ] = Arr::get( $response, 'data.value', array() );
		}

		return $calendars_data;
	}

	/**
	 * Remove booked slots from the given array of slots based on a Outlook Calendar event's time range.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $slots Multi-day slots.
	 * @param string $event_start Start date-time of the event in ISO 8601 format (UTC timezone).
	 * @param string $event_end End date-time of the event in ISO 8601 format (UTC timezone).
	 * @param string $timezone Timezone.
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
					// Remove the user_id from hosts_ids if it exists
					if ( $event_type === 'collective' ) {
						unset( $daily_slots[ $slot_index ] );
					}
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
		$account       = $this->accounts->get_account( $account_id );
		$access_token  = Arr::get( $account, 'tokens.access_token', '' );
		$refresh_token = Arr::get( $account, 'tokens.refresh_token', '' );

		if ( empty( $access_token ) || empty( $refresh_token ) ) {
			return new \WP_Error( 'outlook_integration_error', __( 'Outlook Integration Error: Access token or refresh token is empty.', 'doublescale' ) );
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
