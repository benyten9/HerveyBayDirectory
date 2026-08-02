<?php

/**
 * Class Rest_Event_Controller
 *
 * This class is responsible for handling the event controller
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Rest\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Models\AvailabilityModel;
use WP_Error;
use Exception;
use Illuminate\Support\Arr;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Modules\Booking\Capabilities;
use DoubleScale\Modules\Booking\Services\AvailabilityService;
use DoubleScale\Modules\Booking\EventFields\EventFields;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\UserModel;
use DoubleScale\Modules\Booking\Managers\LocationsManager;
use DoubleScale\Modules\Booking\PaymentGateway\PaymentValidator;

/**
 * Event Controller class
 */
class RestEventController extends RestController {


	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'booking/events';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'keyword'  => array(
							'description' => __( 'Keyword to search.', 'doublescale' ),
							'type'        => 'string',
						),
						'per_page' => array(
							'description' => __( 'Number of items to fetch.', 'doublescale' ),
							'type'        => 'integer',
						),
						'page'     => array(
							'description' => __( 'Page number.', 'doublescale' ),
							'type'        => 'integer',
						),
						'filter'   => array(
							'description' => __( 'Filter the results.', 'doublescale' ),
							'type'        => 'object',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'id' => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/range',
			array(
				'id' => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item_range' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item_availability' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'availability' => array(
							'description'          => __( 'Availability to update.', 'doublescale' ),
							'type'                 => 'object',
							'additionalProperties' => true,
							'required'             => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/fields',
			array(
				'id' => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_fields' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_fields' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'fields' => array(
							'description'          => __( 'Fields to update.', 'doublescale' ),
							'type'                 => 'object',
							'additionalProperties' => true,
							'required'             => true,
						),
					),
				),
			)
		);

		// Duplicate event
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/duplicate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'duplicate_item' ),
					'permission_callback' => array( $this, 'duplicate_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Event ID to duplicate.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Get meta
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/meta/(?P<key>[\w-]+)',
			array(
				'id' => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_meta' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);

		// SMS organizer phone status — does the configured fallback chain resolve a
		// phone for this event's organizer? Free returns `{resolved:false, source:null}`;
		// Pro hooks the `doublescale_booking_sms_organizer_phone_status` filter to
		// return the real resolution outcome from `BookingSmsNotifier::resolve_organizer_phone()`.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/sms-organizer-phone-status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sms_organizer_phone_status' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Unique identifier for the object.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Phone-question status — does this event's form collect a phone number?
		// Used by the SMS Notification tab to decide whether to render the
		// "Your form doesn't collect a phone number" warning + the one-click
		// "Add phone question" button below.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/phone-question-status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_phone_question_status' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Unique identifier for the object.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// One-click: inject a required phone field into this event's `fields` meta.
		// Idempotent — if a phone-type field already exists, the existing fields
		// array is returned unchanged.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/add-phone-question',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_phone_question' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Unique identifier for the object.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// hande event disable status
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/disable-status',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'disable_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id'         => array(
							'description' => __( 'Unique identifier for the object.', 'doublescale' ),
							'type'        => 'integer',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'properties' => array(
							'status' => array(
								'description' => __( 'Disable status.', 'doublescale' ),
								'type'        => 'boolean',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
						),
					),
				),
			)
		);

		// Get latest events
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/latest',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_latest_events' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'limit'   => array(
							'description' => __( 'Number of events to retrieve.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 5,
						),
						'user_id' => array(
							'description' => __( 'Filter by user ID.', 'doublescale' ),
							'type'        => 'integer',
						),
						'status'  => array(
							'description' => __( 'Filter by event status.', 'doublescale' ),
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Get item schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'event',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'hash_id'       => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'calendar_id'   => array(
					'description' => __( 'Calendar ID.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'user_id'       => array(
					'description' => __( 'User ID.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'name'          => array(
					'description' => __( 'Event name.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'is_disabled'   => array(
					'description' => __( 'Is event disabled.', 'doublescale' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'description'   => array(
					'description' => __( 'Event description.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'slug'          => array(
					'description' => __( 'Event slug.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_title',
					),
				),
				'status'        => array(
					'description' => __( 'Event status.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'enum'        => array( 'active', 'inactive' ),
				),
				'type'          => array(
					'description' => __( 'Event type.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'enum'        => array( 'one-to-one', 'group', 'round-robin', 'collective' ),
				),
				'duration'      => array(
					'description' => __( 'Event duration.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'color'         => array(
					'description' => __( 'Event color.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'visibility'    => array(
					'description' => __( 'Event visibility.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'enum'        => array( 'public', 'private' ),
				),
				'reserve_times' => array(
					'description' => __( 'Reserve times.', 'doublescale' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'created_at'    => array(
					'description' => __( 'Event created at.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'updated_at'    => array(
					'description' => __( 'Event updated at.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		try {
			$per_page = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page     = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$keyword  = $request->get_param( 'keyword' );
			$filter   = $request->get_param( 'filter' ) ?? array();
			$user     = Arr::get( $filter, 'user' ) ? Arr::get( $filter, 'user' ) : 'own';

			if ( 'own' === $user ) {
				$user = get_current_user_id();
			}

			if ( ( 'all' === $user || get_current_user_id() !== $user ) && ! current_user_can( 'doublescale_booking_read_all_calendars' ) ) {
				doublescale_get_logger()->warning(
					'Permission denied to access events',
					array(
						'source'  => 'booking-event-rest',
						'user_id' => get_current_user_id(),
					)
				);
				return new WP_Error( 'rest_event_error', __( 'You do not have permission', 'doublescale' ), array( 'status' => 403 ) );
			}

			$query = EventModel::with( array( 'calendar' ) );

			if ( $keyword ) {
				$query->where( 'name', 'LIKE', '%' . $keyword . '%' );
			}

			if ( 'all' !== $user ) {
				$query->where( 'user_id', $user );
			}

			$events = $query->paginate( $per_page, array( '*' ), 'page', $page );

			// Prepare events for response to include calendar data
			$events_data     = $events->toArray();
			$prepared_events = array();
			foreach ( $events->items() as $event ) {
				$prepared_events[] = $this->prepare_event_for_response( $event );
			}
			$events_data['data'] = $prepared_events;

			return new WP_REST_Response( $events_data, 200 );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				'Booking event controller exception in get_items',
				array(
					'source'    => 'booking-event-rest',
					'method'    => 'get_items',
					'exception' => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
				)
			);
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return boolean
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'doublescale_booking_manage_own_calendars' ) || current_user_can( 'doublescale_booking_read_all_calendars' );
	}

	/**
	 * Create item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );

			$calendar_id       = $request->get_param( 'calendar_id' );
			$name              = $request->get_param( 'name' );
			$description       = $request->get_param( 'description' );
			$status            = $request->get_param( 'status' );
			$type              = $request->get_param( 'type' );
			$duration          = $request->get_param( 'duration' );
			$color             = $request->get_param( 'color' );
			$visibility        = $request->get_param( 'visibility' );
			$location          = $request->get_param( 'location' );
			$hosts             = $request->get_param( 'hosts' );
			$payments_settings = $request->get_param( 'payments_settings' );
			$group_settings    = $request->get_param( 'group_settings' );
			$color             = $request->get_param( 'color' ) ?: '#3A3A99'; // Default color if not provided

			if ( empty( $location ) ) {
						doublescale_get_logger()->warning(
							'Event location is required for event creation',
							array( 'source' => 'booking-event-rest' )
						);
				return new WP_Error( 'rest_event_error', __( 'Event location is required.', 'doublescale' ), array( 'status' => 400 ) );
			}

			// Conferencing locations (Google Meet / Zoom / MS Teams) are Pro-only.
			// Reject the request before any DB writes so stale admin pages can't
			// sneak a Pro-only location in after the Pro plugin is deactivated.
			$pro_required_type = LocationsManager::find_pro_conferencing_type( $location );
			if ( $pro_required_type && ! LocationsManager::is_pro_active() ) {
				$wpdb->query( 'ROLLBACK' );
				doublescale_get_logger()->warning(
					'Conferencing location requires Pro add-on',
					array(
						'source'        => 'booking-event-rest',
						'location_type' => $pro_required_type,
					)
				);
				return new WP_Error(
					'rest_event_error',
					sprintf(
						/* translators: %s: e.g. Google Meet, Zoom Video, MS Teams */
						__( '%s requires the Pro add-on. Please install and activate DoubleScale Pro to use this location.', 'doublescale' ),
						LocationsManager::get_conferencing_label( $pro_required_type )
					),
					array( 'status' => 403 )
				);
			}

			$calendar = CalendarModel::find( $calendar_id );
			if ( ! $calendar ) {
				$wpdb->query( 'ROLLBACK' );
				doublescale_get_logger()->warning(
					'Calendar not found for event creation',
					array(
						'source'      => 'booking-event-rest',
						'calendar_id' => (int) $calendar_id,
					)
				);
				return new WP_Error( 'rest_event_error', __( 'You must add event to a calendar.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$team_events = array( 'collective', 'round-robin' );
			$host_events = array( 'one-to-one', 'group' );

			if ( ( 'host' === $calendar->type && ! in_array( $type, $host_events ) ) || ( 'team' === $calendar->type && ! in_array( $type, $team_events ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				doublescale_get_logger()->warning(
					'Invalid event type for calendar type',
					array(
						'source'        => 'booking-event-rest',
						'event_type'    => $type,
						'calendar_type' => $calendar->type,
					)
				);
				return new WP_Error( 'rest_event_error', __( 'Invalid event type.', 'doublescale' ), array( 'status' => 400 ) );
			}

			// Validate hosts for team calendar events
			if ( 'team' === $calendar->type && in_array( $type, $team_events ) ) {
				if ( empty( $hosts ) || ! is_array( $hosts ) || count( $hosts ) === 0 ) {
					$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->warning(
						'Team event requires at least one host',
						array(
							'source'      => 'booking-event-rest',
							'calendar_id' => (int) $calendar_id,
						)
					);
					return new WP_Error( 'rest_event_error', __( 'Team events require at least one host to be selected.', 'doublescale' ), array( 'status' => 400 ) );
				}
			}

			// Validate payment settings if provided
			if ( $payments_settings ) {
				// Use Payment_Validator to validate payment settings
				$validation_result = PaymentValidator::validate_payment_gateways( $payments_settings );

				// If validation fails, block the create operation and return error
				if ( is_wp_error( $validation_result ) ) {
					$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->warning(
						'Payment validation failed during create',
						array(
							'source' => 'booking-event-rest',
							'reason' => $validation_result->get_error_message(),
						)
					);
					return $validation_result;
				}
			}

			$event_data = array(
				'calendar_id' => $calendar_id,
				'name'        => $name,
				'description' => $description,
				'status'      => $status ?? 'active',
				'type'        => $type,
				'duration'    => $duration,
				'color'       => $color,
				'visibility'  => $visibility,
				'is_disabled' => false,
			);
			$event_data['availability_meta']['custom_availability'] = AvailabilityModel::getDefaultAvailability();

			if ( 'host' === $calendar->type ) {
				$default_availability = AvailabilityModel::where( 'user_id', $calendar->user_id )->where( 'is_default', 1 )->first();
				if ( ! $default_availability ) {
					// Lazy-seed a Mon-Fri 9-5 default so the user isn't blocked
					// from creating their first event. They can edit the
					// schedule afterwards from Booking → Availability.
					$default_availability = AvailabilityModel::createDefaultForUser( $calendar->user_id );
				}
				$event_data['availability_id']   = $default_availability->id;
				$event_data['availability_type'] = 'existing';
			} else {
				$event_data['availability_meta']['is_common'] = false;
				$event_data['availability_type']              = 'existing';

				$primary_host_availability = AvailabilityModel::where( 'user_id', $hosts[0] )->where( 'is_default', 1 )->first();
				if ( ! $primary_host_availability ) {
					$wpdb->query( 'ROLLBACK' );
					$user_label = $this->describe_host_for_error( (int) $hosts[0] );
					doublescale_get_logger()->warning(
						'Default availability not found for team member',
						array(
							'source' => 'booking-event-rest',
							'host'   => $user_label,
						)
					);
					return new WP_Error(
						'rest_event_error',
						sprintf(
							/* translators: %s: human-readable host identifier (display name or ID). */
							__( 'Default availability not found for team member %s. Open Booking → Availability and create a default schedule for this user before adding them to a team event.', 'doublescale' ),
							$user_label
						),
						array( 'status' => 400 )
					);
				}
				$event_data['availability_id'] = $primary_host_availability->id;

				foreach ( $hosts as $host ) {
					$host_availability = AvailabilityModel::where( 'user_id', $host )->where( 'is_default', 1 )->first();
					if ( ! $host_availability ) {
						$wpdb->query( 'ROLLBACK' );
						$user_label = $this->describe_host_for_error( (int) $host );
						doublescale_get_logger()->warning(
							'Default availability not found for team member',
							array(
								'source' => 'booking-event-rest',
								'host'   => $user_label,
							)
						);
						return new WP_Error(
							'rest_event_error',
							sprintf(
								/* translators: %s: human-readable host identifier (display name or ID). */
								__( 'Default availability not found for team member %s. Open Booking → Availability and create a default schedule for this user before adding them to a team event.', 'doublescale' ),
								$user_label
							),
							array( 'status' => 400 )
						);
					}
					$event_data['availability_meta']['hosts_schedules'][ $host ] = $host_availability->id;
				}
			}

			$event_data['availability_meta'] = maybe_serialize( $event_data['availability_meta'] );

			$event_data = array_filter( $event_data );
			$event      = EventModel::create( $event_data );
			$event->setEventRangeAttribute(
				array(
					'type' => 'days',
					'days' => 60,
				)
			);
			if ( ! $event->id ) {
				$wpdb->query( 'ROLLBACK' );
						doublescale_get_logger()->warning(
							'Failed to create event in database',
							array( 'source' => 'booking-event-rest' )
						);
				return new WP_Error( 'rest_event_error', __( 'Event not created', 'doublescale' ), array( 'status' => 500 ) );
			}

			$event->setReserveTimesAttribute( false );
			$event->location            = $location;
			$event->limits              = EventFields::instance()->get_default_limit_settings();
			$event->email_notifications = EventFields::instance()->get_default_email_notification_settings();
			$event->additional_settings = EventFields::instance()->get_default_additional_settings( $type );
			$event->advanced_settings   = EventFields::instance()->get_default_advanced_settings();
			$event->sms_notifications   = EventFields::instance()->get_default_sms_notification_settings();
			$event->payments_settings   = $payments_settings ?? EventFields::instance()->get_default_payments_settings();
			$event->team_members        = $hosts;
			if ( 'group' === $type ) {
				$event->group_settings = $group_settings ?? array(
					'max_invites'    => 2,
					'show_remaining' => true,
				);
			}

			$event->save();
			// Set system fields based on the validated location
			$event->setSystemFields();

			$wpdb->query( 'COMMIT' );
			return new WP_REST_Response( $event, 200 );
		} catch ( Exception $e ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->error(
						'Booking event controller exception in create_item',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'create_item',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return boolean
	 */
	public function create_item_permissions_check( $request ) {
		$calendar_id = $request->get_param( 'calendar_id' );
		return Capabilities::can_manage_calendar( $calendar_id );
	}

	/**
	 * Delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_items( $request ) {
		try {
			$ids = $request->get_param( 'ids' );

			if ( ! $ids ) {
						doublescale_get_logger()->warning(
							'No event IDs provided for deletion',
							array( 'source' => 'booking-event-rest' )
						);
				return new WP_Error( 'rest_event_error', __( 'No events to delete', 'doublescale' ), array( 'status' => 400 ) );
			}

			foreach ( $ids as $id ) {
				$event = EventModel::find( $id );

				if ( ! $event ) {
							doublescale_get_logger()->warning(
								'Event not found for deletion ',
								array(
									'source'   => 'booking-event-rest',
									'event_id' => (int) $id,
								)
							);
					return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
				}

				$event->delete();
			}

			return new WP_REST_Response( $ids, 200 );
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in delete_items',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'delete_items',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return boolean
	 */
	public function delete_items_permissions_check( $request ) {
		return current_user_can( 'doublescale_booking_manage_all_calendars' );
	}

	/**
	 * Get item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	// Inside REST_Event_Controller::get_item method in class-rest-event-controller.php

	public function get_item( $request ) {
		try {
			$id    = $request->get_param( 'id' );
			$event = EventModel::with( 'calendar', 'availability' )->where( 'id', $id )->first();

			if ( ! $event ) {
						doublescale_get_logger()->warning(
							'Event not found ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error(
					'rest_event_not_found',
					__( 'Event not found', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			$usersId = $event->getTeamMembersAttribute() ?: $event->user_id;
			$usersId = is_array( $usersId ) ? $usersId : array( $usersId );

			$users = array();
			foreach ( $usersId as $userId ) {
				$user = UserModel::find( $userId );

				if ( $user ) {
					$user_avatar_url = get_avatar_url( $user->ID );
					$availabilities  = AvailabilityModel::where( 'user_id', $user->ID )->get();

					$users[] = array(
						'id'             => $user->ID,
						'name'           => $user->display_name,
						'image'          => $user_avatar_url,
						'availabilities' => $availabilities,
					);
				}
			}

			$event->hosts   = $users;
			$event->reserve = $event->getReserveTimesAttribute();

			return new WP_REST_Response( $event, 200 );
		} catch ( \Throwable $e ) { // Catch Throwable
					doublescale_get_logger()->error(
						'Booking event controller exception in get_item',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'get_item',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get item availability
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item_range( $request ) {
		try {
			$id    = $request->get_param( 'id' );
			$event = EventModel::find( $id );

			if ( ! $event ) {
						doublescale_get_logger()->warning(
							'Event not found for range retrieval ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$data = array(
				'range' => $event->getEventRangeAttribute(),
			);

			return new WP_REST_Response( $data, 200 );
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in get_item_range',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'get_item_range',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fields( $request ) {
		try {
			$id    = $request->get_param( 'id' );
			$event = EventModel::find( $id );

			if ( ! $event ) {
						doublescale_get_logger()->warning(
							'Event not found for fields retrieval ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $event->fields, 200 );
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in get_fields',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'get_fields',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return boolean
	 */
	public function get_item_permissions_check( $request ) {
		$id = $request->get_param( 'id' );
		return Capabilities::can_read_event( $id ) || current_user_can( 'doublescale_booking_read_all_calendars' );
	}

	/**
	 * Update item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		try {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );
			$id                  = $request->get_param( 'id' );
			$user_id             = $request->get_param( 'user_id' );
			$name                = $request->get_param( 'name' );
			$description         = $request->get_param( 'description' );
			$status              = $request->get_param( 'status' );
			$type                = $request->get_param( 'type' );
			$dynamic_duration    = $request->get_param( 'dynamic_duration' );
			$duration            = $request->get_param( 'duration' );
			$color               = $request->get_param( 'color' );
			$event_availability  = $request->get_param( 'event_availability' );
			$availability_meta   = $request->get_param( 'event_availability_meta' );
			$availability_type   = $request->get_param( 'availability_type' );
			$team_availability   = $request->get_param( 'team_availability' );
			$visibility          = $request->get_param( 'visibility' );
			$location            = $request->get_param( 'location' );
			$limits              = $request->get_param( 'limits' );
			$additional_settings = $request->get_param( 'additional_settings' );
			$group_settings      = $request->get_param( 'group_settings' );
			$event_range         = $request->get_param( 'event_range' );
			$advanced_settings   = $request->get_param( 'advanced_settings' );
			if ( is_array( $advanced_settings ) ) {
				$advanced_settings = $this->sanitize_advanced_settings( $advanced_settings );
			}
			$email_notifications = $request->get_param( 'email_notifications' );
			$sms_notifications   = $request->get_param( 'sms_notifications' );
			$payments_settings   = $request->get_param( 'payments_settings' );
			$fields              = $request->get_param( 'fields' );
			$reserve_times       = $request->get_param( 'reserve_times' );
			$hosts               = $request->get_param( 'hosts' );
			$slug                = $request->get_param( 'slug' );

			$event = EventModel::with( 'calendar' )->find( $id );
			if ( ! $event ) {
				$wpdb->query( 'ROLLBACK' );
						doublescale_get_logger()->warning(
							'Event not found for update ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Validate payment settings
			if ( $payments_settings ) {
				// Use Payment_Validator to validate payment settings
				$validation_result = PaymentValidator::validate_payment_gateways( $payments_settings );

				// If validation fails, block the update and return error
				if ( is_wp_error( $validation_result ) ) {
					$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->warning(
						'Payment validation failed during update',
						array(
							'source' => 'booking-event-rest',
							'reason' => $validation_result->get_error_message(),
						)
					);
					return $validation_result;
				}
			}

			// Conferencing locations (Google Meet / Zoom / MS Teams) are Pro-only.
			// Only validate when the client actually sent a `location` param so
			// updates that don't touch the location aren't gated.
			if ( $request->has_param( 'location' ) && ! empty( $location ) ) {
				$pro_required_type = LocationsManager::find_pro_conferencing_type( $location );
				if ( $pro_required_type && ! LocationsManager::is_pro_active() ) {
					$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->warning(
						'Conferencing location requires Pro add-on',
						array(
							'source'        => 'booking-event-rest',
							'location_type' => $pro_required_type,
							'event_id'      => (int) $id,
						)
					);
					return new WP_Error(
						'rest_event_error',
						sprintf(
							/* translators: %s: e.g. Google Meet, Zoom Video, MS Teams */
							__( '%s requires the Pro add-on. Please install and activate DoubleScale Pro to use this location.', 'doublescale' ),
							LocationsManager::get_conferencing_label( $pro_required_type )
						),
						array( 'status' => 403 )
					);
				}
			}

			// Build the update set from parameters the client actually sent. Using
			// has_param() lets clients clear fields by sending '' / null / [] —
			// the previous array_filter() stripped every falsy value, so things
			// like description, payments_settings, and availability_id could
			// never be reset once set.
			$updated   = array();
			$param_map = array(
				'name'                => $name,
				'description'         => $description,
				'status'              => $status,
				'type'                => $type,
				'duration'            => $duration,
				'color'               => $color,
				'visibility'          => $visibility,
				'location'            => $location,
				'limits'              => $limits,
				'additional_settings' => $additional_settings,
				'group_settings'      => $group_settings,
				'event_range'         => $event_range,
				'advanced_settings'   => $advanced_settings,
				'email_notifications' => $email_notifications,
				'sms_notifications'   => $sms_notifications,
				'payments_settings'   => $payments_settings,
				'dynamic_duration'    => $dynamic_duration,
				'availability_type'   => $availability_type,
			);
			foreach ( $param_map as $column => $value ) {
				if ( $request->has_param( $column ) ) {
					$updated[ $column ] = $value;
				}
			}

			if ( $request->has_param( 'event_availability_meta' ) ) {
				$updated['availability_meta'] = maybe_serialize( $availability_meta );
			}

			if ( $request->has_param( 'event_availability' ) ) {
				$updated['availability_id'] = is_array( $event_availability ) && isset( $event_availability['id'] )
					? $event_availability['id']
					: null;
			}

			if ( $request->has_param( 'reserve_times' ) ) {
				$event->setReserveTimesAttribute( $reserve_times );
			}

			$waiting_list = $request->get_param( 'waiting_list' );
			if ( isset( $waiting_list ) && is_array( $waiting_list ) ) {
				if ( isset( $waiting_list['capacity'] ) ) {
					$waiting_list['capacity'] = max( 1, (int) $waiting_list['capacity'] );
				}
				if ( isset( $waiting_list['additional_people_limit'] ) ) {
					$waiting_list['additional_people_limit'] = max( 0, (int) $waiting_list['additional_people_limit'] );
				}
				$event->waiting_list_settings = $waiting_list;
			}

			if ( ! empty( $slug ) ) {
				$exists = EventModel::where( 'slug', $slug )->where( 'id', '!=', $id )->first();
				if ( $exists ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'rest_event_error', __( 'Event slug already exists', 'doublescale' ), array( 'status' => 400 ) );
				}

				$updated['slug'] = $slug;
			}

			if ( ! empty( $hosts ) && $event->calendar->type === 'team' ) {
				// Normalize hosts to array of IDs if they're arrays with 'id' key
				if ( is_array( $hosts ) && ! empty( $hosts ) && is_array( $hosts[0] ) && isset( $hosts[0]['id'] ) ) {
					$hosts = array_column( $hosts, 'id' );
				}

				$event->setTeamMembersAttribute( $hosts );
			}
			if ( $user_id ) {
				$updated['user_id'] = $user_id;
			}

			foreach ( $updated as $key => $value ) {
				$event->{$key} = $value;
			}

			if ( $fields ) {
				$event->updateFields( $fields );
			}

			// Handle availability updates based on calendar type and settings
			$result = $this->handle_availability_update( $event, $availability_type, $availability_meta, $event_availability, $team_availability );
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				doublescale_get_logger()->warning(
					'Availability update failed',
					array(
						'source' => 'booking-event-rest',
						'reason' => $result->get_error_message(),
					)
				);
				return $result;
			}

			$event->save();
			$event->updateSystemFields( $location );

			$wpdb->query( 'COMMIT' );
			return new WP_REST_Response( $event, 200 );
		} catch ( Exception $e ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->error(
						'Booking event controller exception in update_item',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'update_item',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update item availability
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item_availability( $request ) {
		try {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );

			$id           = $request->get_param( 'id' );
			$availability = $request->get_param( 'availability' );

			$event = EventModel::find( $id );

			if ( ! $event ) {
				$wpdb->query( 'ROLLBACK' );
						doublescale_get_logger()->warning(
							'Event not found for availability update ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$result = $this->update_event_host_availability( $availability );
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				doublescale_get_logger()->warning(
					'Host availability update failed',
					array(
						'source' => 'booking-event-rest',
						'reason' => $result->get_error_message(),
					)
				);
				return $result;
			}

			$wpdb->query( 'COMMIT' );
			return new WP_REST_Response( $event->availability_value, 200 );
		} catch ( Exception $e ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->error(
						'Booking event controller exception in update_item_availability',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'update_item_availability',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update fields
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_fields( $request ) {
		try {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );

			$id     = $request->get_param( 'id' );
			$fields = $request->get_param( 'fields' );
			$event  = EventModel::find( $id );

			if ( ! $event ) {
				$wpdb->query( 'ROLLBACK' );
						doublescale_get_logger()->warning(
							'Event not found for fields update ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$event->updateFields( $fields );

			$wpdb->query( 'COMMIT' );
			return new WP_REST_Response( $event->fields, 200 );
		} catch ( Exception $e ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->error(
						'Booking event controller exception in update_fields',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'update_fields',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return boolean
	 */
	public function update_item_permissions_check( $request ) {
		$id = $request->get_param( 'id' );
		return Capabilities::can_manage_event( $id ) || current_user_can( 'doublescale_booking_manage_all_calendars' );
	}

	/**
	 * Delete item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );

			$id = $request->get_param( 'id' );

			$event = EventModel::find( $id );

			if ( ! $event ) {
				$wpdb->query( 'ROLLBACK' );
						doublescale_get_logger()->warning(
							'Event not found for deletion ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$event->delete();

			$wpdb->query( 'COMMIT' );
			return new WP_REST_Response(
				array(
					'id' => $id,
				),
				200
			);
		} catch ( Exception $e ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->error(
						'Booking event controller exception in delete_item',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'delete_item',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return boolean
	 */
	public function delete_item_permissions_check( $request ) {
		$id = $request->get_param( 'id' );
		return Capabilities::can_manage_event( $id ) || current_user_can( 'doublescale_booking_manage_all_calendars' );
	}

	/**
	 * Duplicate item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_item( $request ) {
		try {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );

			$id = $request->get_param( 'id' );

			$event = EventModel::find( $id );

			if ( ! $event ) {
				$wpdb->query( 'ROLLBACK' );
						doublescale_get_logger()->warning(
							'Event not found for duplication ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$new_event = $event->duplicate();

			$wpdb->query( 'COMMIT' );
			return new WP_REST_Response( $new_event, 200 );
		} catch ( Exception $e ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
					doublescale_get_logger()->error(
						'Booking event controller exception in duplicate_item',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'duplicate_item',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Duplicate item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return boolean
	 */
	public function duplicate_item_permissions_check( $request ) {
		return current_user_can( 'doublescale_booking_manage_all_calendars' );
	}

	/**
	 * Get meta
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_meta( $request ) {
		try {
			$id  = $request->get_param( 'id' );
			$key = $request->get_param( 'key' );

			$event = EventModel::find( $id );

			if ( ! $event ) {
						doublescale_get_logger()->warning(
							'Event not found for meta retrieval ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$meta = $event->{$key};

			if ( ! isset( $event->{$key} ) ) {
				doublescale_get_logger()->warning(
					'Meta key not found',
					array(
						'source'   => 'booking-event-rest',
						'meta_key' => $key,
						'event_id' => (int) $id,
					)
				);
				return new WP_Error( 'rest_event_error', __( 'Meta not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// For advanced_settings, merge with defaults so legacy events (or events
			// missing keys added in newer versions) return the full expected shape
			// instead of an empty/partial object that crashes the React form.
			if ( 'advanced_settings' === $key ) {
				$defaults = EventFields::instance()->get_default_advanced_settings();
				$meta     = is_array( $meta ) ? array_merge( $defaults, $meta ) : $defaults;
			}

			return new WP_REST_Response( $meta, 200 );
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in get_meta',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'get_meta',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}


	/**
	 * Report whether the organizer SMS fallback chain resolves a phone for this event.
	 *
	 * Free returns `{resolved:false, source:null}` (no organizer phone resolution
	 * happens without Pro). Pro hooks `doublescale_booking_sms_organizer_phone_status`
	 * to swap in the real outcome from {@see BookingSmsNotifier::resolve_organizer_phone}.
	 *
	 * The SMS Notification tab calls this to decide whether to render a warning
	 * banner when an organizer-bound template is enabled but no phone can be found.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_sms_organizer_phone_status( $request ) {
		$id    = (int) $request->get_param( 'id' );
		$event = EventModel::find( $id );
		if ( ! $event ) {
			return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		$payload = array(
			'resolved' => false,
			'source'   => null,
		);

		/**
		 * Filter the organizer SMS phone resolution payload.
		 *
		 * Pro tier replaces with the real outcome of the organizer phone fallback chain.
		 *
		 * @param array     $payload Default {resolved:false, source:null}.
		 * @param EventModel $event   The event whose organizer is being resolved.
		 */
		$payload = (array) apply_filters( 'doublescale_booking_sms_organizer_phone_status', $payload, $event );

		return rest_ensure_response( $payload );
	}

	/**
	 * Report whether this event's `fields` meta already collects a phone number.
	 *
	 * Drives the SMS Notification tab's "Add phone question" prompt: if the
	 * form has no phone field and attendee SMS is enabled, the UI shows a
	 * warning + one-click button that POSTs to `/add-phone-question`.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_phone_question_status( $request ) {
		$id    = (int) $request->get_param( 'id' );
		$event = EventModel::find( $id );
		if ( ! $event ) {
			return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
		}
		$fields    = $event->get_meta( 'fields' );
		$has_phone = EventFields::instance()->has_phone_field( $fields );
		return rest_ensure_response( array( 'has_phone' => (bool) $has_phone ) );
	}

	/**
	 * Inject a required phone field into this event's `fields` meta and return
	 * the updated fields array. Idempotent — calling twice does not add two
	 * fields; the second call is a no-op and returns the existing fields.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_phone_question( $request ) {
		$id    = (int) $request->get_param( 'id' );
		$event = EventModel::find( $id );
		if ( ! $event ) {
			return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		$fields = $event->get_meta( 'fields' );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		if ( ! isset( $fields['system'] ) || ! is_array( $fields['system'] ) ) {
			$fields['system'] = array();
		}

		if ( EventFields::instance()->has_phone_field( $fields ) ) {
			return rest_ensure_response( array(
				'fields'  => $fields,
				'changed' => false,
			) );
		}

		$fields['system']['phone'] = EventFields::instance()->get_phone_field_template();
		$event->update_meta( 'fields', $fields );

		return rest_ensure_response( array(
			'fields'  => $fields,
			'changed' => true,
		) );
	}

	/**
	 * Disable item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function disable_item( $request ) {
		try {
			$id     = $request->get_param( 'id' );
			$status = $request->get_param( 'status' );
			$event  = EventModel::find( $id );
			if ( ! $event ) {
						doublescale_get_logger()->warning(
							'Event not found for disable status update ',
							array(
								'source'   => 'booking-event-rest',
								'event_id' => (int) $id,
							)
						);
				return new WP_Error( 'rest_event_error', __( 'Event not found', 'doublescale' ), array( 'status' => 404 ) );
			}
			$event->is_disabled = $status;
			$event->save();
			return new WP_REST_Response( $event, 200 );
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in disable_item',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'disable_item',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'rest_event_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Sanitize advanced_settings payload before persisting.
	 *
	 * Coerces time values to positive integers (DateTime::modify breaks on
	 * zero/negative durations) and locks enum-like fields to known values.
	 */
	private function sanitize_advanced_settings( array $settings ) {
		$allowed_units = array( 'minutes', 'hours', 'days' );

		foreach ( array( 'cannot_cancel_time_value', 'cannot_reschedule_time_value', 'confirmation_time_value' ) as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$value             = (int) $settings[ $key ];
				$settings[ $key ] = $value > 0 ? $value : 1;
			}
		}

		foreach ( array( 'cannot_cancel_time_unit', 'cannot_reschedule_time_unit', 'confirmation_time_unit' ) as $key ) {
			if ( array_key_exists( $key, $settings ) && ! in_array( $settings[ $key ], $allowed_units, true ) ) {
				$settings[ $key ] = 'hours';
			}
		}

		foreach ( array( 'cannot_cancel_time', 'cannot_reschedule_time' ) as $key ) {
			if ( array_key_exists( $key, $settings ) && ! in_array( $settings[ $key ], array( 'event_start', 'less_than' ), true ) ) {
				$settings[ $key ] = 'event_start';
			}
		}

		// If redirect is disabled, scrub URL + query string so a stale value
		// can't leak back via getAdvancedSettings() on the next booking.
		// Only clear keys that the client actually sent so the stored shape
		// doesn't grow with synthetic '' values on every save.
		if ( array_key_exists( 'redirect_after_submit', $settings ) && empty( $settings['redirect_after_submit'] ) ) {
			if ( array_key_exists( 'redirect_url', $settings ) ) {
				$settings['redirect_url'] = '';
			}
			if ( array_key_exists( 'redirect_query_string', $settings ) ) {
				$settings['redirect_query_string'] = '';
			}
		}

		return $settings;
	}

	// Update event availability
	private function update_event_host_availability( $event_availability, $current_event_id = null ) {
		try {
			// Check if event_availability is provided and not empty
			if ( empty( $event_availability ) ) {
				return true; // Nothing to update
			}
			// Find the availability record
			$availability = AvailabilityModel::where( 'id', $event_availability['id'] )->first();

			if ( ! $availability ) {
				doublescale_get_logger()->warning(
					'Availability record not found',
					array(
						'source'          => 'booking-event-rest',
						'availability_id' => $event_availability['id'],
					)
				);
				return new WP_Error(
					'rest_event_error',
					__( 'Availability record not found', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			// Guard against silent global edits: if more than one event
			// references this availability row, the in-event edit would
			// rewrite the schedule for every other event too. Refuse and
			// point the user at the central editor (Booking → Availability)
			// so the change is deliberate. We only count events other than
			// the one we're saving so re-saving the same event still works.
			$other_event_count = EventModel::where( 'availability_id', $availability->id );
			if ( $current_event_id ) {
				$other_event_count = $other_event_count->where( 'id', '!=', $current_event_id );
			}
			if ( $other_event_count->count() > 0 ) {
				return new WP_Error(
					'rest_event_availability_shared',
					__( 'This availability is used by other events. Edit it from Booking → Availability, or switch this event to a custom schedule.', 'doublescale' ),
					array( 'status' => 409 )
				);
			}

			// Update the availability record using Eloquent
			try {
				// If event_availability contains the value data directly
				if ( isset( $event_availability['value'] ) ) {
					$availability->value = $event_availability['value'];
				}

				// Update other fields if provided
				if ( isset( $event_availability['name'] ) ) {
					$availability->name = $event_availability['name'];
				}

				if ( isset( $event_availability['timezone'] ) ) {
					$availability->timezone = $event_availability['timezone'];
				}

				// Save the changes
				$updated = $availability->save();

				if ( ! $updated ) {
					doublescale_get_logger()->warning(
						'Failed to update availability record',
						array(
							'source'          => 'booking-event-rest',
							'availability_id' => $event_availability['id'],
						)
					);
					return new WP_Error(
						'rest_event_error',
						__( 'Failed to update availability', 'doublescale' ),
						array( 'status' => 500 )
					);
				}

				return $availability;
			} catch ( Exception $e ) {
				doublescale_get_logger()->error(
					'Booking event controller exception updating availability',
					array(
						'source'          => 'booking-event-rest',
						'availability_id' => $event_availability['id'],
						'exception'       => $e->getMessage(),
					)
				);
				return new WP_Error(
					'rest_event_error',
					$e->getMessage(),
					array( 'status' => 500 )
				);
			}
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in update_event_host_availability',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'update_event_host_availability',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			throw $e;
		}
	}

	// Update event availability
	private function update_event_team_availability( $event, $team_availability ) {
		try {
			foreach ( $team_availability as $user_id => $availabilityData ) {
				$model = AvailabilityModel::find( $availabilityData['id'] );
				if ( ! $model ) {
					doublescale_get_logger()->warning(
						'Availability not found for team member',
						array(
							'source'          => 'booking-event-rest',
							'availability_id' => $availabilityData['id'],
						)
					);
					return new WP_Error(
						'rest_event_error',
						__( 'Availability not found', 'doublescale' ),
						array( 'status' => 404 )
					);
				}
				$model->value = $availabilityData['value'];
				$model->save();
			}
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in update_event_team_availability',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'update_event_team_availability',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			throw $e;
		}
	}

	// Handle availability updates based on calendar type and settings.
	//
	// Branches we handle here only target shared AvailabilityModel rows.
	// For `availability_type === 'custom'` the schedule lives inside
	// availability_meta['custom_availability'] and is already persisted by
	// the caller serializing availability_meta — there's nothing extra to
	// do on the AvailabilityModel side, so we explicitly fall through.
	//
	// To prevent one event from silently rewriting the schedule of every
	// other event that shares the same AvailabilityModel row, we count
	// usages before saving and refuse the in-event edit when the row is
	// shared. The user is asked to go to Booking → Availability instead.
	private function handle_availability_update( $event, $availability_type, $availability_meta, $event_availability, $team_availability ) {
		try {
			$calendar_type = $event->calendar->type;
			$is_common     = is_array( $availability_meta ) ? ( $availability_meta['is_common'] ?? null ) : null;

			if ( 'custom' === $availability_type ) {
				// Per-event snapshot lives in availability_meta — nothing to
				// write on the shared AvailabilityModel row. We intentionally
				// stop here so a custom schedule never mutates a global row.
				return true;
			}

			if ( 'host' === $calendar_type && 'existing' === $availability_type ) {
				return $this->update_event_host_availability( $event_availability, $event->id );
			}

			if ( 'team' === $calendar_type && true === $is_common && 'existing' === $availability_type ) {
				return $this->update_event_host_availability( $event_availability, $event->id );
			}

			if ( 'team' === $calendar_type && false === $is_common && 'existing' === $availability_type ) {
				return $this->update_event_team_availability( $event, $team_availability );
			}

			return true; // No specific availability update needed
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in handle_availability_update',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'handle_availability_update',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			throw $e;
		}
	}

	/**
	 * Get latest events
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_latest_events( $request ) {
		try {
			$limit   = $request->get_param( 'limit' ) ? absint( $request->get_param( 'limit' ) ) : 7;
			$user_id = $request->get_param( 'user_id' ) ? absint( $request->get_param( 'user_id' ) ) : null;
			$status  = $request->get_param( 'status' ) ? sanitize_text_field( $request->get_param( 'status' ) ) : null;

			$query = EventModel::with( array( 'calendar', 'meta' ) )->orderBy( 'created_at', 'desc' );

			// Apply filters if provided
			if ( $user_id ) {
				$query->where( 'user_id', $user_id );
			} elseif ( ! current_user_can( 'doublescale_booking_read_all_calendars' ) ) {
				$query->where( 'user_id', get_current_user_id() );
				$user_id = get_current_user_id(); // Set user_id for count query
			}

			if ( $status ) {
				$query->where( 'status', $status );
			}

			$events = $query->limit( $limit )->get();

			// Count enabled events based on user
			$enabled_events_count_query = EventModel::where( 'is_disabled', false );

			// If user_id is set, filter enabled events by user
			if ( $user_id ) {
				$enabled_events_count_query->where( 'user_id', $user_id );
			}

			$enabled_events_count = $enabled_events_count_query->count();

			// Prepare events for response
			$prepared_events = array();
			foreach ( $events as $event ) {
				$prepared_events[] = $this->prepare_event_for_response( $event );
			}

			return new WP_REST_Response(
				array(
					'events'               => $prepared_events,
					'enabled_events_count' => $enabled_events_count,
				),
				200
			);
		} catch ( Exception $e ) {
					doublescale_get_logger()->error(
						'Booking event controller exception in get_latest_events',
						array(
							'source'    => 'booking-event-rest',
							'method'    => 'get_latest_events',
							'exception' => $e->getMessage(),
							'file'      => $e->getFile(),
							'line'      => $e->getLine(),
						)
					);
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Prepare event data for API response
	 *
	 * @since 1.0.0
	 *
	 * @param EventModel $event The event model.
	 * @return array Prepared event with only essential data.
	 */
	protected function prepare_event_for_response( $event ) {
		// Extract only the essential data needed for display
		$prepared_data = array(
			'id'            => $event->id,
			'name'          => $event->name,
			'duration'      => $event->duration,
			'type'          => $event->type,
			'booking_count' => $event->getBookingCountAttribute(),
			'location'      => $event->location,
			'calendar_id'   => $event->calendar_id,
			'slug'          => $event->slug,
			'calendar'      => $event->calendar,
		);

		return $prepared_data;
	}

	/**
	 * Resolve a human-readable label for a host user id, used in error messages
	 * when the user has no default availability. Falls back to the bare id when
	 * the user record can't be loaded so admins can still locate the row.
	 *
	 * @param int $user_id WP user id of the host.
	 * @return string Display name suffixed with the id, or "user #N" on miss.
	 */
	private function describe_host_for_error( $user_id ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( $user && ! empty( $user->display_name ) ) {
			return sprintf( '%s (#%d)', $user->display_name, $user_id );
		}
		return sprintf( 'user #%d', $user_id );
	}
}
