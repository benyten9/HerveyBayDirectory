<?php
/**
 * Task recurrence model — cron-driven schedule that clones a template task.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Tasks\Models
 */

namespace DoubleScale\Pro\Modules\Tasks\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskStatus;
use WPEloquent\Eloquent\Model;

/**
 * TaskRecurrenceModel class
 */
class TaskRecurrenceModel extends Model {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected $table = 'doublescale_task_recurrences';

	/**
	 * Primary key.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'template_task_id',
		'frequency',
		'interval_count',
		'weekdays',
		'month_day',
		'month_mode',
		'year_month',
		'repeat_when_completed',
		'status_id',
		'create_new_on_repeat',
		'time',
		'timezone',
		'is_active',
		'last_run_at',
		'next_run_at',
	);

	/**
	 * Attribute casts.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'interval_count' => 'integer',
		'month_day'      => 'integer',
		'year_month'            => 'integer',
		'repeat_when_completed' => 'boolean',
		'status_id'              => 'integer',
		'create_new_on_repeat'  => 'boolean',
		'is_active'             => 'boolean',
	);

	/**
	 * Timestamps.
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Validation rules.
	 *
	 * @var array<string, string>
	 */
	public $rules = array(
		'template_task_id' => 'required|integer',
		'frequency'        => 'required|string|in:day,week,month,year',
		'interval_count'   => 'required|integer|min:1',
		'month_day'        => 'nullable|integer|min:1|max:31',
		'time'             => 'nullable|date_format:H:i:s',
		'timezone'         => 'nullable|string|max:64',
		'next_run_at'      => 'required|date',
	);

	/**
	 * Template task that defines the series.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function templateTask() {
		return $this->belongsTo( TaskModel::class, 'template_task_id', 'id' );
	}

	/**
	 * Active recurrences due now or earlier.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeDue( $query ) {
		return $query->where( 'is_active', 1 )
			->where( 'next_run_at', '<=', current_time( 'mysql' ) );
	}

	/**
	 * Weekday list as integers 0 (Sun) through 6 (Sat).
	 *
	 * @return array<int, int>
	 */
	public function getWeekdaysArray(): array {
		if ( empty( $this->weekdays ) ) {
			return array();
		}

		$parts = array_map( 'intval', explode( ',', (string) $this->weekdays ) );

		return array_values(
			array_unique(
				array_filter(
					$parts,
					static function ( $day ) {
						return $day >= 0 && $day <= 6;
					}
				)
			)
		);
	}

	/**
	 * Persist weekday list from API array.
	 *
	 * @param array<int, int>|null $weekdays Weekday numbers.
	 * @return void
	 */
	public function setWeekdaysFromArray( $weekdays ): void {
		if ( empty( $weekdays ) || ! is_array( $weekdays ) ) {
			$this->weekdays = null;
			return;
		}

		$normalized = array_values(
			array_unique(
				array_map( 'intval', $weekdays )
			)
		);
		sort( $normalized );
		$this->weekdays = implode( ',', $normalized );
	}

	/**
	 * Resolve the timezone for recurrence math.
	 *
	 * @return \DateTimeZone
	 */
	public function resolveTimezone(): \DateTimeZone {
		$tz_string = $this->timezone ?: wp_timezone_string();

		try {
			return new \DateTimeZone( $tz_string );
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * Time-of-day string (H:i:s).
	 *
	 * @return string
	 */
	public function resolveTime(): string {
		if ( empty( $this->time ) ) {
			return '09:00:00';
		}

		$time = (string) $this->time;
		if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return $time . ':00';
		}

		return $time;
	}

	/**
	 * Compute the first next_run_at from a task due date and rule fields.
	 *
	 * @param string              $due_date Task due date (Y-m-d).
	 * @param array<string,mixed> $rule     frequency, interval_count, weekdays, month_day, time, timezone.
	 * @return string MySQL datetime (Y-m-d H:i:s).
	 */
	public static function compute_initial_next_run_at( string $due_date, array $rule ): string {
		$model = new self(
			array(
				'frequency'      => $rule['frequency'] ?? 'day',
				'interval_count' => (int) ( $rule['interval_count'] ?? 1 ),
				'month_day'      => isset( $rule['month_day'] ) ? (int) $rule['month_day'] : null,
				'month_mode'     => $rule['month_mode'] ?? null,
				'year_month'     => isset( $rule['year_month'] ) ? (int) $rule['year_month'] : null,
				'time'           => $rule['time'] ?? null,
				'timezone'       => $rule['timezone'] ?? null,
			)
		);
		// Accept weekdays as either an API array or an already-normalized CSV
		// string (the shape prepare_recurrence_data() stores). A raw string is
		// assigned to the attribute directly since getWeekdaysArray() parses it;
		// setWeekdaysFromArray() only accepts arrays and would drop a string.
		$weekdays = $rule['weekdays'] ?? null;
		if ( is_string( $weekdays ) ) {
			$model->weekdays = '' !== $weekdays ? $weekdays : null;
		} else {
			$model->setWeekdaysFromArray( $weekdays );
		}

		$tz   = $model->resolveTimezone();
		$time = $model->resolveTime();

		$anchor = new \DateTime( $due_date . ' ' . $time, $tz );
		$now    = new \DateTime( 'now', $tz );

		if ( $anchor > $now && $model->date_matches_recurrence( $anchor, $anchor ) ) {
			return $anchor->format( 'Y-m-d H:i:s' );
		}

		return $model->compute_next_run_at( $anchor->format( 'Y-m-d H:i:s' ), $anchor );
	}

	/**
	 * Advance from a slot to the next matching datetime.
	 *
	 * @param string                 $from         MySQL datetime the slot just ran (or anchor).
	 * @param \DateTime|null         $anchor_week  Week anchor for interval math (template due week).
	 * @return string MySQL datetime.
	 */
	public function compute_next_run_at( string $from, ?\DateTime $anchor_week = null ): string {
		$tz       = $this->resolveTimezone();
		$time     = $this->resolveTime();
		$from_dt  = new \DateTime( $from, $tz );
		$candidate = clone $from_dt;
		$candidate->modify( '+1 minute' );

		if ( null === $anchor_week ) {
			$anchor_week = clone $from_dt;
		}

		for ( $i = 0; $i < 3660; $i++ ) {
			$candidate = $this->apply_time_to_date( $candidate, $time, $tz );

			if ( $candidate > $from_dt && $this->date_matches_recurrence( $candidate, $anchor_week ) ) {
				return $candidate->format( 'Y-m-d H:i:s' );
			}

			$candidate->modify( '+1 day' );
			$candidate->setTime( 0, 0, 0 );
		}

		// Fallback: one day ahead (should never hit in practice).
		$fallback = clone $from_dt;
		$fallback->modify( '+1 day' );

		return $this->apply_time_to_date( $fallback, $time, $tz )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Whether a datetime satisfies this recurrence rule.
	 *
	 * @param \DateTime $date         Candidate run time.
	 * @param \DateTime $anchor_week  Anchor week for weekly interval math.
	 * @return bool
	 */
	public function date_matches_recurrence( \DateTime $date, \DateTime $anchor_week ): bool {
		$frequency = (string) $this->frequency;
		$interval  = max( 1, (int) $this->interval_count );

		switch ( $frequency ) {
			case 'week':
				$weekdays = $this->getWeekdaysArray();
				if ( empty( $weekdays ) ) {
					return false;
				}

				$dow = (int) $date->format( 'w' );
				if ( ! in_array( $dow, $weekdays, true ) ) {
					return false;
				}

				return $this->is_active_week( $date, $anchor_week, $interval );

			case 'month':
				$mode = (string) ( $this->month_mode ?: 'day' );
				if ( 'last' === $mode ) {
					if ( (int) $date->format( 'j' ) !== (int) $date->format( 't' ) ) {
						return false;
					}
				} elseif ( 'first' === $mode ) {
					if ( (int) $date->format( 'j' ) !== 1 ) {
						return false;
					}
				} else {
					$month_day = (int) ( $this->month_day ?? 1 );
					$clamped   = $this->clamp_month_day( $date, $month_day );
					if ( (int) $date->format( 'j' ) !== $clamped ) {
						return false;
					}
				}

				$anchor_month = new \DateTime( $anchor_week->format( 'Y-m-01' ), $date->getTimezone() );
				$date_month   = new \DateTime( $date->format( 'Y-m-01' ), $date->getTimezone() );
				$months_diff  = ( ( (int) $date_month->format( 'Y' ) - (int) $anchor_month->format( 'Y' ) ) * 12 )
					+ ( (int) $date_month->format( 'n' ) - (int) $anchor_month->format( 'n' ) );

				return $months_diff >= 0 && ( $months_diff % $interval ) === 0;

			case 'year':
				$mode = (string) ( $this->month_mode ?: 'day' );
				if ( 'first' === $mode ) {
					if ( (int) $date->format( 'n' ) !== 1 || (int) $date->format( 'j' ) !== 1 ) {
						return false;
					}
				} elseif ( 'last' === $mode ) {
					if ( (int) $date->format( 'n' ) !== 12 || (int) $date->format( 'j' ) !== 31 ) {
						return false;
					}
				} else {
					$year_month = (int) ( $this->year_month ?? 1 );
					if ( (int) $date->format( 'n' ) !== $year_month ) {
						return false;
					}
					$month_day = (int) ( $this->month_day ?? 1 );
					$clamped   = $this->clamp_month_day( $date, $month_day );
					if ( (int) $date->format( 'j' ) !== $clamped ) {
						return false;
					}
				}

				$anchor_year = (int) $anchor_week->format( 'Y' );
				$date_year   = (int) $date->format( 'Y' );
				$years_diff  = $date_year - $anchor_year;

				return $years_diff >= 0 && ( $years_diff % $interval ) === 0;

			case 'day':
			default:
				$anchor_day = new \DateTime( $anchor_week->format( 'Y-m-d' ), $date->getTimezone() );
				$days_diff  = (int) $anchor_day->diff( $date )->format( '%r%a' );

				return $days_diff >= 0 && ( $days_diff % $interval ) === 0;
		}
	}

	/**
	 * Whether the candidate week is an active interval week (weekly rules).
	 *
	 * @param \DateTime $date         Candidate date.
	 * @param \DateTime $anchor_week  Anchor datetime (template due date week).
	 * @param int       $interval     Every N weeks.
	 * @return bool
	 */
	private function is_active_week( \DateTime $date, \DateTime $anchor_week, int $interval ): bool {
		$start_of_week = (int) get_option( 'start_of_week', 1 );
		$date_week     = $this->week_start( $date, $start_of_week );
		$anchor_week_s = $this->week_start( $anchor_week, $start_of_week );
		$days_diff     = (int) $anchor_week_s->diff( $date_week )->format( '%r%a' );
		$weeks_diff    = (int) floor( $days_diff / 7 );

		return $weeks_diff >= 0 && ( $weeks_diff % $interval ) === 0;
	}

	/**
	 * Start-of-week DateTime aligned to WordPress start_of_week.
	 *
	 * @param \DateTime $date           Reference date.
	 * @param int       $start_of_week  0=Sun … 6=Sat.
	 * @return \DateTime
	 */
	private function week_start( \DateTime $date, int $start_of_week ): \DateTime {
		$week_start = clone $date;
		$week_start->setTime( 0, 0, 0 );
		$current_dow = (int) $week_start->format( 'w' );
		$delta       = ( $current_dow - $start_of_week + 7 ) % 7;
		if ( $delta > 0 ) {
			$week_start->modify( '-' . $delta . ' days' );
		}

		return $week_start;
	}

	/**
	 * Clamp desired day-of-month to the month's length.
	 *
	 * @param \DateTime $reference    A date in the target month.
	 * @param int       $day_of_month Desired day (1-31).
	 * @return int
	 */
	private function clamp_month_day( \DateTime $reference, int $day_of_month ): int {
		$last_day = (int) $reference->format( 't' );

		return min( max( 1, $day_of_month ), $last_day );
	}

	/**
	 * Apply time-of-day to a date, preserving timezone.
	 *
	 * @param \DateTime     $date Reference date.
	 * @param string        $time H:i:s.
	 * @param \DateTimeZone $tz   Timezone.
	 * @return \DateTime
	 */
	private function apply_time_to_date( \DateTime $date, string $time, \DateTimeZone $tz ): \DateTime {
		$parts = explode( ':', $time );
		$hour  = (int) ( $parts[0] ?? 9 );
		$min   = (int) ( $parts[1] ?? 0 );
		$sec   = (int) ( $parts[2] ?? 0 );

		$result = new \DateTime( $date->format( 'Y-m-d' ), $tz );
		$result->setTime( $hour, $min, $sec );

		return $result;
	}

	/**
	 * Build a monthly datetime with day-of-month clamping.
	 *
	 * @param \DateTime     $reference     A DateTime in the target month.
	 * @param int           $day_of_month  Desired day (1-31).
	 * @param string        $time          Time string.
	 * @param \DateTimeZone $timezone      Timezone.
	 * @return \DateTime
	 */
	public function build_monthly_datetime( \DateTime $reference, int $day_of_month, string $time, \DateTimeZone $timezone ): \DateTime {
		$last_day    = (int) $reference->format( 't' );
		$clamped_day = min( $day_of_month, $last_day );
		$date_string = $reference->format( 'Y-m-' ) . sprintf( '%02d', $clamped_day ) . ' ' . $time;

		return new \DateTime( $date_string, $timezone );
	}

	/**
	 * Whether spawn must wait for the template task to be marked completed.
	 *
	 * @return bool
	 */
	public function waitsForCompletion(): bool {
		return (bool) $this->repeat_when_completed;
	}

	/**
	 * Whether each repeat should spawn a new task copy.
	 *
	 * @return bool
	 */
	public function createsNewTaskOnRepeat(): bool {
		return ! isset( $this->create_new_on_repeat ) || (bool) $this->create_new_on_repeat;
	}

	/**
	 * Kanban stage for spawned occurrences (falls back to template stage).
	 *
	 * @param TaskModel $template Template task.
	 * @return int|null
	 */
	public function resolveOccurrenceStatusId( TaskModel $template ): ?int {
		if ( ! empty( $this->status_id ) ) {
			return (int) $this->status_id;
		}

		return ! empty( $template->status_id ) ? (int) $template->status_id : null;
	}

	/**
	 * @deprecated Use resolveOccurrenceStatusId().
	 */
	public function resolveOccurrenceStageId( TaskModel $template ): ?int {
		return $this->resolveOccurrenceStatusId( $template );
	}

	/**
	 * Whether the template task is completed.
	 *
	 * @param TaskModel $template Template task.
	 * @return bool
	 */
	public function isTemplateCompleted( TaskModel $template ): bool {
		return TaskStatus::COMPLETED === (string) $template->status;
	}

	/**
	 * Whether the scheduled run time has arrived (site time).
	 *
	 * @return bool
	 */
	public function isDue(): bool {
		if ( empty( $this->next_run_at ) ) {
			return false;
		}

		return strtotime( (string) $this->next_run_at ) <= current_time( 'timestamp' );
	}

	/**
	 * Whether a spawn is allowed right now for the given template.
	 *
	 * @param TaskModel $template Template task.
	 * @return bool
	 */
	public function canSpawnForTemplate( TaskModel $template ): bool {
		if ( ! $this->isDue() ) {
			return false;
		}

		if ( $this->waitsForCompletion() && ! $this->isTemplateCompleted( $template ) ) {
			return false;
		}

		return true;
	}
}
