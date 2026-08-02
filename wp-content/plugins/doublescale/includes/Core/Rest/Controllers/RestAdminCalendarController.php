<?php
/**
 * GET /doublescale/v1/calendar?start=YYYY-MM-DD&end=YYYY-MM-DD
 *
 * Aggregated, ROLE-SCOPED calendar feed for the admin/staff SPA. Each
 * contributing module pushes its own dated, staff-safe events through the
 * `doublescale_admin_calendar_events` filter (Booking projects bookings on
 * `start_time`; Sales projects invoice due dates / proposal expiries / contract
 * renewals; Pro adds Tasks + Deals). The controller owns the request window — it
 * validates + clamps the span and builds the inclusive upper bound ONCE via the
 * shared {@see \DoubleScale\Core\Utils\CalendarWindow} helper — and resolves the
 * viewer; every provider then scopes its own query against that viewer using its
 * OWN module's manager-vs-own rule (there is intentionally no global "can see
 * all" flag, because a staff member's authority differs per module).
 *
 * This is a separate seam from the customer portal feed
 * (`doublescale_portal_calendar_events`): that one is contact-scoped and
 * customer-safe; this one is staff-scoped and staff-rich.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Utils\CalendarWindow;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestAdminCalendarController.
 *
 * Named distinctly from the Booking module's per-host {@see \DoubleScale\Modules\Booking\Rest\Controllers\RestCalendarController}
 * so the two never collide in the short-name-keyed REST controller manifest
 * ({@see phpunit/RestControllerManifestUtil.php}). This one is the cross-module
 * admin/staff aggregator; that one manages a single calendar's events.
 */
class RestAdminCalendarController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'calendar';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_calendar' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'start'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'end'       => array(
							'type'     => 'string',
							'required' => true,
						),
						// Manager-only "view as assignee" — providers honor it ONLY
						// after confirming the viewer is manager-tier for that kind.
						'view_user' => array(
							'type'     => 'integer',
							'required' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Coarse gate: a logged-in DoubleScale staff member (or admin). Row-level
	 * visibility is enforced per-provider, so this only answers "may you open the
	 * calendar at all".
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to view the calendar.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		$user_id = get_current_user_id();
		if ( user_can( $user_id, 'manage_options' ) || Permissions::check_user_has_role( $user_id ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have access to the calendar.', 'doublescale' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Build the aggregated, role-scoped calendar feed for the requested window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_calendar( WP_REST_Request $request ) {
		$window = CalendarWindow::resolve(
			(string) $request->get_param( 'start' ),
			(string) $request->get_param( 'end' )
		);
		if ( null === $window ) {
			return new WP_REST_Response( array( 'data' => array() ), 200 );
		}
		list( $start, $end_inclusive ) = $window;

		$viewer_id = get_current_user_id();
		$view_user = $request->has_param( 'view_user' ) ? absint( $request->get_param( 'view_user' ) ) : 0;

		/**
		 * Filter the aggregated admin calendar events for the window.
		 *
		 * Each module pushes shaped, staff-safe events, scoping its own query
		 * against $viewer_id with its OWN manager-vs-own rule:
		 * array{ id, kind, title, start, end?, all_day, status, assignee?, contact?, route? }.
		 *
		 * @param array<int, array<string, mixed>> $events        Calendar events.
		 * @param array{0:string,1:string}         $window        [ $start (Y-m-d), $end_inclusive (Y-m-d H:i:s) ].
		 * @param int                              $viewer_id     Current staff user id — providers scope against THIS, never a client id.
		 * @param int                              $view_user     Optional "view as assignee" id; honored ONLY for manager-tier viewers (manager: 0 = all, >0 = that staffer; reps always see only their own and ignore it).
		 */
		$events = (array) apply_filters(
			'doublescale_admin_calendar_events',
			array(),
			array( $start, $end_inclusive ),
			$viewer_id,
			$view_user
		);
		$events = array_values( array_filter( $events, 'is_array' ) );

		return new WP_REST_Response( array( 'data' => $events ), 200 );
	}
}
