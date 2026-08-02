<?php
/**
 * GET /doublescale/v1/portal/calendar?start=YYYY-MM-DD&end=YYYY-MM-DD
 *
 * Aggregated, contact-scoped calendar feed for the dashboard calendar. Each
 * contributing module pushes its own dated, customer-safe events through the
 * `doublescale_portal_calendar_events` filter (Booking projects bookings on
 * `start_time`; Sales projects invoice due dates / proposal expiries). The
 * controller owns the request window: it validates + clamps the span and builds
 * the inclusive upper bound ONCE, so a `start_time` datetime compared against a
 * bare `YYYY-MM-DD` end can't silently drop the last day's timed rows.
 *
 * This is intentionally a SEPARATE seam from `doublescale_portal_timeline_items`:
 * the timeline keys items on `created_at` (a descending sort key), whereas the
 * calendar must place each item on its real event date.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Utils\CalendarWindow;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalCalendarController.
 */
class RestPortalCalendarController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/calendar',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_calendar' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => array(
						'start' => array(
							'type'     => 'string',
							'required' => true,
						),
						'end'   => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Build the aggregated calendar feed for the requested window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_calendar( WP_REST_Request $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_REST_Response( array( 'data' => array() ), 200 );
		}

		$window = self::resolve_window(
			(string) $request->get_param( 'start' ),
			(string) $request->get_param( 'end' )
		);
		if ( null === $window ) {
			return new WP_REST_Response( array( 'data' => array() ), 200 );
		}
		list( $start, $end_inclusive ) = $window;

		/**
		 * Filter the aggregated portal calendar events for the window.
		 *
		 * Each module pushes shaped, customer-safe events:
		 * array{ id, kind, title, start, end?, all_day, timezone?, status, route? }.
		 *
		 * @param array<int, array<string, mixed>>                  $events        Calendar events.
		 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact       Resolved contact.
		 * @param string                                            $start         Window start (Y-m-d).
		 * @param string                                            $end_inclusive Window end, inclusive end-of-day (Y-m-d H:i:s).
		 */
		$events = (array) apply_filters( 'doublescale_portal_calendar_events', array(), $contact, $start, $end_inclusive );
		$events = array_values( array_filter( $events, 'is_array' ) );

		return new WP_REST_Response( array( 'data' => $events ), 200 );
	}

	/**
	 * Validate + clamp the request window and build the inclusive end bound.
	 *
	 * Thin wrapper over the shared {@see CalendarWindow::resolve()} so the window
	 * logic (validate → clamp ≤92d → inclusive end-of-day) is single-sourced with
	 * the admin calendar feed. Kept as a public static for the existing route
	 * tests and any in-module callers.
	 *
	 * @param string $start_raw Raw `start` param.
	 * @param string $end_raw   Raw `end` param.
	 * @return array{0:string,1:string}|null
	 */
	public static function resolve_window( string $start_raw, string $end_raw ): ?array {
		return CalendarWindow::resolve( $start_raw, $end_raw );
	}
}
