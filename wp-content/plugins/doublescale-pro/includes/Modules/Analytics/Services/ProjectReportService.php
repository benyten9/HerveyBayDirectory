<?php
/**
 * Project report service.
 *
 * The most bespoke of the entity reports:
 *
 *  - Status is a foreign key to doublescale_project_statuses, not an enum, so
 *    the breakdown joins that table for the user-defined name and colour, and
 *    "completed" means a status with is_completed = 1.
 *  - There is no completed_at column. Completion timestamps are derived from the
 *    real audit trail: a PROJECT_STATUS_CHANGED activity whose new_status_id maps
 *    to a completed status. This avoids the updated_at proxy, which would move a
 *    project between reporting periods whenever it is edited.
 *  - There is no currency column; budgets follow the global currency.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Services
 */

namespace DoubleScale\Pro\Modules\Analytics\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Pro\Modules\Analytics\Services\Support\ReportPeriod;
use DoubleScale\Pro\Modules\Analytics\Support\EntityReportDescriptor;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;

/**
 * Aggregates project KPIs, trend, and status breakdown.
 */
class ProjectReportService extends EntityReportService {

	/**
	 * @var array<int, bool>|null Cache of status_id => is_completed.
	 */
	private $completed_status_map = null;

	/**
	 * @return string
	 */
	protected function entity_key() {
		return EntityReportDescriptor::PROJECTS;
	}

	/**
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_kpis( ReportPeriod $period, array $filters ) {
		$created  = $this->records_in_period( $period, $filters );
		$previous = $this->records_in_previous_period( $period, $filters );

		$completed_ids  = $this->completed_status_ids();
		$completion_now = $this->completions_in_window( $period, $filters, false );
		$completion_prev = $this->completions_in_window( $period, $filters, true );

		$active   = $this->active_projects( $filters );
		$overdue  = $this->overdue_count( $filters );

		$on_time_now  = $this->on_time_rate( $completion_now );
		$on_time_prev = $this->on_time_rate( $completion_prev );

		return array(
			$this->kpi(
				'created',
				__( 'Projects Created', 'doublescale' ),
				$created->count(),
				$previous->count()
			),
			$this->snapshot_kpi(
				'active',
				__( 'Active Projects', 'doublescale' ),
				count( $active ),
				'number'
			),
			$this->kpi(
				'completed',
				__( 'Completed', 'doublescale' ),
				count( $completion_now ),
				count( $completion_prev )
			),
			$this->kpi(
				'completed_on_time',
				__( 'Completed On Time', 'doublescale' ),
				$on_time_now,
				$on_time_prev,
				'percent'
			),
			$this->snapshot_kpi(
				'overdue',
				__( 'Overdue', 'doublescale' ),
				$overdue,
				'number',
				true
			),
			$this->money_kpi(
				'total_budget',
				__( 'Total Budget', 'doublescale' ),
				$this->as_global_money( $this->sum_budget( $created ) ),
				$this->as_global_money( $this->sum_budget( $previous ) )
			),
			$this->money_kpi(
				'avg_budget',
				__( 'Avg Budget', 'doublescale' ),
				$this->as_global_money( $this->average_budget( $created ) ),
				$this->as_global_money( $this->average_budget( $previous ) )
			),
			$this->snapshot_kpi(
				'avg_progress',
				__( 'Avg Progress', 'doublescale' ),
				$this->average_progress( $active ),
				'percent'
			),
		);
	}

	/**
	 * Trend: projects created and completed per bucket.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_trend( ReportPeriod $period, array $filters ) {
		$created = $this->records_in_period( $period, $filters );

		$completed_totals = array_fill_keys( $period->buckets(), 0.0 );
		foreach ( $this->completions_in_window( $period, $filters, false ) as $completion ) {
			$bucket = $period->bucket_for( $completion['completed_at'] );
			if ( array_key_exists( $bucket, $completed_totals ) ) {
				$completed_totals[ $bucket ] += 1.0;
			}
		}

		return array(
			'labels' => $period->buckets(),
			'series' => array(
				$this->series(
					'created',
					__( 'Created', 'doublescale' ),
					array_values( $this->bucket_records( $period, $created, 'created_at' ) ),
					self::COLOR_PRIMARY
				),
				$this->series(
					'completed',
					__( 'Completed', 'doublescale' ),
					array_values( $completed_totals ),
					self::COLOR_POSITIVE
				),
			),
		);
	}

	/**
	 * Breakdown grouped by the user-defined project status.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_breakdown( ReportPeriod $period, array $filters ) {
		$statuses = ProjectStatusModel::query()->orderBy( 'position' )->get();

		$rows = array();
		foreach ( $statuses as $status ) {
			$rows[ (int) $status->id ] = array(
				'key'   => (string) $status->id,
				'label' => (string) $status->name,
				'color' => $status->color ? (string) $status->color : self::COLOR_NEUTRAL,
				'count' => 0,
				'value' => 0.0,
				'share' => 0.0,
			);
		}

		$records     = $this->records_in_period( $period, $filters );
		$total_count = 0;

		foreach ( $records as $project ) {
			$status_id = (int) $project->status_id;
			if ( ! isset( $rows[ $status_id ] ) ) {
				$rows[ $status_id ] = array(
					'key'   => (string) $status_id,
					'label' => __( 'Unknown', 'doublescale' ),
					'color' => self::COLOR_NEUTRAL,
					'count' => 0,
					'value' => 0.0,
					'share' => 0.0,
				);
			}

			$rows[ $status_id ]['count']++;
			$rows[ $status_id ]['value'] += (float) $project->budget;
			$total_count++;
		}

		foreach ( $rows as $id => $row ) {
			// Projects are single global currency, so the map has at most one key.
			$rows[ $id ]['value_by_currency'] = $this->as_global_money( $row['value'] );
			$rows[ $id ]['value']             = $this->round_amount( $row['value'] );
			$rows[ $id ]['share']             = $this->ratio( $row['count'], $total_count );
		}

		return array(
			'type'    => 'status',
			'columns' => array(
				array( 'key' => 'label', 'label' => __( 'Status', 'doublescale' ), 'format' => 'text' ),
				array( 'key' => 'count', 'label' => __( 'Projects', 'doublescale' ), 'format' => 'number' ),
				array( 'key' => 'value', 'label' => __( 'Budget', 'doublescale' ), 'format' => 'currency' ),
				array( 'key' => 'share', 'label' => __( '% Share', 'doublescale' ), 'format' => 'percent' ),
			),
			'rows'    => array_values( $rows ),
		);
	}

	/**
	 * status_id => is_completed map, loaded once.
	 *
	 * @return array<int, bool>
	 */
	protected function completed_status_map() {
		if ( null === $this->completed_status_map ) {
			$this->completed_status_map = array();
			foreach ( ProjectStatusModel::query()->get() as $status ) {
				$this->completed_status_map[ (int) $status->id ] = (bool) $status->is_completed;
			}
		}

		return $this->completed_status_map;
	}

	/**
	 * @return int[] Status ids marked is_completed.
	 */
	protected function completed_status_ids() {
		return array_keys( array_filter( $this->completed_status_map() ) );
	}

	/**
	 * Active projects (status not completed), point-in-time.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<int, object>
	 */
	protected function active_projects( array $filters ) {
		$completed_ids = $this->completed_status_ids();

		$query = $this->base_query( $filters );
		if ( ! empty( $completed_ids ) ) {
			$query->whereNotIn( 'status_id', $completed_ids );
		}

		return $query->get()->all();
	}

	/**
	 * Overdue active projects: past due_date and not completed.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return int
	 */
	protected function overdue_count( array $filters ) {
		$completed_ids = $this->completed_status_ids();
		$today         = current_time( 'Y-m-d' );

		$query = $this->base_query( $filters )
			->whereNotNull( 'due_date' )
			->whereDate( 'due_date', '<', $today );

		if ( ! empty( $completed_ids ) ) {
			$query->whereNotIn( 'status_id', $completed_ids );
		}

		return (int) $query->count();
	}

	/**
	 * Completion events within a window, derived from the status-change audit
	 * trail rather than updated_at.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return array<int, array{project_id: int, completed_at: string, due_date: string|null}>
	 */
	protected function completions_in_window( ReportPeriod $period, array $filters, $use_previous ) {
		$completed_ids = $this->completed_status_ids();
		if ( empty( $completed_ids ) ) {
			return array();
		}

		$start = $use_previous ? $period->previous_start() : $period->current_start();
		$end   = $use_previous ? $period->previous_end() : $period->current_end();

		// Project ids visible under the owner/contact/status filters, so a rep
		// only ever sees completions for their own projects.
		$project_ids = $this->base_query( $filters )->pluck( 'id' )->all();
		if ( empty( $project_ids ) ) {
			return array();
		}
		$project_ids     = array_map( 'intval', $project_ids );
		$due_by_project  = $this->due_dates_for( $filters );

		// Associations tie an activity to a project; join to the activity rows in
		// the window and keep the latest completing change per project.
		$association_ids = ActivityAssociationModel::query()
			->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROJECT )
			->whereIn( 'entity_id', $project_ids )
			->pluck( 'activity_id', 'activity_id' )
			->all();

		if ( empty( $association_ids ) ) {
			return array();
		}

		$activities = ActivityModel::query()
			->where( 'activity_type', ActivityTypes::PROJECT_STATUS_CHANGED )
			->whereIn( 'id', array_values( $association_ids ) )
			->whereBetween( 'created_at', array( $start, $end ) )
			->orderBy( 'created_at' )
			->get();

		$associations = $this->association_project_map( array_values( $association_ids ) );

		$completions = array();
		foreach ( $activities as $activity ) {
			$data          = is_array( $activity->data ) ? $activity->data : array();
			$new_status_id = isset( $data['new_status_id'] ) ? (int) $data['new_status_id'] : 0;
			if ( ! in_array( $new_status_id, $completed_ids, true ) ) {
				continue;
			}

			$project_id = isset( $associations[ (int) $activity->id ] ) ? $associations[ (int) $activity->id ] : 0;
			if ( ! $project_id ) {
				continue;
			}

			// Latest completing change per project wins.
			$completions[ $project_id ] = array(
				'project_id'   => $project_id,
				'completed_at' => (string) $activity->created_at,
				'due_date'     => isset( $due_by_project[ $project_id ] ) ? $due_by_project[ $project_id ] : null,
			);
		}

		return array_values( $completions );
	}

	/**
	 * activity_id => project_id for the given activities.
	 *
	 * @param int[] $activity_ids Activity ids.
	 * @return array<int, int>
	 */
	protected function association_project_map( array $activity_ids ) {
		if ( empty( $activity_ids ) ) {
			return array();
		}

		$map  = array();
		$rows = ActivityAssociationModel::query()
			->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROJECT )
			->whereIn( 'activity_id', $activity_ids )
			->get();

		foreach ( $rows as $row ) {
			$map[ (int) $row->activity_id ] = (int) $row->entity_id;
		}

		return $map;
	}

	/**
	 * project_id => due_date for filtered projects.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<int, string|null>
	 */
	protected function due_dates_for( array $filters ) {
		$map = array();
		foreach ( $this->base_query( $filters )->get( array( 'id', 'due_date' ) ) as $project ) {
			$map[ (int) $project->id ] = $project->due_date ? (string) $project->due_date : null;
		}

		return $map;
	}

	/**
	 * Share of completions that landed on or before the due date.
	 *
	 * @param array<int, array{completed_at: string, due_date: string|null}> $completions Completions.
	 * @return float
	 */
	protected function on_time_rate( array $completions ) {
		$total   = 0;
		$on_time = 0;

		foreach ( $completions as $completion ) {
			if ( empty( $completion['due_date'] ) ) {
				continue;
			}
			$total++;
			if ( strtotime( $completion['completed_at'] ) <= strtotime( $completion['due_date'] . ' 23:59:59' ) ) {
				$on_time++;
			}
		}

		return $this->ratio( $on_time, $total );
	}

	/**
	 * @param iterable $records Projects.
	 * @return float
	 */
	protected function sum_budget( $records ) {
		$total = 0.0;
		foreach ( $records as $project ) {
			$total += (float) $project->budget;
		}

		return $this->round_amount( $total );
	}

	/**
	 * @param iterable $records Projects.
	 * @return float
	 */
	protected function average_budget( $records ) {
		$values = array();
		foreach ( $records as $project ) {
			$budget = (float) $project->budget;
			if ( $budget > 0 ) {
				$values[] = $budget;
			}
		}

		return $this->average( $values );
	}

	/**
	 * Mean effective progress across active projects.
	 *
	 * resolveProgress() can issue task-count queries per project in auto mode,
	 * so this is intentionally scoped to the already-loaded active set rather
	 * than every project in range.
	 *
	 * @param array<int, object> $active Active projects.
	 * @return float
	 */
	protected function average_progress( array $active ) {
		if ( empty( $active ) ) {
			return 0.0;
		}

		$sum = 0;
		foreach ( $active as $project ) {
			$sum += (int) $project->resolveProgress();
		}

		return round( $sum / count( $active ), 1 );
	}
}
