<?php
/**
 * Base entity report service.
 *
 * Owns the parts of a report that are identical across all five entities:
 * scoping (owner / trash / date / currency), trend bucketing, status breakdown,
 * and KPI card shaping. Per-entity work is limited to build_kpis() plus any
 * override a genuinely different query shape requires.
 *
 * Three invariants live here, each closing a class of bug that would otherwise
 * be re-introduced once per entity:
 *
 * 1. apply_owner_scope() is the ONLY place an owner column is compared. The
 *    REST layer speaks a universal `owner_id`; only the descriptor knows it
 *    means sale_agent_user_id on invoices. A hand-written owner where() clause
 *    anywhere else is a data leak waiting to happen.
 * 2. Currency is always resolved through CurrencyResolver before it is grouped
 *    or filtered — never read raw off the column.
 * 3. Every query starts at base_query(), which is what makes "contracts always
 *    filter is_trash" true by construction rather than by remembering.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Services
 */

namespace DoubleScale\Pro\Modules\Analytics\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Analytics\Services\Support\CurrencyResolver;
use DoubleScale\Pro\Modules\Analytics\Services\Support\ReportPeriod;
use DoubleScale\Pro\Modules\Analytics\Support\EntityReportDescriptor;

/**
 * Shared aggregation for entity reports.
 */
abstract class EntityReportService {

	/**
	 * Series colours shared with the deals reports.
	 */
	const COLOR_PRIMARY  = '#5B93C7';
	const COLOR_POSITIVE = '#4CAF50';
	const COLOR_NEGATIVE = '#E53935';
	const COLOR_NEUTRAL  = '#94A3B8';

	/**
	 * @var EntityReportDescriptor|null
	 */
	private $descriptor_cache = null;

	/**
	 * Entity key this service reports on.
	 *
	 * @return string
	 */
	abstract protected function entity_key();

	/**
	 * Per-entity KPI cards.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<int, array<string, mixed>>
	 */
	abstract protected function build_kpis( ReportPeriod $period, array $filters );

	/**
	 * @return EntityReportDescriptor
	 */
	public function descriptor() {
		if ( null === $this->descriptor_cache ) {
			$this->descriptor_cache = EntityReportDescriptor::for_key( $this->entity_key() );
		}

		return $this->descriptor_cache;
	}

	/**
	 * Full report payload: KPIs, trend, and breakdown in one call.
	 *
	 * One call rather than three endpoints: all three panels share the same
	 * filters, so splitting them would mean three round trips and three chances
	 * for the panels to disagree after a filter change.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	public function get_report( ReportPeriod $period, array $filters = array() ) {
		$descriptor = $this->descriptor();

		return array(
			'entity'     => $descriptor->key(),
			'label'      => $descriptor->label(),
			'period'     => $period->to_array(),
			'kpis'       => array_values( $this->build_kpis( $period, $filters ) ),
			'trend'      => $this->build_trend( $period, $filters ),
			'breakdown'  => $this->build_breakdown( $period, $filters ),
			'currencies' => $this->build_currencies( $filters ),
			'filters'    => $this->public_filters( $filters ),
		);
	}

	/**
	 * Base query with trash, owner, and currency-independent scoping applied.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return mixed Eloquent query builder.
	 */
	protected function base_query( array $filters = array() ) {
		$model_class = $this->descriptor()->model_class();
		$query       = $model_class::query();

		$this->apply_trash_scope( $query );
		$this->apply_owner_scope( $query, isset( $filters['owner_id'] ) ? (int) $filters['owner_id'] : null );
		$this->apply_contact_scope( $query, isset( $filters['contact_id'] ) ? (int) $filters['contact_id'] : null );
		$this->apply_status_scope( $query, isset( $filters['status'] ) ? $filters['status'] : null );

		return $query;
	}

	/**
	 * The one sanctioned owner comparison.
	 *
	 * @param mixed    $query    Query builder.
	 * @param int|null $owner_id Resolved owner id.
	 * @return void
	 */
	protected function apply_owner_scope( $query, $owner_id ) {
		if ( null !== $owner_id && $owner_id > 0 ) {
			$query->where( $this->descriptor()->owner_column(), $owner_id );
		}
	}

	/**
	 * Exclude trashed records. No-op unless the entity has a trash flag.
	 *
	 * @param mixed $query Query builder.
	 * @return void
	 */
	protected function apply_trash_scope( $query ) {
		if ( $this->descriptor()->has_trash_flag() ) {
			$query->where( 'is_trash', 0 );
		}
	}

	/**
	 * @param mixed    $query      Query builder.
	 * @param int|null $contact_id Contact id.
	 * @return void
	 */
	protected function apply_contact_scope( $query, $contact_id ) {
		if ( null !== $contact_id && $contact_id > 0 ) {
			$query->where( 'contact_id', $contact_id );
		}
	}

	/**
	 * @param mixed       $query  Query builder.
	 * @param string|null $status Status filter.
	 * @return void
	 */
	protected function apply_status_scope( $query, $status ) {
		$descriptor = $this->descriptor();

		if ( null === $status || '' === $status ) {
			return;
		}

		if ( $descriptor->has_enum_status() ) {
			if ( $descriptor->is_valid_status( $status ) ) {
				$query->where( $descriptor->status_column(), $status );
			}
			return;
		}

		// Relation-backed status (projects) filters on the numeric FK.
		$status_id = absint( $status );
		if ( $status_id > 0 ) {
			$query->where( $descriptor->status_column(), $status_id );
		}
	}

	/**
	 * Constrain a query to a date window.
	 *
	 * @param mixed       $query  Query builder.
	 * @param string      $start  Y-m-d H:i:s.
	 * @param string      $end    Y-m-d H:i:s.
	 * @param string|null $column Defaults to the descriptor's date column.
	 * @return mixed
	 */
	protected function apply_date_scope( $query, $start, $end, $column = null ) {
		$column = $column ? $column : $this->descriptor()->date_column();

		return $query->whereBetween( $column, array( $start, $end ) );
	}

	/**
	 * Records inside the current window.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @param string|null          $column  Date column override.
	 * @return mixed Eloquent collection.
	 */
	protected function records_in_period( ReportPeriod $period, array $filters, $column = null ) {
		$query = $this->apply_date_scope(
			$this->base_query( $filters ),
			$period->current_start(),
			$period->current_end(),
			$column
		);

		return $query->get();
	}

	/**
	 * Records inside the comparison window.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @param string|null          $column  Date column override.
	 * @return mixed Eloquent collection.
	 */
	protected function records_in_previous_period( ReportPeriod $period, array $filters, $column = null ) {
		$query = $this->apply_date_scope(
			$this->base_query( $filters ),
			$period->previous_start(),
			$period->previous_end(),
			$column
		);

		return $query->get();
	}

	/**
	 * Default trend: record count and amount per bucket.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_trend( ReportPeriod $period, array $filters ) {
		$descriptor = $this->descriptor();
		$buckets    = $period->buckets();
		$counts     = array_fill_keys( $buckets, 0 );
		$amounts    = array_fill_keys( $buckets, 0.0 );

		$currencies    = $this->wanted_currencies( $filters );
		$amount_column = $descriptor->amount_column();

		foreach ( $this->records_in_period( $period, $filters ) as $record ) {
			if ( ! $this->currency_matches( $record, $currencies ) ) {
				continue;
			}

			$bucket = $period->bucket_for( $record->{$descriptor->date_column()} );
			if ( ! array_key_exists( $bucket, $counts ) ) {
				continue;
			}

			$counts[ $bucket ]++;
			if ( $amount_column ) {
				$amounts[ $bucket ] += (float) $record->{$amount_column};
			}
		}

		$series = array(
			$this->series( 'count', __( 'Count', 'doublescale' ), array_values( $counts ), self::COLOR_PRIMARY ),
		);

		if ( $amount_column ) {
			$series[] = $this->series(
				'value',
				__( 'Value', 'doublescale' ),
				array_map( array( $this, 'round_amount' ), array_values( $amounts ) ),
				self::COLOR_POSITIVE
			);
		}

		return array(
			'labels' => $buckets,
			'series' => $series,
		);
	}

	/**
	 * Default breakdown: grouped by status.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_breakdown( ReportPeriod $period, array $filters ) {
		$descriptor    = $this->descriptor();
		$amount_column = $descriptor->amount_column();
		$currencies    = $this->wanted_currencies( $filters );

		$rows = array();
		foreach ( $descriptor->status_values() as $status ) {
			$rows[ $status ] = $this->new_breakdown_row(
				$status,
				$descriptor->status_label( $status ),
				$this->status_color( $status )
			);
		}

		$total_count = 0;
		foreach ( $this->records_in_period( $period, $filters ) as $record ) {
			if ( ! $this->currency_matches( $record, $currencies ) ) {
				continue;
			}

			$status = (string) $record->{$descriptor->status_column()};
			if ( ! isset( $rows[ $status ] ) ) {
				$rows[ $status ] = $this->new_breakdown_row(
					$status,
					$descriptor->status_label( $status ),
					self::COLOR_NEUTRAL
				);
			}

			$rows[ $status ]['count']++;
			$total_count++;
			if ( $amount_column ) {
				$this->add_row_amount( $rows[ $status ], $record, $amount_column );
			}
		}

		foreach ( $rows as $status => $row ) {
			$rows[ $status ] = $this->finalize_breakdown_row( $row, $total_count );
		}

		return array(
			'type'    => 'status',
			'columns' => $this->breakdown_columns(),
			'rows'    => array_values( $rows ),
		);
	}

	/**
	 * Blank breakdown row with a per-currency accumulator.
	 *
	 * @param string $key   Row key.
	 * @param string $label Row label.
	 * @param string $color Hex colour.
	 * @return array<string, mixed>
	 */
	protected function new_breakdown_row( $key, $label, $color ) {
		return array(
			'key'               => $key,
			'label'             => $label,
			'color'             => $color,
			'count'             => 0,
			'value'             => 0.0,
			'value_by_currency' => array(),
			'share'             => 0.0,
		);
	}

	/**
	 * Add a record's amount to a breakdown row, grouped by resolved currency.
	 *
	 * @param array<string, mixed> $row    Row (by reference).
	 * @param object               $record Model instance.
	 * @param string               $column Amount column.
	 * @return void
	 */
	protected function add_row_amount( array &$row, $record, $column ) {
		$amount = (float) $record->{$column};
		$row['value'] += $amount;

		$currency = $this->descriptor()->has_currency()
			? CurrencyResolver::resolve( $record )
			: CurrencyResolver::global_currency();

		if ( ! isset( $row['value_by_currency'][ $currency ] ) ) {
			$row['value_by_currency'][ $currency ] = 0.0;
		}
		$row['value_by_currency'][ $currency ] += $amount;
	}

	/**
	 * Round a breakdown row and compute its share.
	 *
	 * @param array<string, mixed> $row         Row.
	 * @param int                  $total_count Denominator for the share.
	 * @return array<string, mixed>
	 */
	protected function finalize_breakdown_row( array $row, $total_count ) {
		$row['value']             = $this->round_amount( $row['value'] );
		$row['value_by_currency'] = CurrencyResolver::round_map( $row['value_by_currency'] );
		$row['share']             = $total_count > 0
			? round( ( $row['count'] / $total_count ) * 100, 2 )
			: 0.0;

		return $row;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	protected function breakdown_columns() {
		$columns = array(
			array(
				'key'    => 'label',
				'label'  => __( 'Status', 'doublescale' ),
				'format' => 'text',
			),
			array(
				'key'    => 'count',
				'label'  => __( 'Count', 'doublescale' ),
				'format' => 'number',
			),
		);

		if ( $this->descriptor()->amount_column() ) {
			$columns[] = array(
				'key'    => 'value',
				'label'  => __( 'Value', 'doublescale' ),
				'format' => 'currency',
			);
		}

		$columns[] = array(
			'key'    => 'share',
			'label'  => __( '% Share', 'doublescale' ),
			'format' => 'percent',
		);

		return $columns;
	}

	/**
	 * Currency metadata for the response.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_currencies( array $filters ) {
		$descriptor = $this->descriptor();

		if ( ! $descriptor->has_currency() ) {
			$global = CurrencyResolver::global_currency();

			return array(
				'available' => array( $global ),
				'selected'  => array(),
				'display'   => $global,
			);
		}

		$available = CurrencyResolver::available_for( $descriptor->model_class() );
		$selected  = $this->wanted_currencies( $filters );

		$display = ! empty( $selected ) ? $selected[0] : CurrencyResolver::global_currency();

		return array(
			'available' => $available,
			'selected'  => $selected,
			'display'   => $display,
		);
	}

	/**
	 * Normalized currency filter for this request.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return string[]
	 */
	protected function wanted_currencies( array $filters ) {
		if ( ! $this->descriptor()->has_currency() ) {
			return array();
		}

		return CurrencyResolver::normalize_list( $filters['currencies'] ?? array() );
	}

	/**
	 * Currency gate for a record. Entities without a currency column always pass.
	 *
	 * @param object   $record Model instance.
	 * @param string[] $wanted Normalized currency codes.
	 * @return bool
	 */
	protected function currency_matches( $record, array $wanted ) {
		if ( empty( $wanted ) || ! $this->descriptor()->has_currency() ) {
			return true;
		}

		return CurrencyResolver::matches( $record, $wanted );
	}

	/**
	 * Shape a KPI card.
	 *
	 * `previous_value` ships alongside `change` because percentage_change()
	 * returns 0 when the previous value is zero, which is indistinguishable from
	 * a genuinely flat period — the UI needs the raw value to render "—" on a
	 * first-ever period instead of a misleading "0%".
	 *
	 * @param string $key              Stable metric key.
	 * @param string $label            Display label.
	 * @param float  $value            Current value.
	 * @param float  $previous         Previous value.
	 * @param string $format           currency|number|percent|days.
	 * @param bool   $negative_is_good Invert the colour (e.g. overdue count).
	 * @param array  $extra            Extra fields (e.g. as_of).
	 * @return array<string, mixed>
	 */
	protected function kpi( $key, $label, $value, $previous = 0.0, $format = 'number', $negative_is_good = false, array $extra = array() ) {
		$change = ReportPeriod::percentage_change( $value, $previous );
		$rising = $change >= 0;

		return array_merge(
			array(
				'key'            => $key,
				'label'          => $label,
				'value'          => is_float( $value ) ? $this->round_amount( $value ) : $value,
				'previous_value' => is_float( $previous ) ? $this->round_amount( $previous ) : $previous,
				'change'         => $change,
				'format'         => $format,
				'isArrow'        => $rising,
				'isColor'        => $negative_is_good ? ! $rising : $rising,
			),
			$extra
		);
	}

	/**
	 * Shape a MONEY KPI card that carries a per-currency breakdown.
	 *
	 * `value` stays as the summed scalar for back-compat and for the change
	 * calculation, but `value_by_currency` / `previous_by_currency` are what the
	 * UI renders ("$X · R$Y") so mixed currencies are never added together.
	 *
	 * @param string               $key              Stable metric key.
	 * @param string               $label            Display label.
	 * @param array<string, float> $by_currency      Current currency => amount.
	 * @param array<string, float> $previous_by_currency Previous currency => amount.
	 * @param bool                 $negative_is_good Invert the colour.
	 * @return array<string, mixed>
	 */
	protected function money_kpi( $key, $label, array $by_currency, array $previous_by_currency = array(), $negative_is_good = false ) {
		$current  = array_sum( $by_currency );
		$previous = array_sum( $previous_by_currency );

		$kpi = $this->kpi( $key, $label, (float) $current, (float) $previous, 'currency', $negative_is_good );

		$kpi['value_by_currency']    = CurrencyResolver::round_map( $by_currency );
		$kpi['previous_by_currency'] = CurrencyResolver::round_map( $previous_by_currency );

		return $kpi;
	}

	/**
	 * Shape a point-in-time MONEY snapshot KPI with a per-currency breakdown.
	 *
	 * @param string               $key              Stable metric key.
	 * @param string               $label            Display label.
	 * @param array<string, float> $by_currency      Currency => amount.
	 * @param bool                 $negative_is_good Invert the colour.
	 * @return array<string, mixed>
	 */
	protected function snapshot_money_kpi( $key, $label, array $by_currency, $negative_is_good = false ) {
		$kpi                      = $this->snapshot_kpi( $key, $label, (float) array_sum( $by_currency ), 'currency', $negative_is_good );
		$kpi['value_by_currency'] = CurrencyResolver::round_map( $by_currency );

		return $kpi;
	}

	/**
	 * Shape a point-in-time KPI card.
	 *
	 * Snapshot metrics (outstanding balance, active contract value) do not
	 * respect the date picker — they describe "now". They carry an `as_of`
	 * timestamp so the UI can label them rather than implying otherwise.
	 *
	 * @param string $key              Stable metric key.
	 * @param string $label            Display label.
	 * @param float  $value            Current value.
	 * @param string $format           currency|number|percent|days.
	 * @param bool   $negative_is_good Invert the colour.
	 * @return array<string, mixed>
	 */
	protected function snapshot_kpi( $key, $label, $value, $format = 'currency', $negative_is_good = false ) {
		return array(
			'key'            => $key,
			'label'          => $label,
			'value'          => is_float( $value ) ? $this->round_amount( $value ) : $value,
			'previous_value' => null,
			'change'         => null,
			'format'         => $format,
			'isArrow'        => false,
			'isColor'        => ! $negative_is_good,
			'as_of'          => current_time( 'mysql' ),
		);
	}

	/**
	 * @param string  $key    Series key.
	 * @param string  $label  Series label.
	 * @param array   $data   Values aligned to bucket labels.
	 * @param string  $color  Hex colour.
	 * @return array<string, mixed>
	 */
	protected function series( $key, $label, array $data, $color ) {
		return array(
			'key'   => $key,
			'label' => $label,
			'color' => $color,
			'data'  => array_values( $data ),
		);
	}

	/**
	 * Bucket a set of records into a trend series.
	 *
	 * @param ReportPeriod $period        Reporting period.
	 * @param iterable     $records       Model instances.
	 * @param string       $date_column   Column to bucket on.
	 * @param string|null  $amount_column Sum this column instead of counting.
	 * @return array<string, float> Bucket label => value.
	 */
	protected function bucket_records( ReportPeriod $period, $records, $date_column, $amount_column = null ) {
		$totals = array_fill_keys( $period->buckets(), 0.0 );

		foreach ( $records as $record ) {
			$raw = isset( $record->{$date_column} ) ? $record->{$date_column} : null;
			if ( empty( $raw ) ) {
				continue;
			}

			$bucket = $period->bucket_for( $raw );
			if ( ! array_key_exists( $bucket, $totals ) ) {
				continue;
			}

			$totals[ $bucket ] += $amount_column
				? (float) $record->{$amount_column}
				: 1.0;
		}

		return $totals;
	}

	/**
	 * Ratio as a percentage, guarding division by zero.
	 *
	 * @param float $numerator   Numerator.
	 * @param float $denominator Denominator.
	 * @return float
	 */
	protected function ratio( $numerator, $denominator ) {
		$denominator = (float) $denominator;
		if ( $denominator <= 0 ) {
			return 0.0;
		}

		return round( ( (float) $numerator / $denominator ) * 100, 2 );
	}

	/**
	 * Mean of a set of values, guarding empty input.
	 *
	 * @param float[] $values Values.
	 * @return float
	 */
	protected function average( array $values ) {
		$values = array_filter(
			$values,
			static function ( $value ) {
				return null !== $value;
			}
		);

		if ( empty( $values ) ) {
			return 0.0;
		}

		return $this->round_amount( array_sum( $values ) / count( $values ) );
	}

	/**
	 * Whole days between two datetimes, or null when either is missing.
	 *
	 * @param string|null $from Start datetime.
	 * @param string|null $to   End datetime.
	 * @return float|null
	 */
	protected function days_between( $from, $to ) {
		if ( empty( $from ) || empty( $to ) ) {
			return null;
		}

		$start = strtotime( (string) $from );
		$end   = strtotime( (string) $to );
		if ( ! $start || ! $end || $end < $start ) {
			return null;
		}

		return round( ( $end - $start ) / DAY_IN_SECONDS, 1 );
	}

	/**
	 * @param float $amount Raw amount.
	 * @return float
	 */
	protected function round_amount( $amount ) {
		return round( (float) $amount, 2 );
	}

	/**
	 * Sum an amount column across records honouring the currency filter.
	 *
	 * @param iterable             $records Model instances.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @param string|null          $column  Amount column override.
	 * @return float
	 */
	protected function sum_amount( $records, array $filters, $column = null ) {
		$column = $column ? $column : $this->descriptor()->amount_column();
		if ( ! $column ) {
			return 0.0;
		}

		$wanted = $this->wanted_currencies( $filters );
		$total  = 0.0;

		foreach ( $records as $record ) {
			if ( ! $this->currency_matches( $record, $wanted ) ) {
				continue;
			}
			$total += (float) $record->{$column};
		}

		return $this->round_amount( $total );
	}

	/**
	 * Sum an amount column grouped by RESOLVED currency.
	 *
	 * Money must never be collapsed across currencies into one scalar — a mixed
	 * "USD + BRL" total is meaningless. Every money KPI groups per currency and
	 * the frontend renders the map as "$X · R$Y".
	 *
	 * @param iterable             $records Model instances.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @param string|null          $column  Amount column override.
	 * @return array<string, float> Currency code => total.
	 */
	protected function sum_amount_by_currency( $records, array $filters, $column = null ) {
		$column = $column ? $column : $this->descriptor()->amount_column();
		if ( ! $column ) {
			return array();
		}

		// Projects have no currency column — everything is the global currency.
		if ( ! $this->descriptor()->has_currency() ) {
			$total = 0.0;
			foreach ( $records as $record ) {
				$total += (float) $record->{$column};
			}

			return 0.0 === $total
				? array()
				: array( CurrencyResolver::global_currency() => $this->round_amount( $total ) );
		}

		return CurrencyResolver::sum_by_currency( $records, $column, $this->wanted_currencies( $filters ) );
	}

	/**
	 * Mean of an amount column grouped by resolved currency.
	 *
	 * @param iterable             $records Model instances.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @param string|null          $column  Amount column override.
	 * @return array<string, float> Currency => mean.
	 */
	protected function average_amount_by_currency( $records, array $filters, $column = null ) {
		$column = $column ? $column : $this->descriptor()->amount_column();
		if ( ! $column ) {
			return array();
		}

		$wanted     = $this->wanted_currencies( $filters );
		$has_cur    = $this->descriptor()->has_currency();
		$sums       = array();
		$counts     = array();

		foreach ( $records as $record ) {
			if ( $has_cur && ! $this->currency_matches( $record, $wanted ) ) {
				continue;
			}
			$amount = (float) $record->{$column};
			if ( 0.0 === $amount ) {
				continue;
			}
			$currency = $has_cur
				? CurrencyResolver::resolve( $record )
				: CurrencyResolver::global_currency();
			if ( ! isset( $sums[ $currency ] ) ) {
				$sums[ $currency ]   = 0.0;
				$counts[ $currency ] = 0;
			}
			$sums[ $currency ] += $amount;
			$counts[ $currency ]++;
		}

		$means = array();
		foreach ( $sums as $currency => $sum ) {
			$means[ $currency ] = $counts[ $currency ] > 0 ? $sum / $counts[ $currency ] : 0.0;
		}

		return CurrencyResolver::round_map( $means );
	}

	/**
	 * Wrap a single amount in a global-currency map, for entities without a
	 * currency column (projects). Empty amounts produce an empty map.
	 *
	 * @param float $amount Amount.
	 * @return array<string, float>
	 */
	protected function as_global_money( $amount ) {
		$amount = (float) $amount;
		if ( 0.0 === $amount ) {
			return array();
		}

		return array( CurrencyResolver::global_currency() => $this->round_amount( $amount ) );
	}

	/**
	 * Count records honouring the currency filter.
	 *
	 * @param iterable             $records Model instances.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return int
	 */
	protected function count_records( $records, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );
		$count  = 0;

		foreach ( $records as $record ) {
			if ( $this->currency_matches( $record, $wanted ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Colour for a status value. Subclasses override for entity semantics.
	 *
	 * @param string $status Status value.
	 * @return string
	 */
	protected function status_color( $status ) {
		unset( $status );

		return self::COLOR_PRIMARY;
	}

	/**
	 * Filters echoed back to the client.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function public_filters( array $filters ) {
		return array(
			'owner_id'   => isset( $filters['owner_id'] ) ? (int) $filters['owner_id'] : null,
			'contact_id' => isset( $filters['contact_id'] ) ? (int) $filters['contact_id'] : null,
			'status'     => isset( $filters['status'] ) ? $filters['status'] : null,
			'currencies' => $this->wanted_currencies( $filters ),
		);
	}
}
