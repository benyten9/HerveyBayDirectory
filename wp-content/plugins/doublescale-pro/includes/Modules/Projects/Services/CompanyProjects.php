<?php
/**
 * Projects on a company page: the Projects tab (every member's projects) and
 * the active-projects figure, through free's company filters.
 *
 * @package DoubleScale\Pro\Modules\Projects\Services
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

/**
 * CompanyProjects class
 */
class CompanyProjects {

	/**
	 * Hook into free's company page.
	 */
	public static function register(): void {
		add_filter( 'doublescale_company_record_types', array( self::class, 'types' ) );
		add_filter( 'doublescale_company_records', array( self::class, 'records' ), 10, 6 );
		add_filter( 'doublescale_company_summary', array( self::class, 'summary' ), 10, 2 );
	}

	/**
	 * Projects come first on a company page, for those who see projects.
	 *
	 * @param string[] $types Types.
	 * @return string[]
	 */
	public static function types( $types ): array {
		$types = (array) $types;
		if ( Permissions::has_project_access() ) {
			array_unshift( $types, 'projects' );
		}
		return $types;
	}

	/**
	 * One page of the members' projects.
	 *
	 * @param array|null $result   Earlier result.
	 * @param string     $type     Record type.
	 * @param int[]      $ids      Member contact ids.
	 * @param mixed      $company  Company.
	 * @param int        $page     Page.
	 * @param int        $per_page Rows per page.
	 * @return array|null
	 */
	public static function records( $result, $type, $ids, $company, $page, $per_page ) {
		if ( 'projects' !== $type || is_array( $result ) ) {
			return $result;
		}
		if ( empty( $ids ) ) {
			return array(
				'data'  => array(),
				'total' => 0,
			);
		}

		$query = self::query( (array) $ids );
		$total = (int) ( clone $query )->count();
		$rows  = $query->with( 'status' )->orderBy( 'id', 'desc' )->skip( ( (int) $page - 1 ) * (int) $per_page )->take( (int) $per_page )->get();

		$data = array();
		foreach ( $rows as $project ) {
			$data[] = array(
				'id'           => (int) $project->id,
				'contact_id'   => (int) $project->contact_id,
				'shared'       => (int) $project->company_id === (int) $company->id,
				'title'        => (string) $project->title,
				'status'       => $project->status ? (string) $project->status->name : '',
				'status_color' => $project->status ? (string) ( $project->status->color ?? '' ) : '',
				'completed'    => $project->status ? (bool) $project->status->is_completed : false,
				'progress'     => null !== $project->progress ? (int) $project->progress : null,
				'due_date'     => $project->due_date ? (string) $project->due_date : null,
				'date'         => $project->created_at ? (string) $project->created_at : null,
				'route'        => 'projects/' . (int) $project->id,
			);
		}
		return array(
			'data'  => $data,
			'total' => $total,
		);
	}

	/**
	 * Active (not completed) projects of the members.
	 *
	 * @param array $summary Figures.
	 * @param int[] $ids     Member contact ids.
	 * @return array
	 */
	public static function summary( $summary, $ids ): array {
		$summary = (array) $summary;
		if ( $ids && Permissions::has_project_access() ) {
			$summary['active_projects'] = (int) self::query( (array) $ids )
				->whereHas(
					'status',
					static function ( $q ) {
						$q->where( 'is_completed', 0 );
					}
				)
				->count();
		}
		return $summary;
	}

	/**
	 * Members' projects, limited to the ones this user works on unless they
	 * manage all projects.
	 *
	 * @param int[] $ids Member contact ids.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	private static function query( array $ids ) {
		$query = ProjectModel::query()->whereIn( 'contact_id', array_map( 'intval', $ids ) );
		if ( ! PermissionsCompat::can_manage_all_projects() ) {
			$query->involving( get_current_user_id() );
		}
		return $query;
	}
}
