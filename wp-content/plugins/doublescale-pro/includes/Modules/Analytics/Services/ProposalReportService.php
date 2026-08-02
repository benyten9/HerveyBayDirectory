<?php
/**
 * Proposal report service.
 *
 * Proposals add two things over the contract template: a decided-only
 * acceptance rate (accepted vs accepted+declined, so the number does not swing
 * as still-open proposals pile up), and a conversion-to-invoice metric via the
 * invoice() hasOne relation.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Services
 */

namespace DoubleScale\Pro\Modules\Analytics\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Pro\Modules\Analytics\Services\Support\ReportPeriod;
use DoubleScale\Pro\Modules\Analytics\Support\EntityReportDescriptor;

/**
 * Aggregates proposal KPIs, trend, and status breakdown.
 */
class ProposalReportService extends EntityReportService {

	/**
	 * @return string
	 */
	protected function entity_key() {
		return EntityReportDescriptor::PROPOSALS;
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

		// Eager-load invoice for the conversion metric so we do not issue a
		// query per accepted proposal.
		$accepted_now      = $this->records_by_date_column( $period, $filters, 'accepted_at', false, array( 'invoice' ) );
		$accepted_previous = $this->records_by_date_column( $period, $filters, 'accepted_at', true, array( 'invoice' ) );

		$declined_now      = $this->records_by_date_column( $period, $filters, 'declined_at', false );
		$declined_previous = $this->records_by_date_column( $period, $filters, 'declined_at', true );

		$sent_count     = $this->count_records( $sent_now, $filters );
		$accepted_count = $this->count_records( $accepted_now, $filters );
		$declined_count = $this->count_records( $declined_now, $filters );

		$sent_prev     = $this->count_records( $sent_previous, $filters );
		$accepted_prev = $this->count_records( $accepted_previous, $filters );
		$declined_prev = $this->count_records( $declined_previous, $filters );

		// Awaiting decision: sent but neither accepted nor declined. Surfaced as
		// its own card so the population excluded from the acceptance-rate
		// denominator is visible rather than hidden.
		$awaiting = $this->awaiting_decision_count( $filters );

		$viewed_count = $this->count_viewed( $sent_now, $filters );

		return array(
			$this->kpi(
				'created',
				__( 'Proposals Created', 'doublescale' ),
				$this->count_records( $created, $filters ),
				$this->count_records( $previous, $filters )
			),
			$this->kpi(
				'sent',
				__( 'Sent', 'doublescale' ),
				$sent_count,
				$sent_prev
			),
			$this->kpi(
				'accepted',
				__( 'Accepted', 'doublescale' ),
				$accepted_count,
				$accepted_prev
			),
			$this->kpi(
				'acceptance_rate',
				__( 'Acceptance Rate', 'doublescale' ),
				$this->ratio( $accepted_count, $accepted_count + $declined_count ),
				$this->ratio( $accepted_prev, $accepted_prev + $declined_prev ),
				'percent'
			),
			$this->kpi(
				'declined',
				__( 'Declined', 'doublescale' ),
				$declined_count,
				$declined_prev,
				'number',
				true
			),
			$this->snapshot_kpi(
				'awaiting_decision',
				__( 'Awaiting Decision', 'doublescale' ),
				$awaiting,
				'number'
			),
			$this->money_kpi(
				'value_sent',
				__( 'Total Value Sent', 'doublescale' ),
				$this->sum_amount_by_currency( $sent_now, $filters ),
				$this->sum_amount_by_currency( $sent_previous, $filters )
			),
			$this->money_kpi(
				'avg_value',
				__( 'Avg Proposal Value', 'doublescale' ),
				$this->average_amount_by_currency( $sent_now, $filters ),
				$this->average_amount_by_currency( $sent_previous, $filters )
			),
			$this->kpi(
				'avg_days_to_decision',
				__( 'Avg Days to Decision', 'doublescale' ),
				$this->average_days_to_decision( $accepted_now, $declined_now, $filters ),
				$this->average_days_to_decision( $accepted_previous, $declined_previous, $filters ),
				'days',
				true
			),
			$this->kpi(
				'conversion_to_invoice',
				__( 'Conversion to Invoice', 'doublescale' ),
				$this->conversion_to_invoice_rate( $accepted_now, $filters ),
				$this->conversion_to_invoice_rate( $accepted_previous, $filters ),
				'percent'
			),
			$this->kpi(
				'view_rate',
				__( 'View Rate', 'doublescale' ),
				$this->ratio( $viewed_count, $sent_count ),
				$this->ratio( $this->count_viewed( $sent_previous, $filters ), $sent_prev ),
				'percent'
			),
		);
	}

	/**
	 * Trend: sent / accepted / declined per bucket.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_trend( ReportPeriod $period, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );

		$sent     = $this->filter_by_currency( $this->records_by_date_column( $period, $filters, 'sent_at', false ), $wanted );
		$accepted = $this->filter_by_currency( $this->records_by_date_column( $period, $filters, 'accepted_at', false ), $wanted );
		$declined = $this->filter_by_currency( $this->records_by_date_column( $period, $filters, 'declined_at', false ), $wanted );

		return array(
			'labels' => $period->buckets(),
			'series' => array(
				$this->series(
					'sent',
					__( 'Sent', 'doublescale' ),
					array_values( $this->bucket_records( $period, $sent, 'sent_at' ) ),
					self::COLOR_PRIMARY
				),
				$this->series(
					'accepted',
					__( 'Accepted', 'doublescale' ),
					array_values( $this->bucket_records( $period, $accepted, 'accepted_at' ) ),
					self::COLOR_POSITIVE
				),
				$this->series(
					'declined',
					__( 'Declined', 'doublescale' ),
					array_values( $this->bucket_records( $period, $declined, 'declined_at' ) ),
					self::COLOR_NEGATIVE
				),
			),
		);
	}

	/**
	 * Records whose given datetime column falls in the current or previous window.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param string               $column       Datetime column.
	 * @param bool                 $use_previous Use the comparison window.
	 * @param string[]             $with         Relations to eager-load.
	 * @return mixed Eloquent collection.
	 */
	protected function records_by_date_column( ReportPeriod $period, array $filters, $column, $use_previous, array $with = array() ) {
		$start = $use_previous ? $period->previous_start() : $period->current_start();
		$end   = $use_previous ? $period->previous_end() : $period->current_end();

		$query = $this->apply_date_scope( $this->base_query( $filters ), $start, $end, $column )
			->whereNotNull( $column );

		if ( ! empty( $with ) ) {
			$query->with( $with );
		}

		return $query->get();
	}

	/**
	 * Count of proposals sent but not yet decided.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return int
	 */
	protected function awaiting_decision_count( array $filters ) {
		$records = $this->base_query( $filters )
			->whereIn( 'status', array( ProposalStatus::SENT, ProposalStatus::OPEN ) )
			->whereNull( 'accepted_at' )
			->whereNull( 'declined_at' )
			->get();

		return $this->count_records( $records, $filters );
	}

	/**
	 * Share of accepted proposals that have a related invoice.
	 *
	 * @param iterable             $accepted Accepted proposals.
	 * @param array<string, mixed> $filters  Normalized filters.
	 * @return float
	 */
	protected function conversion_to_invoice_rate( $accepted, array $filters ) {
		$wanted    = $this->wanted_currencies( $filters );
		$total     = 0;
		$converted = 0;

		foreach ( $accepted as $proposal ) {
			if ( ! $this->currency_matches( $proposal, $wanted ) ) {
				continue;
			}
			$total++;
			if ( $proposal->invoice ) {
				$converted++;
			}
		}

		return $this->ratio( $converted, $total );
	}

	/**
	 * Number of proposals with a viewed_at timestamp.
	 *
	 * @param iterable             $records Model instances.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return int
	 */
	protected function count_viewed( $records, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );
		$count  = 0;

		foreach ( $records as $record ) {
			if ( ! $this->currency_matches( $record, $wanted ) ) {
				continue;
			}
			if ( ! empty( $record->viewed_at ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Mean proposal value across records.
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
			$values[] = (float) $record->total;
		}

		return $this->average( $values );
	}

	/**
	 * Mean days between sent_at and the decision (accepted_at or declined_at).
	 *
	 * @param iterable             $accepted Accepted proposals.
	 * @param iterable             $declined Declined proposals.
	 * @param array<string, mixed> $filters  Normalized filters.
	 * @return float
	 */
	protected function average_days_to_decision( $accepted, $declined, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );
		$spans  = array();

		foreach ( array( 'accepted_at' => $accepted, 'declined_at' => $declined ) as $column => $records ) {
			foreach ( $records as $record ) {
				if ( ! $this->currency_matches( $record, $wanted ) ) {
					continue;
				}
				$days = $this->days_between( $record->sent_at, $record->{$column} );
				if ( null !== $days ) {
					$spans[] = $days;
				}
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
			case ProposalStatus::ACCEPTED:
				return self::COLOR_POSITIVE;
			case ProposalStatus::DECLINED:
				return self::COLOR_NEGATIVE;
			case ProposalStatus::SENT:
			case ProposalStatus::OPEN:
				return self::COLOR_PRIMARY;
			case ProposalStatus::DRAFT:
			default:
				return self::COLOR_NEUTRAL;
		}
	}
}
