<?php

/**
 * Class Rest_Booking_Controller
 *
 * This class is responsible for handling the booking controller
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Rest\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DateTime;
use WP_Error;
use Exception;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Models\BookingOrderModel;
use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Services\BookingValidator;
use DoubleScale\Modules\Booking\Services\BookingService;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Modules\Booking\Capabilities;
use DoubleScale\Modules\Booking\Models\BookedSlotModel;
use DoubleScale\Modules\Booking\Models\BookingHostsModel;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Booking\BookingUtils;

/**
 * Booking Controller class
 */
class RestBookingController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'booking/bookings';

	/**
	 * Booking status constants
	 */
	protected $STATUS_PENDING   = 'pending';
	protected $STATUS_COMPLETED = 'completed';
	protected $STATUS_CANCELLED = 'cancelled';
	protected $STATUS_NO_SHOW   = 'no-show';


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
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		// Single booking route
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
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

		// Booking counts route
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/counts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_booking_counts' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'user' => array(
							'description' => __( 'User ID to filter counts by.', 'doublescale' ),
							'type'        => 'string',
							'default'     => 'own',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_booking_analytics' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'user' => array(
							'description' => __( 'User ID to filter analytics by.', 'doublescale' ),
							'type'        => 'string',
							'default'     => 'own',
						),
						'date' => array(
							'description' => __( 'Month and year in format "March 2025".', 'doublescale' ),
							'type'        => 'string',
							'default'     => '', // Current month and year will be used if empty
						),
					),
				),
			)
		);

		// Total guests route
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/total-guests',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_total_guests' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'user'   => array(
							'description' => __( 'User ID to filter guests by.', 'doublescale' ),
							'type'        => 'string',
							'default'     => 'own',
						),
						'filter' => array(
							'description' => __( 'Filter the results.', 'doublescale' ),
							'type'        => 'object',
						),
					),
				),
			)
		);

		// Revenue analytics route
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/revenue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_revenue' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'user'    => array(
							'description' => __( 'User ID to filter revenue by.', 'doublescale' ),
							'type'        => 'string',
							'default'     => 'own',
						),
						'period'  => array(
							'description' => __( 'Period to get revenue for: weekly, monthly, quarterly, or yearly.', 'doublescale' ),
							'type'        => 'string',
							'default'     => 'monthly',
							'enum'        => array( 'weekly', 'monthly', 'quarterly', 'yearly' ),
						),
						'year'    => array(
							'description' => __( 'Year to get revenue for.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => gmdate( 'Y' ),
						),
						'month'   => array(
							'description' => __( 'Month to get revenue for (1-12). Only applicable for monthly period.', 'doublescale' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 12,
						),
						'quarter' => array(
							'description' => __( 'Quarter to get revenue for (1-4). Only applicable for quarterly period.', 'doublescale' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 4,
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
			'title'      => 'booking',
			'type'       => 'object',
			'properties' => array(
				'id'           => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'hash_id'      => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'event_id'     => array(
					'description' => __( 'Event ID.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'contact_id'   => array(
					'description' => __( 'Contact ID.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'start_date'   => array(
					'description' => __( 'Start date.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'slot_time'    => array(
					'description' => __( 'Slot time.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'source'       => array(
					'description' => __( 'Source.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'status'       => array(
					'description' => __( 'Status.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'cancelled_by' => array(
					'description' => __( 'Cancelled by.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'event_url'    => array(
					'description' => __( 'Event URL.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'created_at'   => array(
					'description' => __( 'Creation time.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'updated_at'   => array(
					'description' => __( 'Update time.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Get collection params.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'keyword'  => array(
				'description'       => __( 'Keyword to search.', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'description'       => __( 'Number of items to fetch.', 'doublescale' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'description'       => __( 'Page number.', 'doublescale' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'filter'   => array(
				'description' => __( 'Filter the results.', 'doublescale' ),
				'type'        => 'object',
			),
		);
	}

	/**
	 * Get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {

		// Parse and sanitize pagination parameters
		$page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?? 10 ) ) );
		$keyword  = sanitize_text_field( $request->get_param( 'keyword' ) ?? '' );

		// Parse filters
		$filter     = $request->get_param( 'filter' ) ?? array();
		$user       = sanitize_text_field( Arr::get( $filter, 'user', 'own' ) );
		$period     = sanitize_text_field( Arr::get( $filter, 'period', 'all' ) );
		$event      = sanitize_text_field( Arr::get( $filter, 'event', 'all' ) );
		$event_type = sanitize_text_field( Arr::get( $filter, 'event_type', 'all' ) );
		$search     = sanitize_text_field( Arr::get( $filter, 'search', '' ) );

		// Validate date parameters
		$year  = $this->validate_year( Arr::get( $filter, 'year', current_time( 'Y' ) ) );
		$month = $this->validate_month( Arr::get( $filter, 'month', current_time( 'm' ) ) );
		$day   = $this->validate_day( Arr::get( $filter, 'day' ) );

		if ( 'own' === $user ) {
			$user = get_current_user_id();
		}

		if ( ( 'all' === $user || get_current_user_id() !== $user ) && ! current_user_can( 'doublescale_booking_read_all_bookings' ) ) {
			return new WP_Error( 'rest_booking_error', __( 'You do not have permission', 'doublescale' ), array( 'status' => 403 ) );
		}

		try {

			$query = BookingModel::query();

			$this->apply_date_range_filter( $query, $year, $month, $day );

			if ( 'all' !== $user ) {
				$this->apply_user_filter( $query, $user );
			}

			if ( ! empty( $keyword ) ) {
				$query->where( 'name', 'LIKE', '%' . $keyword . '%' );
			}

			$status_counts = $this->get_status_counts( $query );

			$this->apply_period_filter( $query, $period );

			$this->apply_event_filters( $query, $event, $event_type );

			if ( ! empty( $search ) ) {
				$this->apply_search_filter( $query, $search );
			}

			$bookings = $query->with( 'event', 'event.calendar', 'contact', 'calendar.user', 'order' )->get();

			// Process merge tags for each booking's event
			foreach ( $bookings as $booking ) {
				$booking->booking_title = $booking->processMergeTagsEvent();
			}

			$general_settings = Settings::get( 'general', array() );
			// The 'bookings' array data is designed to compensate for pagination when it gets added.
			return new WP_REST_Response(
				array(
					'bookings'        => array(
						'data' => $bookings,
					),
					'time_format'     => $general_settings['time_format'] ?? '12',
					'pending_count'   => $status_counts[ $this->STATUS_PENDING ] ?? 0,
					'cancelled_count' => $status_counts[ $this->STATUS_CANCELLED ] ?? 0,
					'noshow_count'    => $status_counts[ $this->STATUS_NO_SHOW ] ?? 0,
					'waiting_count'   => $status_counts['waiting'] ?? 0,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'doublescale_booking_read_own_bookings' ) || current_user_can( 'doublescale_booking_read_all_bookings' );
	}

	/**
	 * Create item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			$event_id            = $request->get_param( 'event_id' );
			$start_date          = $request->get_param( 'start_date' );
			$duration            = $request->get_param( 'slot_time' );
			$timezone            = $request->get_param( 'timezone' );
			$name                = $request->get_param( 'name' );
			$email               = $request->get_param( 'email' );
			$status              = $request->get_param( 'status' );
			$location            = $request->get_param( 'location' );
			$current_url         = $request->get_param( 'current_url' );
			$fields              = $request->get_param( 'fields' );
			$ignore_availability = $request->get_param( 'ignore_availability' );
			$host_ids            = $request->get_param( 'hosts_ids' ) ?? null;

			$event    = BookingValidator::validate_event( $event_id );
			$duration = BookingValidator::validate_duration( $duration, $event->duration );

			$host_id = null;
			if ( $host_ids ) {
				if ( $event->type === 'round-robin' ) {
					$host_id = $host_ids[0];
				} else {
					$host_id = $host_ids;
				}
			}

			// `ignore_availability` is an admin shortcut for inserting bookings
			// outside the configured schedule (e.g. backfilling). Only trust it
			// when the request comes from a user who can manage all bookings;
			// otherwise fall through to the normal availability check so a
			// crafted public request can't bypass the schedule.
			$can_skip_availability = $ignore_availability && current_user_can( 'doublescale_booking_manage_all_bookings' );

			if ( ! $can_skip_availability ) {
				$start_date      = BookingValidator::validate_start_date( $start_date, $timezone );
				$available_slots = $event->get_booking_available_slots( $start_date, $duration, $timezone, $host_id );
				if ( ! $available_slots ) {
					throw new \Exception( __( 'Sorry, This booking is not available', 'doublescale' ) );
				}
			} else {
				$start_date = BookingUtils::create_date_time( $start_date, $timezone );
			}

			$invitee = array(
				array(
					'name'  => $name,
					'email' => $email,
				),
			);

			// Allow filtering the BookingService instance for testing
			$booking_service = apply_filters( 'doublescale_booking_booking_service_instance', new BookingService() );

			$validate_invitee = $booking_service->validate_invitee( $event, $invitee );

			$calendar_id = $event->calendar_id;
			$booking     = $booking_service->book_event_slot( $event, $calendar_id, $start_date, $duration, $timezone, $validate_invitee, $location, $status, $fields, $host_id, $can_skip_availability );

			$bookings   = array();
			$bookings[] = $booking;

			return new WP_REST_Response( $bookings, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}


	/**
	 * Create item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$event_id = $request->get_param( 'event_id' );
		return Capabilities::can_manage_event( $event_id );
	}

	/**
	 * Get item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		try {
			$id      = $request->get_param( 'id' );
			$booking = BookingModel::with( 'order' )->find( $id );

			if ( ! $booking ) {
				return new WP_Error( 'rest_booking_error', __( 'Booking not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$booking->load( 'contact', 'event', 'calendar.user', 'logs', 'event.calendar', 'hosts' );
			$booking->fields = $booking->get_meta( 'fields' );

			// Process merge tags for the booking's event
			$booking->booking_title = $booking->processMergeTagsEvent( $booking );

			return new WP_REST_Response( $booking, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$id = $request->get_param( 'id' );
		return Capabilities::can_read_booking( $id );
	}

	/**
	 * Update item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		try {
			$id                  = $request->get_param( 'id' );
			$cancellation_reason = $request->get_param( 'cancellation_reason' );

			$booking = BookingModel::find( $id );

			if ( ! $booking ) {
				return new WP_Error( 'rest_booking_error', __( 'Booking not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$old_status     = $booking->status;
			$old_start_time = $booking->start_time;

			$has_status     = $request->has_param( 'status' );
			$has_start_time = $request->has_param( 'start_time' );
			$has_end_time   = $request->has_param( 'end_time' );

			$status     = $has_status ? $request->get_param( 'status' ) : null;
			$start_time = $has_start_time ? $request->get_param( 'start_time' ) : null;
			$end_time   = $has_end_time ? $request->get_param( 'end_time' ) : null;

			if ( $has_status && $status !== $old_status ) {
				$valid_transitions = array(
					'waiting'   => array( 'scheduled', 'cancelled' ),
					'pending'   => array( 'scheduled', 'cancelled' ),
					'scheduled' => array( 'cancelled', 'no-show', 'completed' ),
					'cancelled' => array(),
					'completed' => array(),
					'no-show'   => array(),
				);

				if ( isset( $valid_transitions[ $old_status ] )
					&& ! in_array( $status, $valid_transitions[ $old_status ], true ) ) {
					return new WP_Error(
						'invalid_transition',
						sprintf(
							/* translators: 1: previous booking status, 2: target booking status */
							__( 'Cannot transition from %1$s to %2$s', 'doublescale' ),
							$old_status,
							$status
						),
						array( 'status' => 400 )
					);
				}
			}

			if ( 'waiting' === $old_status && 'scheduled' === $status ) {
				return $this->promote_waiting_booking( $booking );
			}

			$start_dt = null;
			$end_dt   = null;
			if ( $has_start_time && $start_time ) {
				$start_dt = new \DateTime( $start_time, new \DateTimeZone( $booking->timezone ) );
			}
			if ( $has_end_time && $end_time ) {
				$end_dt = new \DateTime( $end_time, new \DateTimeZone( $booking->timezone ) );
			}

			$time_changed = false;
			if ( $start_dt && 'cancelled' !== $status ) {
				if ( ! $end_dt ) {
					$end_dt = ( clone $start_dt )->modify( "+{$booking->slot_time} minutes" );
				}
				$duration = (int) ( ( $end_dt->getTimestamp() - $start_dt->getTimestamp() ) / 60 );
				if ( $duration <= 0 ) {
					$duration = (int) $booking->slot_time;
				}

				$service = new BookingService();
				$service->reschedule_booking( $booking, $start_dt, $end_dt, $duration );
				$booking->refresh();

				$time_changed = $booking->start_time !== $old_start_time;
			}

			$booking_data = array();
			if ( $has_status ) {
				$booking_data['status'] = $status;
			}
			if ( 'cancelled' === $status ) {
				$booking_data['cancelled_by'] = array(
					'type' => 'host',
					'id'   => get_current_user_id(),
				);
				$booking->update_meta( 'cancellation_reason', $cancellation_reason );
			}

			if ( ! empty( $booking_data ) ) {
				$booking->update( $booking_data );
				$booking->refresh();
			}

			if ( 'cancelled' === $status && 'cancelled' !== $old_status ) {
				BookedSlotModel::release( $booking->id );
				$booking->logs()->create(
					array(
						'type'    => 'info',
						'message' => __( 'Booking cancelled', 'doublescale' ),
						'details' => __( 'Booking cancelled by Host', 'doublescale' ),
					)
				);
				BookingEvents::emit( 'cancelled', (int) $booking->id, array( 'actor' => 'organizer' ) );
				if ( 'waiting' === $old_status ) {
					BookingModel::rebalanceWaitingListPositions( $booking );
				}
			} elseif ( $time_changed ) {
				$booking->logs()->create(
					array(
						'type'    => 'info',
						'message' => __( 'Booking rescheduled', 'doublescale' ),
						'details' => __( 'Booking rescheduled by Host', 'doublescale' ),
					)
				);
				BookingEvents::emit( 'rescheduled', (int) $booking->id, array( 'actor' => 'organizer' ) );
			}

			if ( $status && $status !== $old_status ) {
				if ( 'pending' === $old_status && 'scheduled' === $status ) {
					$booking->logs()->create(
						array(
							'type'    => 'info',
							'message' => __( 'Booking confirmed', 'doublescale' ),
							'details' => __( 'Booking confirmed by Host', 'doublescale' ),
						)
					);
					BookingEvents::emit( 'confirmed', (int) $booking->id, array( 'actor' => 'organizer' ) );
				} elseif ( 'completed' === $status ) {
					$booking->logs()->create(
						array(
							'type'    => 'info',
							'message' => __( 'Booking marked as completed', 'doublescale' ),
							'details' => __( 'Booking marked as completed by Host', 'doublescale' ),
						)
					);
					BookingEvents::emit( 'completed', (int) $booking->id, array( 'actor' => 'organizer' ) );
				} elseif ( 'rejected' === $status ) {
					$booking->logs()->create(
						array(
							'type'    => 'info',
							'message' => __( 'Booking rejected', 'doublescale' ),
							'details' => __( 'Booking rejected by Host', 'doublescale' ),
						)
					);
					BookingEvents::emit( 'rejected', (int) $booking->id, array( 'actor' => 'organizer' ) );
				} elseif ( 'no-show' === $status ) {
					$booking->logs()->create(
						array(
							'type'    => 'info',
							'message' => __( 'Booking marked as no-show', 'doublescale' ),
							'details' => __( 'Booking marked as no-show by Host', 'doublescale' ),
						)
					);
					BookingEvents::emit( 'no_show', (int) $booking->id, array( 'actor' => 'organizer' ) );
				}
			}

			return new WP_REST_Response( $booking, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Promote a waiting-list booking to scheduled.
	 * Acquires a slot atomically and dispatches lifecycle events.
	 *
	 * @param BookingModel $booking
	 * @return WP_REST_Response|WP_Error
	 */
	private function promote_waiting_booking( $booking ) {
		global $wpdb;

		$entity = $booking->getBookableEntity();
		$start  = $booking->start_time;
		$end    = $booking->end_time;

		// Same race shape as ClaimWaitlistPageRenderer: an attendee claim and
		// an admin promote can hit the same slot concurrently. Serialize on a
		// named lock keyed by (calendar, start, end) so only one wins.
		$lock_token = 'ds_claim_' . substr(
			md5( $booking->calendar_id . '|' . $start . '|' . $end ),
			0,
			52
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_token ) );
		if ( 1 !== $got_lock ) {
			return new WP_Error( 'slot_busy', __( 'Slot is being claimed by another request, please retry', 'doublescale' ), array( 'status' => 409 ) );
		}

		try {
			$wpdb->query( 'START TRANSACTION' );
			try {
				if ( $booking->event_id && $entity ) {
					switch ( $entity->type ) {
						case 'one-to-one':
							if ( BookedSlotModel::has_overlap( $booking->calendar_id, $start, $end ) ) {
								$wpdb->query( 'ROLLBACK' );
								return new WP_Error( 'slot_taken', __( 'Slot is no longer available', 'doublescale' ), array( 'status' => 409 ) );
							}
							break;

						case 'round-robin':
						case 'collective':
							$host_ids = $booking->hosts->pluck( 'ID' )->toArray();
							foreach ( $host_ids as $host_id ) {
								if ( BookingService::host_has_overlap( (int) $host_id, $start, $end ) ) {
									$wpdb->query( 'ROLLBACK' );
									return new WP_Error( 'slot_taken', __( 'Host is no longer available', 'doublescale' ), array( 'status' => 409 ) );
								}
							}
							break;

						case 'group':
							$max      = Arr::get( $entity->group_settings, 'max_invites', 2 );
							$overlaps = BookedSlotModel::count_overlaps( $booking->calendar_id, $start, $end );
							if ( $overlaps >= $max ) {
								$wpdb->query( 'ROLLBACK' );
								return new WP_Error( 'slot_taken', __( 'Slot is no longer available', 'doublescale' ), array( 'status' => 409 ) );
							}
							break;
					}
				}

				BookedSlotModel::acquire(
					$booking->calendar_id,
					$booking->start_time,
					$booking->end_time,
					$booking->id,
					$booking->event_id
				);

				$booking->update( array( 'status' => 'scheduled' ) );
				BookingHostsModel::where( 'booking_id', $booking->id )
					->update( array( 'status' => 'scheduled' ) );
				$booking->refresh();

				$wpdb->query( 'COMMIT' );
			} catch ( \Exception $e ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'slot_taken', __( 'Slot is no longer available', 'doublescale' ), array( 'status' => 409 ) );
			}
		} finally {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_token ) );
		}

		$booking->logs()->create(
			array(
				'type'    => 'info',
				'message' => __( 'Promoted from waiting list', 'doublescale' ),
				'details' => __( 'Booking promoted from waiting list to scheduled by Host', 'doublescale' ),
			)
		);

		// Promotion from waiting list is semantically a confirmation of an
		// already-existing booking, not a brand-new creation.
		BookingEvents::emit( 'confirmed', (int) $booking->id, array( 'actor' => 'organizer' ) );

		BookingModel::rebalanceWaitingListPositions( $booking );

		return new WP_REST_Response( $booking, 200 );
	}

	/**
	 * Update item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$id = $request->get_param( 'id' );
		return Capabilities::can_manage_booking( $id );
	}

	/**
	 * Delete item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			$id = $request->get_param( 'id' );

			$booking = BookingModel::find( $id );

			if ( ! $booking ) {
				return new WP_Error( 'rest_booking_error', __( 'Booking not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$booking->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$id = $request->get_param( 'id' );
		return Capabilities::can_manage_booking( $id );
	}


	/**
	 * Apply date range filter to booking query
	 *
	 * @param mixed       $query The Booking query object
	 * @param string      $year Year to filter by
	 * @param string      $month Month to filter by
	 * @param string|null $day Optional day to filter by
	 * @return void
	 */
	protected function apply_date_range_filter( $query, $year, $month, $day ) {
		// Database stores times in UTC, so we need to create the date range in UTC
		$utc_timezone = new \DateTimeZone( 'UTC' );

		if ( ! empty( $day ) ) {
			// Specific day
			$start_date = new DateTime( "$year-$month-$day 00:00:00", $utc_timezone );
			$end_date   = new DateTime( "$year-$month-$day 23:59:59", $utc_timezone );
		} else {
			// Full month
			$start_date = new DateTime( "$year-$month-01 00:00:00", $utc_timezone );
			$end_date   = clone $start_date;
			$end_date->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		}

		$query->whereBetween(
			'start_time',
			array(
				$start_date->format( 'Y-m-d H:i:s' ),
				$end_date->format( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Apply user filter to booking query.
	 *
	 * "My Meetings" means meetings the user will actually attend as host.
	 * On personal calendars (calendar.type = 'host'), the calendar owner IS the
	 * host; on team calendars (round-robin / collective), the actual host is
	 * stored per booking in booking_hosts and is not necessarily the team
	 * calendar owner. Match accordingly so:
	 *   - Personal-calendar owners see their own bookings.
	 *   - Team-event hosts see bookings they were assigned to host.
	 *   - Team-calendar owners do NOT see bookings they aren't hosting.
	 *
	 * @param mixed $query The Booking query object
	 * @param int   $user_id User ID to filter by
	 * @return void
	 */
	protected function apply_user_filter( $query, $user_id ) {
		$query->where(
			function ( $query ) use ( $user_id ) {
				$query->whereHas(
					'calendar',
					function ( $q ) use ( $user_id ) {
						$q->where( 'type', 'host' )->where( 'user_id', $user_id );
					}
				)->orWhereHas(
					'hosts',
					function ( $q ) use ( $user_id ) {
						global $wpdb;
						$q->where( $wpdb->users . '.ID', $user_id );
					}
				);
			}
		);
	}

	/**
	 * Get counts of bookings by status
	 *
	 * @param mixed $query The Booking query object to clone
	 * @return array Associative array with status counts
	 */
	protected function get_status_counts( $query ) {
		$counts                            = array();
		$counts[ $this->STATUS_PENDING ]   = ( clone $query )->where( 'status', $this->STATUS_PENDING )->count();
		$counts[ $this->STATUS_CANCELLED ] = ( clone $query )->where( 'status', $this->STATUS_CANCELLED )->count();
		$counts[ $this->STATUS_NO_SHOW ]   = ( clone $query )->where( 'status', $this->STATUS_NO_SHOW )->count();
		$counts['waiting']                 = ( clone $query )->where( 'status', 'waiting' )->count();

		return $counts;
	}

	/**
	 * Apply period filter to booking query
	 *
	 * @param mixed  $query The Booking query object
	 * @param string $period Period to filter by
	 * @return void
	 */
	protected function apply_period_filter( $query, $period ) {
		switch ( $period ) {
			case 'latest':
				$query->where( 'status', '!=', 'waiting' )
					->orderBy( 'created_at', 'desc' );
				break;

			case 'upcoming':
				$query->where( 'status', '!=', 'waiting' )
					->where( 'start_time', '>', current_time( 'mysql' ) )
					->orderBy( 'start_time' );
				break;

			case $this->STATUS_PENDING:
			case $this->STATUS_COMPLETED:
			case $this->STATUS_CANCELLED:
			case $this->STATUS_NO_SHOW:
			case 'waiting':
				$query->where( 'status', $period );
				break;

			default: // 'all'
				$query->where( 'status', '!=', 'waiting' )
					->orderBy( 'start_time' );
				break;
		}
	}

	/**
	 * Apply event filters to booking query. Supports filtering by event type
	 * and by a specific event ID (either "event_5" or plain numeric for back-compat).
	 *
	 * @param mixed  $query      The Booking query object
	 * @param string $event      Event identifier to filter by
	 * @param string $event_type Event type to filter by
	 * @return void
	 */
	protected function apply_event_filters( $query, $event, $event_type ) {
		if ( 'all' !== $event_type ) {
			$query->whereHas(
				'event',
				function ( $query ) use ( $event_type ) {
					$query->where( 'type', $event_type );
				}
			);
		}

		if ( 'all' !== $event ) {
			if ( strpos( $event, 'event_' ) === 0 ) {
				$query->where( 'event_id', absint( str_replace( 'event_', '', $event ) ) );
			} else {
				$query->where( 'event_id', absint( $event ) );
			}
		}
	}

	/**
	 * Apply search filter to booking query
	 *
	 * @param mixed  $query The Booking query object
	 * @param string $search Search term to filter by
	 * @return void
	 */
	protected function apply_search_filter( $query, $search ) {
		$query->whereHas(
			'contact',
			function ( $query ) use ( $search ) {
				$sanitized_search = '%' . $search . '%';
				$query->where( 'first_name', 'LIKE', $sanitized_search )
					->orWhere( 'last_name', 'LIKE', $sanitized_search )
					->orWhere( 'email', 'LIKE', $sanitized_search );
			}
		);
	}

	/**
	 * Validate year parameter
	 *
	 * @param string $year Year to validate
	 * @return string Validated year
	 */
	protected function validate_year( $year ) {
		$year = absint( $year );
		return $year >= 2000 ? $year : gmdate( 'Y' );
	}

	/**
	 * Validate month parameter
	 *
	 * @param string $month Month to validate
	 * @return string Validated month (01-12)
	 */
	protected function validate_month( $month ) {
		$month = absint( $month );
		return ( $month >= 1 && $month <= 12 ) ? sprintf( '%02d', $month ) : current_time( 'm' );
	}

	/**
	 * Validate day parameter
	 *
	 * @param string|null $day Day to validate
	 * @return string|null Validated day (01-31) or null
	 */
	protected function validate_day( $day ) {
		if ( empty( $day ) ) {
			return null;
		}

		$day = absint( $day );
		return ( $day >= 1 && $day <= 31 ) ? sprintf( '%02d', $day ) : null;
	}


	/**
	 * Get booking counts by status
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_booking_counts( $request ) {
		// Get user parameter
		$user = sanitize_text_field( $request->get_param( 'user' ) ?? 'own' );

		if ( 'own' === $user ) {
			$user = get_current_user_id();
		}

		if ( ( 'all' === $user || get_current_user_id() !== $user ) && ! current_user_can( 'doublescale_booking_read_all_bookings' ) ) {
			return new WP_Error( 'rest_booking_error', __( 'You do not have permission', 'doublescale' ), array( 'status' => 403 ) );
		}

		try {
			$query = BookingModel::query();

			// Apply user filter if needed
			if ( 'all' !== $user ) {
				$this->apply_user_filter( $query, $user );
			}

			// Get the current time for upcoming/past filtering
			$current_time = current_time( 'mysql' );

			// Calculate all counts
			$counts = array(
				'total'     => ( clone $query )->where( 'status', '!=', 'waiting' )->count(),
				'upcoming'  => ( clone $query )->where( 'start_time', '>', $current_time )->whereNotIn( 'status', BookingModel::NON_ACTIVE_STATUSES )->count(),
				'completed' => ( clone $query )->where( 'status', $this->STATUS_COMPLETED )->count(),
				'pending'   => ( clone $query )->where( 'status', $this->STATUS_PENDING )->count(),
				'cancelled' => ( clone $query )->where( 'status', $this->STATUS_CANCELLED )->count(),
				'no_show'   => ( clone $query )->where( 'status', $this->STATUS_NO_SHOW )->count(),
				'waiting'   => ( clone $query )->where( 'status', 'waiting' )->count(),
			);

			return new WP_REST_Response( $counts, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get booking analytics by day for a given month
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_booking_analytics( $request ) {
		// Get user parameter
		$user = sanitize_text_field( $request->get_param( 'user' ) ?? 'own' );

		if ( 'own' === $user ) {
			$user = get_current_user_id();
		}

		if ( ( 'all' === $user || get_current_user_id() !== $user ) && ! current_user_can( 'doublescale_booking_read_all_bookings' ) ) {
			return new WP_Error( 'rest_booking_error', __( 'You do not have permission', 'doublescale' ), array( 'status' => 403 ) );
		}

		try {
			// Parse date string (e.g., "March 2025")
			$date_str = sanitize_text_field( $request->get_param( 'date' ) ?? '' );

			if ( empty( $date_str ) ) {
				// Use current month and year if not provided
				$year  = current_time( 'Y' );
				$month = current_time( 'm' );
			} else {
				// Parse date string like "March 2025"
				$parsed_date = date_parse( $date_str );

				if ( $parsed_date['error_count'] > 0 || ! isset( $parsed_date['month'] ) || ! isset( $parsed_date['year'] ) ) {
					return new WP_Error(
						'rest_booking_error',
						__( 'Invalid date format. Please use format like "March 2025".', 'doublescale' ),
						array( 'status' => 400 )
					);
				}

				$year  = $parsed_date['year'];
				$month = $parsed_date['month'];
			}

			// Validate date ranges
			$year  = $this->validate_year( $year );
			$month = $this->validate_month( $month );

			// Create start and end dates for the month in UTC (DB stores times in UTC)
			$utc_timezone = new \DateTimeZone( 'UTC' );
			$start_date   = new DateTime( "$year-$month-01 00:00:00", $utc_timezone );
			$end_date     = clone $start_date;
			$end_date->modify( 'last day of this month' )->setTime( 23, 59, 59 );

			// Get days in this month
			$days_in_month = (int) $end_date->format( 'd' );

			// Base query
			$query = BookingModel::query();

			// Apply user filter if needed
			if ( 'all' !== $user ) {
				$this->apply_user_filter( $query, $user );
			}

			// Filter by month range
			$query->whereBetween(
				'start_time',
				array(
					$start_date->format( 'Y-m-d H:i:s' ),
					$end_date->format( 'Y-m-d H:i:s' ),
				)
			);

			// Get all relevant bookings for the month with their creation date and status
			$bookings = $query
				->select( 'id', 'status', 'start_time', 'created_at' )
				->get();

			// Initialize results array
			$results = array();

			// Temporary array to track booking counts
			$days_with_data = array();

			// Initialize the data structure for all days with zero counts
			for ( $day = 1; $day <= $days_in_month; $day++ ) {
				$day_key                    = (string) $day;
				$days_with_data[ $day_key ] = array(
					'booked'    => 0,
					'completed' => 0,
					'cancelled' => 0,
				);
			}

			// Increment counters for each booking
			foreach ( $bookings as $booking ) {
				if ( 'waiting' === $booking->status ) {
					continue;
				}

				// Get the day of the month (1-31)
				$booking_day = gmdate( 'j', strtotime( $booking->start_time ) );
				$created_day = gmdate( 'j', strtotime( $booking->created_at ) );

				// Make sure we're only counting days within our target month
				if ( gmdate( 'Y-m', strtotime( $booking->created_at ) ) === "$year-$month" ) {
					// Increment the "booked" counter for the creation date
					++$days_with_data[ (string) $created_day ]['booked'];
				}

				// Make sure we're only counting days within our target month
				if ( gmdate( 'Y-m', strtotime( $booking->start_time ) ) === "$year-$month" ) {
					// Increment status counters for the appointment date
					switch ( $booking->status ) {
						case $this->STATUS_COMPLETED:
							++$days_with_data[ (string) $booking_day ]['completed'];
							break;
						case $this->STATUS_CANCELLED:
							++$days_with_data[ (string) $booking_day ]['cancelled'];
							break;
					}
				}
			}

			// Only include days with booked > 0 in final results
			foreach ( $days_with_data as $day => $counts ) {
				if ( $counts['booked'] > 0 || $counts['completed'] > 0 || $counts['cancelled'] > 0 ) {
					$results[ $day ] = $counts;
				}
			}

			return new WP_REST_Response( $results, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get total guests for a specific period
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_total_guests( $request ) {
		$user = sanitize_text_field( $request->get_param( 'user' ) ?? 'own' );
		if ( 'own' === $user ) {
			$user = get_current_user_id();
		}

		// Check permissions
		if ( ( 'all' === $user || get_current_user_id() !== $user ) && ! current_user_can( 'doublescale_booking_read_all_bookings' ) ) {
			return new WP_Error( 'rest_booking_error', __( 'You do not have permission', 'doublescale' ), array( 'status' => 403 ) );
		}

		try {
			// Get period from filter
			$filter = $request->get_param( 'filter' ) ?? array();
			$period = sanitize_text_field( Arr::get( $filter, 'period', 'all' ) );

			// Initialize query and apply filters
			$query = BookingModel::query()
				->where( 'status', '!=', 'waiting' );
			if ( 'all' !== $user ) {
				$this->apply_user_filter( $query, $user );
			}
			$this->apply_period_filter_for_guests( $query, $period );

			// Fetch bookings (contacts via $booking->contact_id are not needed for the count itself,
			// but additional_guests live in booking meta which we still need to walk).
			$bookings = $query->get();

			// Count primary guests (one per booking)
			$primary_count = $bookings->count();

			// Count additional guests from booking metadata
			$additional_count = 0;
			foreach ( $bookings as $booking ) {
				$fields = $booking->get_meta( 'fields' );
				if ( is_array( $fields ) && isset( $fields['additional_guests'] ) && is_array( $fields['additional_guests'] ) ) {
					$additional_count += count( $fields['additional_guests'] );
				}
			}

			return new WP_REST_Response( $primary_count + $additional_count, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Apply time period filter for guest count queries
	 *
	 * @param mixed  $query The Booking query object
	 * @param string $period Period to filter by
	 * @return void
	 */
	protected function apply_period_filter_for_guests( $query, $period ) {
		if ( 'all' === $period ) {
			// No date filtering for 'all'
			return;
		}

		$date_range = $this->get_period_date_range( $period );
		if ( empty( $date_range ) ) {
			return;
		}

		// Apply date range filter to query
		$query->whereBetween( 'start_time', $date_range );
	}

	/**
	 * Get date range for a given period
	 *
	 * @param string $period Period type (this_week, this_month, this_year)
	 * @return array|null Array with start and end dates formatted for SQL, or null if invalid period
	 */
	protected function get_period_date_range( $period ) {
		// Use UTC timezone since database stores times in UTC
		$utc_timezone = new \DateTimeZone( 'UTC' );
		$date         = new DateTime( 'now', $utc_timezone );

		switch ( $period ) {
			case 'this_week':
				$start = clone $date;
				$start->modify( 'this week' )->setTime( 0, 0, 0 );

				$end = clone $start;
				$end->modify( '+6 days' )->setTime( 23, 59, 59 );
				break;

			case 'this_month':
				$start = clone $date;
				$start->modify( 'first day of this month' )->setTime( 0, 0, 0 );

				$end = clone $date;
				$end->modify( 'last day of this month' )->setTime( 23, 59, 59 );
				break;

			case 'this_year':
				$start = clone $date;
				$start->modify( 'first day of January this year' )->setTime( 0, 0, 0 );

				$end = clone $date;
				$end->modify( 'last day of December this year' )->setTime( 23, 59, 59 );
				break;

			default:
				return null;
		}

		return array(
			$start->format( 'Y-m-d H:i:s' ),
			$end->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Get total revenue for a given period
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_revenue( $request ) {
		// Get and sanitize parameters
		$user    = $this->sanitize_user_param( $request->get_param( 'user' ) ?? 'own' );
		$period  = sanitize_text_field( $request->get_param( 'period' ) ?? 'monthly' );
		$year    = (int) ( $request->get_param( 'year' ) ?? gmdate( 'Y' ) );
		$month   = (int) ( $request->get_param( 'month' ) ?? gmdate( 'n' ) );
		$quarter = (int) ( $request->get_param( 'quarter' ) ?? ceil( gmdate( 'n' ) / 3 ) );

		// Check permissions
		if ( ! $this->can_view_revenue( $user ) ) {
			return new WP_Error(
				'rest_booking_error',
				__( 'You do not have permission to view revenue data', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		try {
			// Build and execute query
			$query = $this->build_revenue_query( $user );

			// Apply date filters
			$date_range = $this->get_date_range_for_period( $period, $year, $month, $quarter );
			$this->apply_date_filter( $query, $date_range );

			// Calculate revenue totals
			// Booking payments share the booking-module currency — summing is safe.
			$total_revenue = (float) $query->sum( 'total' );
			$order_count   = (int) $query->count();

			// Build response
			$results = $this->build_revenue_response(
				$total_revenue,
				$order_count,
				$period,
				$year,
				$month,
				$quarter,
				$date_range
			);

			return new WP_REST_Response( $results, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_booking_revenue_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Sanitize user parameter
	 *
	 * @param string $user User parameter from request
	 * @return int|string User ID or 'all'
	 */
	private function sanitize_user_param( $user ) {
		if ( 'own' === $user ) {
			return get_current_user_id();
		}
		return sanitize_text_field( $user );
	}

	/**
	 * Check if current user can view revenue for given user
	 *
	 * @param int|string $user User ID or 'all'
	 * @return bool
	 */
	private function can_view_revenue( $user ) {
		if ( 'all' === $user || get_current_user_id() !== $user ) {
			return current_user_can( 'doublescale_booking_read_all_bookings' );
		}
		return true;
	}

	/**
	 * Build query for revenue data.
	 * Uses the calendar relationship.
	 *
	 * @param int|string $user User ID or 'all'
	 * @return object Query object
	 */
	private function build_revenue_query( $user ) {
		$query = BookingOrderModel::query();

		// Include only completed orders
		$query->where( 'status', '!=', 'cancelled' )
			->where( 'status', '!=', 'refunded' );

		// Apply user filter if specific user requested
		if ( 'all' !== $user ) {
			$query->whereHas(
				'booking',
				function ( $q ) use ( $user ) {
					$q->whereHas(
						'calendar',
						function ( $q ) use ( $user ) {
							$q->where( 'user_id', $user );
						}
					);
				}
			);
		}

		return $query;
	}

	/**
	 * Apply date filter to query
	 *
	 * @param object $query Query object
	 * @param array  $date_range Date range with start and end
	 * @return void
	 */
	private function apply_date_filter( $query, $date_range ) {
		$query->whereBetween(
			'created_at',
			array(
				$date_range['start']->format( 'Y-m-d H:i:s' ),
				$date_range['end']->format( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Build revenue response data
	 *
	 * @param float  $total_revenue Total revenue amount
	 * @param int    $order_count Number of orders
	 * @param string $period Period type (weekly, monthly, quarterly, yearly)
	 * @param int    $year Year
	 * @param int    $month Month number
	 * @param int    $quarter Quarter number
	 * @param array  $date_range Date range with start and end
	 * @return array Response data
	 */
	private function build_revenue_response( $total_revenue, $order_count, $period, $year, $month, $quarter, $date_range ) {
		$results = array(
			'total_revenue' => $total_revenue,
			'currency'      => get_option( 'doublescale_booking_currency', 'USD' ),
			'order_count'   => $order_count,
			'period'        => $period,
			'year'          => $year,
			'date_range'    => array(
				'start' => $date_range['start']->format( 'Y-m-d' ),
				'end'   => $date_range['end']->format( 'Y-m-d' ),
			),
		);

		// Add period-specific details
		switch ( $period ) {
			case 'monthly':
				$results['month']      = $month;
				$results['month_name'] = gmdate( 'F', mktime( 0, 0, 0, $month, 1, $year ) );
				break;

			case 'quarterly':
				$results['quarter'] = $quarter;
				break;

			case 'weekly':
				$week_number     = gmdate( 'W', $date_range['start']->getTimestamp() );
				$results['week'] = (int) $week_number;
				break;
		}

		return $results;
	}

	/**
	 * Get date range for specified period
	 *
	 * @param string  $period  Period type (weekly, monthly, quarterly, yearly)
	 * @param integer $year    Year
	 * @param integer $month   Month (1-12)
	 * @param integer $quarter Quarter (1-4)
	 *
	 * @return array Date range with start and end DateTime objects
	 */
	protected function get_date_range_for_period( $period, $year = null, $month = null, $quarter = null ) {
		// Set defaults if not provided
		$year    = $year ?? (int) gmdate( 'Y' );
		$month   = $month ?? (int) gmdate( 'n' );
		$quarter = $quarter ?? (int) ceil( gmdate( 'n' ) / 3 );

		// Use UTC timezone since database stores times in UTC
		$utc_timezone = new \DateTimeZone( 'UTC' );

		switch ( $period ) {
			case 'weekly':
				// Get the date for the first day of the specified week
				$current_week = (int) gmdate( 'W' );
				$date         = new DateTime( 'now', $utc_timezone );
				$date->setISODate( $year, $current_week );
				$date->setTime( 0, 0, 0 );

				$start = clone $date;
				$end   = clone $date;
				$end->modify( '+6 days' );
				$end->setTime( 23, 59, 59 );
				break;

			case 'monthly':
				$start = new DateTime( sprintf( '%d-%02d-01 00:00:00', $year, $month ), $utc_timezone );
				$end   = clone $start;
				$end->modify( 'last day of this month' );
				$end->setTime( 23, 59, 59 );
				break;

			case 'quarterly':
				// Calculate the first and last month of the quarter
				$first_month = ( ( $quarter - 1 ) * 3 ) + 1;
				$last_month  = $first_month + 2;

				$start = new DateTime( sprintf( '%d-%02d-01 00:00:00', $year, $first_month ), $utc_timezone );
				$end   = new DateTime( sprintf( '%d-%02d-01 00:00:00', $year, $last_month ), $utc_timezone );
				$end->modify( 'last day of this month' );
				$end->setTime( 23, 59, 59 );
				break;

			case 'yearly':
				$start = new DateTime( sprintf( '%d-01-01 00:00:00', $year ), $utc_timezone );
				$end   = new DateTime( sprintf( '%d-12-31 23:59:59', $year ), $utc_timezone );
				break;

			default:
				// Default to current month if invalid period
				$current_year  = (int) gmdate( 'Y' );
				$current_month = (int) gmdate( 'm' );
				$start         = new DateTime( sprintf( '%d-%02d-01 00:00:00', $current_year, $current_month ), $utc_timezone );
				$end           = clone $start;
				$end->modify( 'last day of this month' );
				$end->setTime( 23, 59, 59 );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}
}
