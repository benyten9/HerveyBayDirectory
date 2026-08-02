<?php
/**
 * Contract report service.
 *
 * Contracts are the reference implementation for entity reports: a plain enum
 * status, a real amount column (contract_value, not total), a currency column,
 * and the is_trash filter — every base-class mechanism, none of the harder
 * specialisations.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Services
 */

namespace DoubleScale\Pro\Modules\Analytics\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Analytics\Services\Support\ReportPeriod;
use DoubleScale\Pro\Modules\Analytics\Support\EntityReportDescriptor;
use DoubleScale\Pro\Modules\Contracts\Constants\ContractStatus;

/**
 * Aggregates contract KPIs, trend, and breakdowns.
 */
class ContractReportService extends EntityReportService {

	/**
	 * Window used by the "expiring soon" cards.
	 */
	const EXPIRING_WINDOW_DAYS = 30;

	/**
	 * @return string
	 */
	protected function entity_key() {
		return EntityReportDescriptor::CONTRACTS;
	}

	/**
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_kpis( ReportPeriod $period, array $filters ) {
		$created  = $this->records_in_period( $period, $filters );
		$previous = $this->records_in_previous_period( $period, $filters );

		$sent_now      = $this->records_by_date_column( $period, $filters, 'sent_at', false );
		$sent_previous = $this->records_by_date_column( $period, $filters, 'sent_at', true );

		$signed_now      = $this->records_by_date_column( $period, $filters, 'signed_at', false );
		$signed_previous = $this->records_by_date_column( $period, $filters, 'signed_at', true );

		$sent_count       = $this->count_records( $sent_now, $filters );
		$signed_count     = $this->count_records( $signed_now, $filters );
		$sent_prev_count  = $this->count_records( $sent_previous, $filters );
		$signed_prev_count = $this->count_records( $signed_previous, $filters );

		$expiring = $this->expiring_soon( $filters );

		return array(
			$this->kpi(
				'created',
				__( 'Contracts Created', 'doublescale' ),
				$this->count_records( $created, $filters ),
				$this->count_records( $previous, $filters )
			),
			$this->kpi(
				'sent',
				__( 'Sent', 'doublescale' ),
				$sent_count,
				$sent_prev_count
			),
			$this->kpi(
				'signed',
				__( 'Signed', 'doublescale' ),
				$signed_count,
				$signed_prev_count
			),
			$this->kpi(
				'signed_rate',
				__( 'Signed Rate', 'doublescale' ),
				$this->ratio( $signed_count, $sent_count ),
				$this->ratio( $signed_prev_count, $sent_prev_count ),
				'percent'
			),
			$this->money_kpi(
				'new_contract_value',
				__( 'New Contract Value', 'doublescale' ),
				$this->sum_amount_by_currency( $signed_now, $filters ),
				$this->sum_amount_by_currency( $signed_previous, $filters )
			),
			$this->money_kpi(
				'avg_contract_value',
				__( 'Avg Contract Value', 'doublescale' ),
				$this->average_amount_by_currency( $signed_now, $filters ),
				$this->average_amount_by_currency( $signed_previous, $filters )
			),
			$this->kpi(
				'avg_days_to_sign',
				__( 'Avg Days to Sign', 'doublescale' ),
				$this->average_days_to_sign( $signed_now, $filters ),
				$this->average_days_to_sign( $signed_previous, $filters ),
				'days',
				true
			),
			$this->snapshot_money_kpi(
				'active_contract_value',
				__( 'Active Contract Value', 'doublescale' ),
				$this->active_contract_value_by_currency( $filters )
			),
			$this->snapshot_kpi(
				'expiring_soon_count',
				/* translators: %d: number of days. */
				sprintf( __( 'Expiring in %d Days', 'doublescale' ), self::EXPIRING_WINDOW_DAYS ),
				$expiring['count'],
				'number',
				true
			),
			$this->snapshot_money_kpi(
				'expiring_soon_value',
				/* translators: %d: number of days. */
				sprintf( __( 'Value Expiring in %d Days', 'doublescale' ), self::EXPIRING_WINDOW_DAYS ),
				$expiring['value_by_currency'],
				true
			),
		);
	}

	/**
	 * Trend: created / sent / signed per bucket.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_trend( ReportPeriod $period, array $filters ) {
		$buckets  = $period->buckets();
		$wanted   = $this->wanted_currencies( $filters );

		$created = $this->filter_by_currency( $this->records_in_period( $period, $filters ), $wanted );
		$sent    = $this->filter_by_currency( $this->records_by_date_column( $period, $filters, 'sent_at', false ), $wanted );
		$signed  = $this->filter_by_currency( $this->records_by_date_column( $period, $filters, 'signed_at', false ), $wanted );

		return array(
			'labels' => $buckets,
			'series' => array(
				$this->series(
					'created',
					__( 'Created', 'doublescale' ),
					array_values( $this->bucket_records( $period, $created, 'created_at' ) ),
					self::COLOR_NEUTRAL
				),
				$this->series(
					'sent',
					__( 'Sent', 'doublescale' ),
					array_values( $this->bucket_records( $period, $sent, 'sent_at' ) ),
					self::COLOR_PRIMARY
				),
				$this->series(
					'signed',
					__( 'Signed', 'doublescale' ),
					array_values( $this->bucket_records( $period, $signed, 'signed_at' ) ),
					self::COLOR_POSITIVE
				),
			),
		);
	}

	/**
	 * Status breakdown plus a top-N table by contract type.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_breakdown( ReportPeriod $period, array $filters ) {
		$breakdown              = parent::build_breakdown( $period, $filters );
		$breakdown['secondary'] = $this->build_type_breakdown( $period, $filters );

		return $breakdown;
	}

	/**
	 * Top contract types by value in the period.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_type_breakdown( ReportPeriod $period, array $filters ) {
		$wanted  = $this->wanted_currencies( $filters );
		$records = $this->apply_date_scope(
			$this->base_query( $filters ),
			$period->current_start(),
			$period->current_end()
		)->with( 'type' )->get();

		$rows        = array();
		$total_count = 0;

		foreach ( $records as $record ) {
			if ( ! $this->currency_matches( $record, $wanted ) ) {
				continue;
			}

			$type  = $record->type;
			$key   = $type && isset( $type->id ) ? (string) $type->id : 'untyped';
			$label = $type && ! empty( $type->name )
				? (string) $type->name
				: __( 'Untyped', 'doublescale' );

			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = $this->new_breakdown_row( $key, $label, self::COLOR_PRIMARY );
			}

			$rows[ $key ]['count']++;
			$this->add_row_amount( $rows[ $key ], $record, 'contract_value' );
			$total_count++;
		}

		foreach ( $rows as $key => $row ) {
			$rows[ $key ] = $this->finalize_breakdown_row( $row, $total_count );
		}

		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);

		return array(
			'type'    => 'contract_type',
			'title'   => __( 'By Contract Type', 'doublescale' ),
			'columns' => array(
				array(
					'key'    => 'label',
					'label'  => __( 'Contract Type', 'doublescale' ),
					'format' => 'text',
				),
				array(
					'key'    => 'count',
					'label'  => __( 'Count', 'doublescale' ),
					'format' => 'number',
				),
				array(
					'key'    => 'value',
					'label'  => __( 'Value', 'doublescale' ),
					'format' => 'currency',
				),
				array(
					'key'    => 'share',
					'label'  => __( '% Share', 'doublescale' ),
					'format' => 'percent',
				),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Records whose given datetime column falls in the current or previous window.
	 *
	 * @param ReportPeriod         $period      Reporting period.
	 * @param array<string, mixed> $filters     Normalized filters.
	 * @param string               $column      Datetime column.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return mixed Eloquent collection.
	 */
	protected function records_by_date_column( ReportPeriod $period, array $filters, $column, $use_previous ) {
		$start = $use_previous ? $period->previous_start() : $period->current_start();
		$end   = $use_previous ? $period->previous_end() : $period->current_end();

		return $this->apply_date_scope( $this->base_query( $filters ), $start, $end, $column )
			->whereNotNull( $column )
			->get();
	}

	/**
	 * Point-in-time value of active contracts, grouped by currency.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, float>
	 */
	protected function active_contract_value_by_currency( array $filters ) {
		$records = $this->base_query( $filters )
			->where( 'status', ContractStatus::ACTIVE )
			->get();

		return $this->sum_amount_by_currency( $records, $filters );
	}

	/**
	 * Active contracts ending within the expiring window.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array{count: int, value_by_currency: array<string, float>}
	 */
	protected function expiring_soon( array $filters ) {
		$today    = current_time( 'Y-m-d' );
		$deadline = gmdate( 'Y-m-d', strtotime( '+' . self::EXPIRING_WINDOW_DAYS . ' days', strtotime( $today ) ) );

		$records = $this->base_query( $filters )
			->where( 'status', ContractStatus::ACTIVE )
			->whereNotNull( 'end_date' )
			->whereBetween( 'end_date', array( $today, $deadline ) )
			->get();

		return array(
			'count'             => $this->count_records( $records, $filters ),
			'value_by_currency' => $this->sum_amount_by_currency( $records, $filters ),
		);
	}

	/**
	 * Mean contract value across records.
	 *
	 * @param iterable             $records Model instances.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return float
	 */
	protected function average_amount( $records, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );
		$values = array();

		foreach ( $records as $record ) {
			if ( ! $this->currency_matches( $record, $wanted ) ) {
				continue;
			}
			$values[] = (float) $record->contract_value;
		}

		return $this->average( $values );
	}

	/**
	 * Mean days between sent_at and signed_at.
	 *
	 * @param iterable             $records Model instances.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return float
	 */
	protected function average_days_to_sign( $records, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );
		$spans  = array();

		foreach ( $records as $record ) {
			if ( ! $this->currency_matches( $record, $wanted ) ) {
				continue;
			}

			$days = $this->days_between( $record->sent_at, $record->signed_at );
			if ( null !== $days ) {
				$spans[] = $days;
			}
		}

		return $this->average( $spans );
	}

	/**
	 * @param iterable $records Model instances.
	 * @param string[] $wanted  Currency filter.
	 * @return array<int, object>
	 */
	protected function filter_by_currency( $records, array $wanted ) {
		$kept = array();
		foreach ( $records as $record ) {
			if ( $this->currency_matches( $record, $wanted ) ) {
				$kept[] = $record;
			}
		}

		return $kept;
	}

	/**
	 * @param string $status Status value.
	 * @return string
	 */
	protected function status_color( $status ) {
		switch ( $status ) {
			case ContractStatus::SIGNED:
			case ContractStatus::ACTIVE:
				return self::COLOR_POSITIVE;
			case ContractStatus::EXPIRED:
				return self::COLOR_NEGATIVE;
			case ContractStatus::SENT:
				return self::COLOR_PRIMARY;
			case ContractStatus::DRAFT:
			default:
				return self::COLOR_NEUTRAL;
		}
	}
}
