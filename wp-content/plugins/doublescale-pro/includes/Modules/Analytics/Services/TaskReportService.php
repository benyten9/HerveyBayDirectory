<?php
/**
 * Task report service.
 *
 * Count-based (no money). Completions bucket on the real `completed_at` column —
 * unlike projects, no activity-trail derivation is needed. Contact filtering is
 * polymorphic (`entity_type`/`entity_id`), so apply_contact_scope() is overridden.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Services
 */

namespace DoubleScale\Pro\Modules\Analytics\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Constants\TaskPriority;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Core\Constants\TaskType;
use DoubleScale\Pro\Modules\Analytics\Services\Support\ReportPeriod;
use DoubleScale\Pro\Modules\Analytics\Support\EntityReportDescriptor;

/**
 * Aggregates task KPIs, trend, and status/priority/type breakdowns.
 */
class TaskReportService extends EntityReportService {

	/**
	 * @return string
	 */
	protected function entity_key() {
		return EntityReportDescriptor::TASKS;
	}

	/**
	 * Tasks link to contacts via polymorphic entity_type/entity_id, not contact_id.
	 *
	 * @param mixed    $query      Query builder.
	 * @param int|null $contact_id Contact id.
	 * @return void
	 */
	protected function apply_contact_scope( $query, $contact_id ) {
		if ( null !== $contact_id && $contact_id > 0 ) {
			$query->where( 'entity_type', TaskEntityType::CONTACT )
				->where( 'entity_id', $contact_id );
		}
	}

	/**
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_kpis( ReportPeriod $period, array $filters ) {
		$created      = $this->records_in_period( $period, $filters );
		$previous     = $this->records_in_previous_period( $period, $filters );
		$completed    = $this->completed_records( $period, $filters, false );
		$completed_prev = $this->completed_records( $period, $filters, true );

		$created_count   = $created->count();
		$previous_count  = $previous->count();
		$completed_count = $completed->count();
		$completed_prev_count = $completed_prev->count();

		return array(
			$this->kpi(
				'created',
				__( 'Tasks Created', 'doublescale' ),
				$created_count,
				$previous_count
			),
			$this->kpi(
				'completed',
				__( 'Completed', 'doublescale' ),
				$completed_count,
				$completed_prev_count
			),
			$this->kpi(
				'completion_rate',
				__( 'Completion Rate', 'doublescale' ),
				$this->ratio( $completed_count, $created_count ),
				$this->ratio( $completed_prev_count, $previous_count ),
				'percent'
			),
			$this->snapshot_kpi(
				'overdue',
				__( 'Overdue', 'doublescale' ),
				$this->overdue_count( $filters ),
				'number',
				true
			),
			$this->kpi(
				'on_time_rate',
				__( 'Completed On Time', 'doublescale' ),
				$this->on_time_rate( $completed ),
				$this->on_time_rate( $completed_prev ),
				'percent'
			),
			$this->kpi(
				'avg_days_to_complete',
				__( 'Avg Days to Complete', 'doublescale' ),
				$this->avg_days_to_complete( $completed ),
				$this->avg_days_to_complete( $completed_prev ),
				'days',
				true
			),
			$this->snapshot_kpi(
				'due_today',
				__( 'Due Today', 'doublescale' ),
				$this->due_today_count( $filters ),
				'number'
			),
			$this->snapshot_kpi(
				'due_this_week',
				__( 'Due This Week', 'doublescale' ),
				$this->due_this_week_count( $filters ),
				'number'
			),
		);
	}

	/**
	 * Two series: created (on created_at) and completed (on completed_at).
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_trend( ReportPeriod $period, array $filters ) {
		$created   = $this->records_in_period( $period, $filters );
		$completed = $this->completed_records( $period, $filters, false );

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
					array_values( $this->bucket_records( $period, $completed, 'completed_at' ) ),
					self::COLOR_POSITIVE
				),
			),
		);
	}

	/**
	 * Status breakdown with priority and type tables chained via secondary.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_breakdown( ReportPeriod $period, array $filters ) {
		$breakdown = parent::build_breakdown( $period, $filters );
		$records   = $this->records_in_period( $period, $filters );

		$priority                      = $this->build_attribute_breakdown(
			$records,
			'priority',
			__( 'By Priority', 'doublescale' ),
			__( 'Priority', 'doublescale' ),
			static function ( $value ) {
				return TaskPriority::get_label( $value );
			},
			static function ( $value ) {
				return TaskPriority::get_color( $value );
			},
			array_keys( TaskPriority::get_all() )
		);

		$type = $this->build_attribute_breakdown(
			$records,
			'task_type',
			__( 'By Type', 'doublescale' ),
			__( 'Type', 'doublescale' ),
			static function ( $value ) {
				return TaskType::get_label( $value );
			},
			static function ( $value ) {
				return TaskType::get_color( $value );
			},
			array_keys( TaskType::get_all() )
		);

		$priority['secondary']          = $type;
		$breakdown['secondary']         = $priority;

		return $breakdown;
	}

	/**
	 * Group created-in-period records by a string attribute.
	 *
	 * @param iterable $records       Task models.
	 * @param string   $attribute     Column name.
	 * @param string   $title         Table title.
	 * @param string   $label_header  First column header.
	 * @param callable $label_for     value => label.
	 * @param callable $color_for     value => hex colour.
	 * @param string[] $seed_values   Pre-seed these keys so empty periods still list them.
	 * @return array<string, mixed>
	 */
	protected function build_attribute_breakdown( $records, $attribute, $title, $label_header, $label_for, $color_for, array $seed_values ) {
		$rows = array();
		foreach ( $seed_values as $value ) {
			$rows[ $value ] = $this->new_breakdown_row( $value, (string) $label_for( $value ), (string) $color_for( $value ) );
		}

		$total_count = 0;
		foreach ( $records as $record ) {
			$key = (string) $record->{$attribute};
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = $this->new_breakdown_row( $key, (string) $label_for( $key ), (string) $color_for( $key ) );
			}
			$rows[ $key ]['count']++;
			$total_count++;
		}

		foreach ( $rows as $key => $row ) {
			$rows[ $key ] = $this->finalize_breakdown_row( $row, $total_count );
		}

		return array(
			'type'    => $attribute,
			'title'   => $title,
			'columns' => array(
				array( 'key' => 'label', 'label' => $label_header, 'format' => 'text' ),
				array( 'key' => 'count', 'label' => __( 'Count', 'doublescale' ), 'format' => 'number' ),
				array( 'key' => 'share', 'label' => __( '% Share', 'doublescale' ), 'format' => 'percent' ),
			),
			'rows'    => array_values( $rows ),
		);
	}

	/**
	 * Tasks completed inside the current or previous window.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return mixed Eloquent collection.
	 */
	protected function completed_records( ReportPeriod $period, array $filters, $use_previous ) {
		$start = $use_previous ? $period->previous_start() : $period->current_start();
		$end   = $use_previous ? $period->previous_end() : $period->current_end();

		return $this->apply_date_scope( $this->base_query( $filters ), $start, $end, 'completed_at' )
			->whereNotNull( 'completed_at' )
			->where( 'status', TaskStatus::COMPLETED )
			->get();
	}

	/**
	 * Pending tasks past their due date (point-in-time).
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return int
	 */
	protected function overdue_count( array $filters ) {
		$today = current_time( 'Y-m-d' );

		return (int) $this->base_query( $filters )
			->where( 'status', TaskStatus::PENDING )
			->whereNotNull( 'due_date' )
			->whereDate( 'due_date', '<', $today )
			->count();
	}

	/**
	 * Pending tasks due today (point-in-time).
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return int
	 */
	protected function due_today_count( array $filters ) {
		$today = current_time( 'Y-m-d' );

		return (int) $this->base_query( $filters )
			->where( 'status', TaskStatus::PENDING )
			->whereDate( 'due_date', $today )
			->count();
	}

	/**
	 * Pending tasks due today through today+6 days inclusive (point-in-time).
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return int
	 */
	protected function due_this_week_count( array $filters ) {
		$today    = current_time( 'Y-m-d' );
		$deadline = gmdate( 'Y-m-d', strtotime( '+6 days', strtotime( $today ) ) );

		return (int) $this->base_query( $filters )
			->where( 'status', TaskStatus::PENDING )
			->whereNotNull( 'due_date' )
			->whereBetween( 'due_date', array( $today, $deadline ) )
			->count();
	}

	/**
	 * Share of completions that landed on or before the due date.
	 * Skips records with a null due_date (same rule as projects).
	 *
	 * @param iterable $completions Completed task models.
	 * @return float
	 */
	protected function on_time_rate( $completions ) {
		$total   = 0;
		$on_time = 0;

		foreach ( $completions as $task ) {
			if ( empty( $task->due_date ) ) {
				continue;
			}
			$total++;
			if ( strtotime( (string) $task->completed_at ) <= strtotime( (string) $task->due_date . ' 23:59:59' ) ) {
				$on_time++;
			}
		}

		return $this->ratio( $on_time, $total );
	}

	/**
	 * Mean whole days between created_at and completed_at.
	 *
	 * @param iterable $completions Completed task models.
	 * @return float
	 */
	protected function avg_days_to_complete( $completions ) {
		$spans = array();

		foreach ( $completions as $task ) {
			$days = $this->days_between( $task->created_at, $task->completed_at );
			if ( null !== $days ) {
				$spans[] = $days;
			}
		}

		return $this->average( $spans );
	}

	/**
	 * @param string $status Status value.
	 * @return string
	 */
	protected function status_color( $status ) {
		if ( TaskStatus::COMPLETED === $status ) {
			return self::COLOR_POSITIVE;
		}

		return self::COLOR_NEUTRAL;
	}
}
