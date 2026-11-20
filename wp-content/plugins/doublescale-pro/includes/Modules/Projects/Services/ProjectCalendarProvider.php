<?php
/**
 * Projects ⇄ calendars bridge.
 *
 * Puts each project on its due date (all-day) in both calendars:
 *   - the team calendar, through Free's `doublescale_admin_calendar_events`,
 *     scoped like the Projects list: those who read all projects see every
 *     project (or one owner's, with `$view_user`), everyone else their own;
 *   - the client portal calendar, through `doublescale_portal_calendar_events`
 *     (see {@see ProjectPortalProvider}): the client's own projects. Free's
 *     access gate takes them off when the client may not see Projects.
 *
 * The status says how it is going: `completed`, `overdue` (due date passed,
 * still running) or `open`. Projects without a due date are not placed.
 *
 * Registered in {@see \DoubleScale\Pro\Modules\Projects\Module::boot()}, so it
 * only attaches while the Projects module is on.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Utils\CalendarSupport;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

/**
 * ProjectCalendarProvider.
 */
final class ProjectCalendarProvider {

	/**
	 * Safety cap on projects placed in one window.
	 */
	private const MAX_ROWS = 500;

	public function __construct() {
		add_filter( 'doublescale_admin_calendar_events', array( $this, 'add_team_events' ), 10, 4 );
	}

	/**
	 * The team calendar's projects for the window.
	 *
	 * @param array<int, array<string, mixed>> $events    Events collected so far.
	 * @param array{0:string,1:string}         $window    [ start (Y-m-d), end_inclusive (Y-m-d H:i:s) ].
	 * @param int                              $viewer_id Current staff user id.
	 * @param int                              $view_user Manager-only "view as" id (0 = all).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_team_events( array $events, array $window, int $viewer_id, int $view_user ): array {
		if ( ! self::module_on() ) {
			return $events;
		}

		list( $start, $end_inclusive ) = $window;

		// As the Projects list: without "read all projects", only your own and your teams'.
		$reads_all  = user_can( $viewer_id, 'doublescale_project_read_all_projects' );
		$scope_user = $reads_all ? ( $view_user > 0 ? $view_user : 0 ) : $viewer_id;

		$query = self::in_window( $start, $end_inclusive );
		if ( $scope_user > 0 ) {
			// The projects they own or are on the team of.
			$query->involving( $scope_user );
		}

		$projects = $query->get();
		if ( $projects->isEmpty() ) {
			return $events;
		}

		$names    = CalendarSupport::user_names( $projects->pluck( 'owner_id' )->all() );
		$contacts = self::contact_names( $projects->pluck( 'contact_id' )->all() );

		foreach ( $projects as $project ) {
			$owner_id   = (int) $project->owner_id;
			$contact_id = (int) $project->contact_id;

			$events[] = self::event( $project, 'projects/' . (int) $project->id ) + array(
				'assignee' => $owner_id > 0 ? array(
					'id'   => $owner_id,
					'name' => $names[ $owner_id ] ?? '',
				) : null,
				'contact'  => $contact_id > 0 ? array(
					'id'   => $contact_id,
					'name' => $contacts[ $contact_id ] ?? '',
				) : null,
			);
		}

		return $events;
	}

	/**
	 * The client's projects for the portal calendar window.
	 *
	 * @param array<int, array<string, mixed>> $events        Events collected so far.
	 * @param ContactModel|null                $contact       The client's contact.
	 * @param string                           $start         Window start (Y-m-d).
	 * @param string                           $end_inclusive Window end (Y-m-d H:i:s).
	 * @return array<int, array<string, mixed>>
	 */
	public static function add_client_events( array $events, $contact, string $start, string $end_inclusive ): array {
		if ( ! self::module_on() || ! $contact instanceof ContactModel || ! self::free_gates_project_events() ) {
			return $events;
		}

		$projects = ProjectPortalScope::apply( self::in_window( $start, $end_inclusive ), (int) $contact->id )
			->get();

		foreach ( $projects as $project ) {
			$events[] = self::event( $project, '/projects/' . (int) $project->id );
		}

		return $events;
	}

	/**
	 * Projects due inside the window, with their status.
	 *
	 * @param string $start         Window start (Y-m-d).
	 * @param string $end_inclusive Window end (Y-m-d H:i:s).
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	private static function in_window( string $start, string $end_inclusive ) {
		return ProjectModel::with( array( 'status' ) )
			->whereNotNull( 'due_date' )
			->whereBetween( 'due_date', array( $start, substr( $end_inclusive, 0, 10 ) ) )
			->orderBy( 'due_date' )
			->limit( self::MAX_ROWS );
	}

	/**
	 * One all-day event on the project's due date.
	 *
	 * @param ProjectModel $project Project (status loaded).
	 * @param string       $route   Where the chip opens.
	 * @return array<string, mixed>
	 */
	private static function event( ProjectModel $project, string $route ): array {
		return array(
			'id'       => 'project-' . (int) $project->id,
			'kind'     => 'project',
			'title'    => (string) $project->title,
			'start'    => (string) $project->due_date,
			'end'      => null,
			'all_day'  => true,
			'timezone' => null,
			'status'   => self::status( $project ),
			'route'    => $route,
		);
	}

	/**
	 * `completed`, `overdue` (due date passed, still running) or `open`.
	 *
	 * @param ProjectModel $project Project (status loaded).
	 * @return string
	 */
	private static function status( ProjectModel $project ): string {
		if ( $project->status && (bool) $project->status->is_completed ) {
			return 'completed';
		}

		return (string) $project->due_date < current_time( 'Y-m-d' ) ? 'overdue' : 'open';
	}

	/**
	 * Whether free holds project events to the client's access (they belong
	 * to the Projects area). An older free shows any kind it does not know,
	 * so a client the Projects area is hidden from would see project names
	 * and dates on the calendar: add none there.
	 *
	 * @return bool
	 */
	private static function free_gates_project_events(): bool {
		if ( ! class_exists( \DoubleScale\Modules\Portal\Services\PortalFeatures::class ) ) {
			return false;
		}
		$index = \DoubleScale\Modules\Portal\Services\PortalFeatures::index();

		return ! empty( $index['calendar_kinds']['project'] );
	}

	private static function module_on(): bool {
		return function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'projects' );
	}

	/**
	 * `[ contact_id => display name ]` for a set of ids, in one query.
	 *
	 * @param array<int, int> $contact_ids Contact ids.
	 * @return array<int, string>
	 */
	private static function contact_names( array $contact_ids ): array {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'intval', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return array();
		}

		$map = array();
		foreach ( ContactModel::whereIn( 'id', $contact_ids )->get() as $contact ) {
			$name                      = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
			$map[ (int) $contact->id ] = '' !== $name ? $name : (string) ( $contact->email ?? '' );
		}

		return $map;
	}
}
