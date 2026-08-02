<?php
/**
 * Credit note report service.
 *
 * Adds two things over the sales-document template: the applications child table
 * (real application dates and amounts, analogous to invoice payments) and a
 * credit-to-invoice ratio, which a finance lead watches — a rising ratio signals
 * billing or delivery problems. That ratio needs the invoice total for the same
 * period, so this service depends on InvoiceReportService.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Services
 */

namespace DoubleScale\Pro\Modules\Analytics\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Analytics\Services\Support\CurrencyResolver;
use DoubleScale\Pro\Modules\Analytics\Services\Support\ReportPeriod;
use DoubleScale\Pro\Modules\Analytics\Support\EntityReportDescriptor;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteApplicationModel;

/**
 * Aggregates credit-note KPIs, trend, and breakdown.
 */
class CreditNoteReportService extends EntityReportService {

	/**
	 * @var InvoiceReportService|null
	 */
	private $invoice_service;

	/**
	 * @param InvoiceReportService|null $invoice_service Injected for the credit-to-invoice ratio.
	 */
	public function __construct( $invoice_service = null ) {
		$this->invoice_service = $invoice_service;
	}

	/**
	 * @return string
	 */
	protected function entity_key() {
		return EntityReportDescriptor::CREDIT_NOTES;
	}

	/**
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_kpis( ReportPeriod $period, array $filters ) {
		$issued_now  = $this->records_in_period( $period, $filters );
		$issued_prev = $this->records_in_previous_period( $period, $filters );

		$applied_cur_now  = $this->applied_by_currency( $period, $filters, false );
		$applied_cur_prev = $this->applied_by_currency( $period, $filters, true );

		$credited_cur_now  = $this->sum_amount_by_currency( $issued_now, $filters );
		$credited_cur_prev = $this->sum_amount_by_currency( $issued_prev, $filters );

		$invoiced_now  = $this->invoiced_total( $period, $filters, false );
		$invoiced_prev = $this->invoiced_total( $period, $filters, true );

		$voided_now  = $this->count_by_status( $period, $filters, CreditNoteStatus::VOID, false );
		$voided_prev = $this->count_by_status( $period, $filters, CreditNoteStatus::VOID, true );

		return array(
			$this->kpi(
				'issued',
				__( 'Credit Notes Issued', 'doublescale' ),
				$this->count_records( $issued_now, $filters ),
				$this->count_records( $issued_prev, $filters )
			),
			$this->money_kpi(
				'total_credited',
				__( 'Total Credited', 'doublescale' ),
				$credited_cur_now,
				$credited_cur_prev
			),
			$this->money_kpi(
				'applied',
				__( 'Applied', 'doublescale' ),
				$applied_cur_now,
				$applied_cur_prev
			),
			$this->kpi(
				'application_rate',
				__( 'Application Rate', 'doublescale' ),
				$this->ratio( array_sum( $applied_cur_now ), array_sum( $credited_cur_now ) ),
				$this->ratio( array_sum( $applied_cur_prev ), array_sum( $credited_cur_prev ) ),
				'percent'
			),
			$this->snapshot_money_kpi(
				'remaining_credit',
				__( 'Remaining Credit', 'doublescale' ),
				$this->remaining_credit_by_currency( $filters )
			),
			$this->kpi(
				'voided',
				__( 'Voided', 'doublescale' ),
				$voided_now,
				$voided_prev,
				'number',
				true
			),
			$this->money_kpi(
				'avg_value',
				__( 'Avg Credit Note Value', 'doublescale' ),
				$this->average_amount_by_currency( $issued_now, $filters ),
				$this->average_amount_by_currency( $issued_prev, $filters )
			),
			$this->kpi(
				'credit_to_invoice_ratio',
				__( 'Credit-to-Invoice Ratio', 'doublescale' ),
				$this->ratio( array_sum( $credited_cur_now ), $invoiced_now ),
				$this->ratio( array_sum( $credited_cur_prev ), $invoiced_prev ),
				'percent',
				true
			),
		);
	}

	/**
	 * Trend: issued (credit_note_date) vs applied (applications.applied_date).
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_trend( ReportPeriod $period, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );
		$issued = $this->filter_by_currency( $this->records_in_period( $period, $filters ), $wanted );

		return array(
			'labels' => $period->buckets(),
			'series' => array(
				$this->series(
					'issued',
					__( 'Issued', 'doublescale' ),
					array_values( $this->bucket_records( $period, $issued, 'credit_note_date', 'total' ) ),
					self::COLOR_PRIMARY
				),
				$this->series(
					'applied',
					__( 'Applied', 'doublescale' ),
					array_values( $this->applied_buckets( $period, $filters ) ),
					self::COLOR_POSITIVE
				),
			),
		);
	}

	/**
	 * Status breakdown plus top invoices by credit applied.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_breakdown( ReportPeriod $period, array $filters ) {
		$breakdown              = parent::build_breakdown( $period, $filters );
		$breakdown['secondary'] = $this->build_applied_invoices( $period, $filters );

		return $breakdown;
	}

	/**
	 * Applications joined to their credit notes for owner/currency scoping.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return mixed Eloquent collection of applications with creditNote loaded.
	 */
	protected function applications_in_period( ReportPeriod $period, array $filters, $use_previous ) {
		$start = $use_previous ? $period->previous_start() : $period->current_start();
		$end   = $use_previous ? $period->previous_end() : $period->current_end();

		$owner_id = isset( $filters['owner_id'] ) ? (int) $filters['owner_id'] : null;

		$query = CreditNoteApplicationModel::query()
			->whereBetween( 'applied_date', array( substr( $start, 0, 10 ), substr( $end, 0, 10 ) ) );

		if ( null !== $owner_id && $owner_id > 0 ) {
			$query->whereHas(
				'creditNote',
				function ( $q ) use ( $owner_id ) {
					$q->where( 'sale_agent_user_id', $owner_id );
				}
			);
		}

		return $query->with( 'creditNote' )->get();
	}

	/**
	 * Total credit applied within the window, grouped by currency.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return array<string, float> Currency => amount.
	 */
	protected function applied_by_currency( ReportPeriod $period, array $filters, $use_previous ) {
		$wanted = $this->wanted_currencies( $filters );
		$totals = array();

		foreach ( $this->applications_in_period( $period, $filters, $use_previous ) as $application ) {
			if ( ! $this->application_currency_matches( $application, $wanted ) ) {
				continue;
			}
			$credit_note = $application->creditNote;
			$currency    = $credit_note
				? CurrencyResolver::resolve( $credit_note )
				: CurrencyResolver::global_currency();
			if ( ! isset( $totals[ $currency ] ) ) {
				$totals[ $currency ] = 0.0;
			}
			$totals[ $currency ] += (float) $application->amount;
		}

		return CurrencyResolver::round_map( $totals );
	}

	/**
	 * Applied amount per trend bucket.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, float>
	 */
	protected function applied_buckets( ReportPeriod $period, array $filters ) {
		$wanted  = $this->wanted_currencies( $filters );
		$totals  = array_fill_keys( $period->buckets(), 0.0 );

		foreach ( $this->applications_in_period( $period, $filters, false ) as $application ) {
			if ( ! $this->application_currency_matches( $application, $wanted ) ) {
				continue;
			}
			$bucket = $period->bucket_for( $application->applied_date );
			if ( array_key_exists( $bucket, $totals ) ) {
				$totals[ $bucket ] += (float) $application->amount;
			}
		}

		return $totals;
	}

	/**
	 * Top source invoices by credit applied in the period.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_applied_invoices( ReportPeriod $period, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );
		$rows   = array();

		foreach ( $this->applications_in_period( $period, $filters, false ) as $application ) {
			if ( ! $this->application_currency_matches( $application, $wanted ) ) {
				continue;
			}

			$invoice_id = (int) $application->invoice_id;
			$key        = (string) $invoice_id;

			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = $this->new_breakdown_row(
					$key,
					sprintf(
						/* translators: %d: invoice id. */
						__( 'Invoice #%d', 'doublescale' ),
						$invoice_id
					),
					self::COLOR_PRIMARY
				);
			}

			$amount      = (float) $application->amount;
			$credit_note = $application->creditNote;
			$currency    = $credit_note
				? CurrencyResolver::resolve( $credit_note )
				: CurrencyResolver::global_currency();

			$rows[ $key ]['count']++;
			$rows[ $key ]['value'] += $amount;
			if ( ! isset( $rows[ $key ]['value_by_currency'][ $currency ] ) ) {
				$rows[ $key ]['value_by_currency'][ $currency ] = 0.0;
			}
			$rows[ $key ]['value_by_currency'][ $currency ] += $amount;
		}

		$total = array_sum( array_column( $rows, 'value' ) );
		foreach ( $rows as $key => $row ) {
			$rows[ $key ]['value']             = $this->round_amount( $row['value'] );
			$rows[ $key ]['value_by_currency'] = CurrencyResolver::round_map( $row['value_by_currency'] );
			$rows[ $key ]['share']             = $this->ratio( $row['value'], $total );
		}

		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);

		return array(
			'type'    => 'applied_invoices',
			'title'   => __( 'Credit Applied by Invoice', 'doublescale' ),
			'columns' => array(
				array(
					'key'    => 'label',
					'label'  => __( 'Invoice', 'doublescale' ),
					'format' => 'text',
				),
				array(
					'key'    => 'count',
					'label'  => __( 'Applications', 'doublescale' ),
					'format' => 'number',
				),
				array(
					'key'    => 'value',
					'label'  => __( 'Applied', 'doublescale' ),
					'format' => 'currency',
				),
				array(
					'key'    => 'share',
					'label'  => __( '% Share', 'doublescale' ),
					'format' => 'percent',
				),
			),
			'rows'    => array_slice( $rows, 0, 10 ),
		);
	}

	/**
	 * Outstanding credit not yet applied (point-in-time), grouped by currency.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, float> Currency => remaining.
	 */
	protected function remaining_credit_by_currency( array $filters ) {
		$wanted  = $this->wanted_currencies( $filters );
		$records = $this->base_query( $filters )
			->whereIn( 'status', array( CreditNoteStatus::OPEN, CreditNoteStatus::PARTIALLY_APPLIED ) )
			->get();

		$totals = array();
		foreach ( $records as $record ) {
			if ( ! $this->currency_matches( $record, $wanted ) ) {
				continue;
			}
			$remaining = max( 0.0, (float) $record->total - (float) $record->amount_applied );
			if ( $remaining <= 0 ) {
				continue;
			}
			$currency = CurrencyResolver::resolve( $record );
			if ( ! isset( $totals[ $currency ] ) ) {
				$totals[ $currency ] = 0.0;
			}
			$totals[ $currency ] += $remaining;
		}

		return CurrencyResolver::round_map( $totals );
	}

	/**
	 * Invoice total for the same window, for the credit-to-invoice ratio.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return float
	 */
	protected function invoiced_total( ReportPeriod $period, array $filters, $use_previous ) {
		$service = $this->invoice_service ? $this->invoice_service : new InvoiceReportService();

		return $service->invoiced_total_for( $period, $filters, $use_previous );
	}

	/**
	 * Count of credit notes reaching a status within the window (by issue date).
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param string               $status       Status value.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return int
	 */
	protected function count_by_status( ReportPeriod $period, array $filters, $status, $use_previous ) {
		$start = $use_previous ? $period->previous_start() : $period->current_start();
		$end   = $use_previous ? $period->previous_end() : $period->current_end();

		$records = $this->apply_date_scope( $this->base_query( $filters ), $start, $end )
			->where( 'status', $status )
			->get();

		return $this->count_records( $records, $filters );
	}

	/**
	 * Mean credit-note value across records.
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
	 * Currency match for an application, resolved via its parent credit note.
	 *
	 * @param object   $application Application with creditNote loaded.
	 * @param string[] $wanted      Currency filter.
	 * @return bool
	 */
	protected function application_currency_matches( $application, array $wanted ) {
		if ( empty( $wanted ) ) {
			return true;
		}

		$credit_note = $application->creditNote;
		if ( ! $credit_note ) {
			return false;
		}

		return in_array( CurrencyResolver::resolve( $credit_note ), $wanted, true );
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
			case CreditNoteStatus::APPLIED:
			case CreditNoteStatus::PARTIALLY_APPLIED:
				return self::COLOR_POSITIVE;
			case CreditNoteStatus::VOID:
				return self::COLOR_NEGATIVE;
			case CreditNoteStatus::OPEN:
				return self::COLOR_PRIMARY;
			case CreditNoteStatus::DRAFT:
			default:
				return self::COLOR_NEUTRAL;
		}
	}
}
