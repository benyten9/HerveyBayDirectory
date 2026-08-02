<?php

/**
 * Zoom Calendar / Meet Integration
 *
 * This class is responsible for handling the Zoom Calendar / Meet Integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Zoom;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\EventLocations\Zoom;
use DoubleScale\Modules\Booking\Abstracts\Integration as Abstract_Integration;
use DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Rest\REST_API;
use DoubleScale\Core\Utils\Utils as CoreUtils;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Pro\Modules\Booking\Integrations\Google\Integration as Google_Integration;
/**
 * Zoom Integration class
 */
class Integration extends Abstract_Integration {

	/**
	 * Integration Name
	 *
	 * @var string
	 */
	public $name = 'Zoom Integration';

	/**
	 * Integration Slug
	 *
	 * @var string
	 */
	public $slug = 'zoom';

	/**
	 * Integration Description
	 *
	 * @var string
	 */
	public $description = 'Host meetings and webinars with Zoom. Easily sync your Zoom events directly from the platform.';

	/**
	 * App
	 *
	 * @var App
	 */
	public $app;

	/**
	 * Zoom REST client.
	 *
	 * @var Api
	 */
	public $api;

	/**
	 * Is calendar integration
	 *
	 * @var bool
	 */
	public $is_calendar = false;

	/**
	 * Has acconuts
	 *
	 * @var bool
	 */
	public $has_accounts = false;

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
		'remote_data' => RemoteData::class,
		'rest_api'    => REST_API::class,
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'doublescale_booking_initialize_default_settings', array( $this, 'initialize_default_settings' ) );
		add_action( 'doublescale_booking_created', array( $this, 'add_event_to_calendars' ) );
		add_action( 'doublescale_booking_cancelled', array( $this, 'remove_event_from_calendars' ) );
		add_action( 'doublescale_booking_rescheduled', array( $this, 'reschedule_event' ) );
	}

	/**
	 * Whether the booking's location is Zoom (null-safe for in-person / unset locations).
	 *
	 * @param \DoubleScale\Modules\Booking\Models\BookingModel $booking Booking model.
	 */
	private function booking_has_zoom_location( $booking ): bool {
		$location = $booking->location;
		return is_array( $location ) && ( $location['type'] ?? '' ) === Zoom::instance()->slug;
	}

	/**
	 * Reschedule event
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
		if ( ! $this->booking_has_zoom_location( $booking ) ) {
			return $booking;
		}

		// make transaction by wpdb
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$booking_calendar = $booking->calendar;
			if ( ! $booking_calendar ) {
				$wpdb->query( 'ROLLBACK' );
				return $booking;
			}
			$host = CalendarModel::where( 'user_id', $booking_calendar->user_id )->where( 'type', 'host' )->first();
			$this->set_host( $host );

			$zoom_meetings = $booking->get_meta( 'zoom_event_details', array() );
			if ( empty( $zoom_meetings ) ) {
				$wpdb->query( 'COMMIT' );
				return $booking;
			}

			$meeting_id = Arr::get( $zoom_meetings, 'meeting.id' );
			$account_id = Arr::get( $zoom_meetings, 'account_id' );

			// Try to connect using the stored account_id
			$api = $this->connect( $host->id, $account_id );
			if ( ! $api ) {
				// If that fails, try global settings
				$api = $this->connect( $host->id, 'global' );
				if ( ! $api ) {
					$booking->logs()->create(
						array(
							'type'    => 'error',
							'message' => __( 'Error connecting to Zoom.', 'doublescale' ),
							'details' => __( 'Error connecting to Zoom with both account and global settings.', 'doublescale' ),
						)
					);
					$wpdb->query( 'ROLLBACK' );
					return $booking;
				}
			}

			$start_time = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			$data       = array(
				'start_time' => $start_time->format( 'Y-m-d\TH:i:s\Z' ),
				'duration'   => $booking->slot_time,
			);

			$response = $api->update_meeting( $meeting_id, $data );
			if ( ! is_array( $response ) || empty( $response['success'] ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error rescheduling meeting in Zoom.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error rescheduling event in Zoom Account %1$s: %2$s', 'doublescale' ),
							$account_id,
							Arr::get( $response, 'data.error.message' )
						),
					)
				);
				$wpdb->query( 'ROLLBACK' );
				return $booking;
			}

			$meeting = Arr::get( $response, 'data' );
			$booking->update_meta(
				'zoom_event_details',
				array(
					'meeting'    => $meeting,
					'account_id' => $account_id,
				)
			);

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Meeting rescheduled in Zoom Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event has been rescheduled in Zoom Account %1$s.', 'doublescale' ),
						$account_id
					),
				)
			);

			// reschedule event in google calendar
			$google = new Google_Integration();
			$google->reschedule_event( $booking );

			$wpdb->query( 'COMMIT' );
			return $booking;
		} catch ( \Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			error_log( 'Zoom Integration Debug - Exception: ' . $e->getMessage() );
			error_log( 'Zoom Integration Debug - Stack trace: ' . $e->getTraceAsString() );
			return $booking;
		}
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
		if ( ! $this->booking_has_zoom_location( $booking ) ) {
			return $booking;
		}

		// make transaction by wpdb
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$booking_calendar = $booking->calendar;
			if ( ! $booking_calendar ) {
				$wpdb->query( 'ROLLBACK' );
				return $booking;
			}
			$host = CalendarModel::where( 'user_id', $booking_calendar->user_id )->where( 'type', 'host' )->first();
			$this->set_host( $host );

			$zoom_meetings = $booking->get_meta( 'zoom_event_details', array() );
			if ( empty( $zoom_meetings ) ) {
				$wpdb->query( 'COMMIT' );
				return $booking;
			}

			$meeting_id = Arr::get( $zoom_meetings, 'meeting.id' );
			$account_id = Arr::get( $zoom_meetings, 'account_id' );

			// Try to connect using the stored account_id
			$api = $this->connect( $host->id, $account_id );
			if ( ! $api ) {
				// If that fails, try global settings
				$api = $this->connect( $host->id, 'global' );
				if ( ! $api ) {
					$booking->logs()->create(
						array(
							'type'    => 'error',
							'message' => __( 'Error connecting to Zoom.', 'doublescale' ),
							'details' => __( 'Error connecting to Zoom with both account and global settings.', 'doublescale' ),
						)
					);
					$wpdb->query( 'ROLLBACK' );
					return $booking;
				}
			}

			$response = $api->delete_meeting( $meeting_id );
			if ( ! is_array( $response ) || empty( $response['success'] ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'Error removing meeting from Zoom.', 'doublescale' ),
						'details' => sprintf(
							__( 'Error removing event from Zoom Account %1$s: %2$s', 'doublescale' ),
							$account_id,
							Arr::get( $response, 'data.error.message' )
						),
					)
				);
				$wpdb->query( 'ROLLBACK' );
				return $booking;
			}

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Meeting removed from Zoom Calendar.', 'doublescale' ),
					'details' => sprintf(
						__( 'Event has been removed from Zoom Account %1$s.', 'doublescale' ),
						$account_id
					),
				)
			);

			// remove event from google calendar
			$google = new Google_Integration();
			$google->remove_event_from_calendars( $booking );

			$wpdb->query( 'COMMIT' );
			return $booking;
		} catch ( \Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			error_log( 'Zoom Integration Debug - Exception: ' . $e->getMessage() );
			error_log( 'Zoom Integration Debug - Stack trace: ' . $e->getTraceAsString() );
			return $booking;
		}
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
		if ( ! $this->booking_has_zoom_location( $booking ) ) {
			return $booking;
		}
		$existing_zoom = $booking->get_meta( 'zoom_event_details', array() );
		if ( ! empty( Arr::get( $existing_zoom, 'meeting.id' ) ) ) {
			return $booking;
		}
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$booking_calendar = $booking->calendar;
			if ( ! $booking_calendar ) {
				$wpdb->query( 'ROLLBACK' );
				return $booking;
			}
			$host = CalendarModel::where( 'user_id', $booking_calendar->user_id )->where( 'type', 'host' )->first();
			$this->set_host( $host );

			// First try to get host-specific settings
			$zoom_integration = $this->host->get_meta( $this->meta_key, array() );

			if ( empty( $zoom_integration ) ) {
				$booking->logs()->create(
					array(
						'type'    => 'error',
						'message' => __( 'No Zoom settings found for host or globally', 'doublescale' ),
					)
				);
				$wpdb->query( 'ROLLBACK' );
				return $booking;
			}

			$start_date = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
			$meeting    = null;
			foreach ( $zoom_integration as $account_id => $data ) {
				$api = $this->connect( $host->id, $account_id );
				if ( ! $api || is_wp_error( $api ) ) {
					$booking->logs()->create(
						array(
							'type'    => 'error',
							'message' => __( 'Error connecting to Zoom.', 'doublescale' ),
							'details' => sprintf(
								__( 'Error connecting host %1$s with Zoom Account %2$s.', 'doublescale' ),
								$host->name,
								$account_id
							),
						)
					);
					continue;
				}

				$account = $this->accounts->get_account( $account_id );

				$bookable_entity = $booking->getBookableEntity();
				$entity_name     = $bookable_entity ? $bookable_entity->name : __( 'Booking', 'doublescale' );

				$meeting_data = array(
					'agenda'       => $entity_name,
					'start_time'   => $start_date->format( 'Y-m-d\TH:i:s\Z' ),
					'duration'     => $booking->slot_time,
					'type'         => '2',
					'schedule_for' => Arr::get( $account, 'name' ),
					'settings'     => array(
						'meeting_invitees' => array(
							array(
								'email' => $booking->contact->email ?? '',
							),
						),
					),
					'topic'        => $entity_name,
				);

				// Remove any empty values recursively.
				$meeting_data = array_filter( $meeting_data );

				$response = $api->create_meeting( $meeting_data );
				if ( ! $response['success'] ) {
					$error_message = Arr::get( $response, 'data.error.message', 'Unknown error' );
					$booking->logs()->create(
						array(
							'type'    => 'error',
							'message' => __( 'Error creating meeting in Zoom.', 'doublescale' ),
							'details' => sprintf(
								__( 'Error adding event to Zoom Account %1$s: %2$s', 'doublescale' ),
								$account_id,
								$error_message
							),
						)
					);
					continue;
				}

				$meeting = Arr::get( $response, 'data' );
				if ( empty( $meeting['join_url'] ) ) {
					$booking->logs()->create(
						array(
							'type'    => 'error',
							'message' => __( 'Error creating meeting in Zoom.', 'doublescale' ),
							'details' => sprintf(
								/* translators: %s: Zoom account id */
								__( 'Zoom returned no join URL for account %s.', 'doublescale' ),
								(string) $account_id
							),
						)
					);
					continue;
				}

				$booking->update_meta(
					'zoom_event_details',
					array(
						'meeting'    => $meeting,
						'account_id' => $account_id,
					)
				);

				$booking->update_meta(
					'location',
					array(
						'type'  => Zoom::instance()->slug,
						'label' => 'Zoom',
						'value' => $meeting['join_url'],
					)
				);

				$booking->logs()->create(
					array(
						'type'    => 'info',
						'message' => __( 'Meeting created in Zoom Calendar.', 'doublescale' ),
						'details' => sprintf(
							__( 'Event has been added to Zoom Account %1$s: %2$s', 'doublescale' ),
							$account_id,
							$meeting['join_url']
						),
					)
				);

				// create event in google calendar
				$google = new Google_Integration();
				$google->add_event_to_calendars( $booking, false, $meeting['join_url'], true );
				break;
			}

			$wpdb->query( 'COMMIT' );
			return $booking;
		} catch ( \Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			error_log( 'Zoom Integration Debug - Exception: ' . $e->getMessage() );
			error_log( 'Zoom Integration Debug - Stack trace: ' . $e->getTraceAsString() );
			return $booking;
		}
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
	public function get_event_description( $booking ) {
		$entity      = $booking->getBookableEntity();
		$entity_name = $entity ? $entity->name : __( 'Booking', 'doublescale' );

		$description  = sprintf(
			__( 'Event Detials:', 'doublescale' ),
			$entity_name
		);
		$description .= PHP_EOL;
		$description .= sprintf(
			__( 'Invitee: %s', 'doublescale' ),
			$booking->getContactDisplayName()
		);
		$description .= PHP_EOL;
		$description .= sprintf(
			__( 'Invitee Email: %s', 'doublescale' ),
			$booking->contact->email ?? ''
		);
		$description .= PHP_EOL . PHP_EOL;
		$start_date   = new \DateTime( $booking->start_time, new \DateTimeZone( $booking->calendar->timezone ) );
		$end_date     = new \DateTime( $booking->end_time, new \DateTimeZone( $booking->calendar->timezone ) );
		$description .= sprintf(
			__( 'When:%4$s%1$s to %2$s (%3$s)', 'doublescale' ),
			$start_date->format( 'Y-m-d H:i' ),
			$end_date->format( 'Y-m-d H:i' ),
			$booking->calendar->timezone,
			PHP_EOL
		);

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
		$site_uid = get_option( 'doublescale_booking_site_uid', '' );
		if ( empty( $site_uid ) ) {
			$site_uid = CoreUtils::generate_hash_key();
			update_option( 'doublescale_booking_site_uid', $site_uid );
		}

		return $site_uid;
	}

	/**
	 * Connect the integration
	 *
	 * @since 1.0.0
	 *
	 * @param int $host_id Host ID.
	 * @param int $account_id Account ID.
	 *
	 * @return bool|Api
	 */
	public function connect( $host_id, $account_id ) {
		parent::connect( $host_id, $account_id );

		// First try to get account from host
		$account = $this->accounts->get_account( $account_id );

		if ( empty( $account ) ) {
			return false;
		}

		$access_token  = Arr::get( $account, 'tokens.access_token', '' );
		$refresh_token = Arr::get( $account, 'tokens.refresh_token', '' );

		// If we have an access token but no refresh token, we can still proceed
		if ( ! empty( $access_token ) ) {
			try {
				$this->api = new Api( $access_token, $this, $refresh_token, $account_id );
				return $this->api;
			} catch ( \Exception $e ) {
				error_log( 'Zoom Integration Debug - API initialization failed: ' . $e->getMessage() );
			}
		}
		$this->api = new \WP_Error( 'zoom_integration_error', __( 'Zoom Integration Error: Unable to initialize API.', 'doublescale' ) );
		return false;
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
			'account_id'    => array(
				'type'        => 'text',
				'label'       => __( 'Account ID', 'doublescale' ),
				'required'    => true,
				'placeholder' => __( 'Enter your Zoom Account ID', 'doublescale' ),
				'description' => __( 'You can find your Account ID in your Zoom app settings.', 'doublescale' ),
			),
			'client_id'     => array(
				'type'        => 'text',
				'label'       => __( 'Client ID', 'doublescale' ),
				'required'    => true,
				'placeholder' => __( 'Enter your Zoom Client ID', 'doublescale' ),
				'description' => __( 'You can find your Client ID in your Zoom app settings.', 'doublescale' ),
			),
			'client_secret' => array(
				'type'        => 'text',
				'label'       => __( 'Secret Key', 'doublescale' ),
				'required'    => true,
				'placeholder' => __( 'Enter your Zoom Secret Key', 'doublescale' ),
				'description' => __( 'You can find your Secret Key in your Zoom app settings.', 'doublescale' ),
			),
		);
	}

	/**
	 * Initialize default settings when {@see doublescale_booking_initialize_default_settings} runs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function initialize_default_settings(): void {
	}

	/**
	 * Delete settings
	 *
	 * @since 1.0.0
	 *
	 * @param string|int $account_id Account ID. If empty, deletes global settings.
	 * @return void
	 */
	public function delete_settings( $account_id = '' ) {
		if ( empty( $account_id ) ) {
			// Delete global settings
			delete_option( $this->option_name );
			return;
		}

		// Delete account-specific settings
		$this->accounts->delete_account( $account_id );
	}
}
