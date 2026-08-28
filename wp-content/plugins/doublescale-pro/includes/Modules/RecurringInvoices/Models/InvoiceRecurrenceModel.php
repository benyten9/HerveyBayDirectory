<?php
/**
 * Invoice recurrence rule model.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use WPEloquent\Eloquent\Model;

/**
 * InvoiceRecurrenceModel class.
 */
class InvoiceRecurrenceModel extends Model {

	/**
	 * Supported interval units.
	 */
	public const UNITS = array( 'day', 'week', 'month', 'year' );

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_invoice_recurrences';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'template_invoice_id',
		'interval_value',
		'interval_unit',
		'total_cycles',
		'is_infinite',
		'cycles_done',
		'end_date',
		'auto_send',
		'require_paid',
		'next_run_at',
		'last_run_at',
		'is_active',
	);

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'template_invoice_id' => 'int',
		'interval_value'      => 'int',
		'total_cycles'        => 'int',
		'is_infinite'         => 'bool',
		'cycles_done'         => 'int',
		'auto_send'           => 'bool',
		'require_paid'        => 'bool',
		'is_active'           => 'bool',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Template invoice this rule copies from.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function templateInvoice() {
		return $this->belongsTo( InvoiceModel::class, 'template_invoice_id', 'id' );
	}

	/**
	 * Active rules whose slot has arrived.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeDue( $query ) {
		return $query->where( 'is_active', 1 )
			->whereNotNull( 'next_run_at' )
			->where( 'next_run_at', '<=', current_time( 'mysql' ) );
	}

	/**
	 * Normalize an interval unit to one of {@see self::UNITS}.
	 *
	 * @param mixed $unit Raw unit.
	 * @return string
	 */
	public static function normalize_unit( $unit ): string {
		$unit = is_string( $unit ) ? strtolower( trim( $unit ) ) : '';
		// Tolerate plural forms coming from the UI ("months").
		$unit = rtrim( $unit, 's' );

		return in_array( $unit, self::UNITS, true ) ? $unit : 'month';
	}

	/**
	 * Advance one interval from `$from`, keeping the day-of-month pinned to the
	 * anchor (the template invoice date).
	 *
	 * Anchoring matters: stepping relative to the previous *run* lets error
	 * accumulate — a rule that slips a day because the sweep ran late would
	 * keep that day forever. Perfex documents the same rule ("the date ... is
	 * calculated from the invoice date"), so an invoice dated the 17th always
	 * regenerates on the 17th.
	 *
	 * Month/year steps are computed from the anchor by multiplying the interval,
	 * so short months never drag the series backwards: Jan 31 monthly yields
	 * Feb 28, then Mar 31 (not Feb 28 → Mar 28).
	 *
	 * @param string         $from   MySQL datetime of the slot just filled.
	 * @param \DateTime|null $anchor Series anchor (template invoice date).
	 * @return string MySQL datetime.
	 */
	public function compute_next_run_at( string $from, ?\DateTime $anchor = null ): string {
		$tz      = wp_timezone();
		$from_dt = new \DateTime( $from, $tz );
		$unit    = self::normalize_unit( $this->interval_unit );
		$step    = max( 1, (int) $this->interval_value );

		if ( null === $anchor ) {
			$anchor = clone $from_dt;
		}

		$anchor = clone $anchor;
		$anchor->setTime(
			(int) $from_dt->format( 'H' ),
			(int) $from_dt->format( 'i' ),
			(int) $from_dt->format( 's' )
		);

		// Day/week intervals are exact durations — no calendar clamping needed.
		if ( 'day' === $unit || 'week' === $unit ) {
			$days      = ( 'week' === $unit ) ? $step * 7 : $step;
			$candidate = clone $from_dt;

			do {
				$candidate->modify( '+' . $days . ' days' );
			} while ( $candidate <= $from_dt );

			return $candidate->format( 'Y-m-d H:i:s' );
		}

		$months = ( 'year' === $unit ) ? $step * 12 : $step;

		// Walk whole multiples of the interval away from the anchor until we
		// pass `$from`, so every occurrence is anchor-relative, not chained.
		$elapsed = $this->months_between( $anchor, $from_dt );
		$n       = (int) floor( $elapsed / $months ) + 1;

		for ( $guard = 0; $guard < 1200; $guard++ ) {
			$candidate = $this->add_months_clamped( $anchor, $months * $n );

			if ( $candidate > $from_dt ) {
				return $candidate->format( 'Y-m-d H:i:s' );
			}

			++$n;
		}

		// Fallback: one interval past `$from` (should never be reached).
		return $this->add_months_clamped( $from_dt, $months )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Whole months between two datetimes (floor, never negative).
	 *
	 * @param \DateTime $start Earlier datetime.
	 * @param \DateTime $end   Later datetime.
	 * @return int
	 */
	private function months_between( \DateTime $start, \DateTime $end ): int {
		if ( $end <= $start ) {
			return 0;
		}

		$months = ( ( (int) $end->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12 )
			+ ( (int) $end->format( 'n' ) - (int) $start->format( 'n' ) );

		return max( 0, $months );
	}

	/**
	 * Add months to a date, clamping to the last day of short target months.
	 *
	 * `DateTime::modify( '+1 month' )` overflows (Jan 31 → Mar 3); clamping
	 * keeps the series on the intended month.
	 *
	 * @param \DateTime $date   Base date.
	 * @param int       $months Months to add.
	 * @return \DateTime
	 */
	private function add_months_clamped( \DateTime $date, int $months ): \DateTime {
		$result = clone $date;
		$day    = (int) $result->format( 'j' );

		$result->setDate( (int) $result->format( 'Y' ), (int) $result->format( 'n' ), 1 );
		$result->modify( '+' . $months . ' months' );

		$days_in_month = (int) $result->format( 't' );
		$result->setDate(
			(int) $result->format( 'Y' ),
			(int) $result->format( 'n' ),
			min( $day, $days_in_month )
		);

		return $result;
	}

	/**
	 * Whether this rule has produced everything it was asked for.
	 *
	 * Two independent limits; whichever lands first stops the series.
	 *
	 * @param string|null $next_run_at Candidate next slot (MySQL datetime).
	 * @return bool
	 */
	public function has_reached_limit( ?string $next_run_at = null ): bool {
		if ( ! $this->is_infinite && (int) $this->total_cycles > 0
			&& (int) $this->cycles_done >= (int) $this->total_cycles ) {
			return true;
		}

		if ( ! empty( $this->end_date ) ) {
			$slot = $next_run_at ?? $this->next_run_at;
			if ( ! empty( $slot ) ) {
				$slot_ts = strtotime( (string) $slot );
				$end_ts  = strtotime( (string) $this->end_date . ' 23:59:59' );
				if ( false !== $slot_ts && false !== $end_ts && $slot_ts > $end_ts ) {
					return true;
				}
			}
		}

		return false;
	}
}
