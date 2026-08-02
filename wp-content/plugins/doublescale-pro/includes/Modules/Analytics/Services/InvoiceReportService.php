<?php
/**
 * Invoice report service.
 *
 * The richest of the sales-document reports: collected revenue comes from the
 * payments child table (not a status flag), balance is computed (total minus
 * amount_paid), and it fixes three known weaknesses of the older
 * InvoiceAnalyticsService:
 *
 *  1. paid-invoice counting keyed off MAX(payment_date), not updated_at, so
 *     editing a paid invoice no longer moves it between reporting periods;
 *  2. outstanding is an explicit point-in-time snapshot carrying an as_of
 *     timestamp, instead of silently ignoring the date picker;
 *  3. the aging table and collected/invoiced series come from one pass over the
 *     data rather than a query per bucket.
 *
 * The existing free-side InvoiceAnalyticsService and its /sales/analytics/revenue
 * route are left in place — this service sits alongside them.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Services
 */

namespace DoubleScale\Pro\Modules\Analytics\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Pro\Modules\Analytics\Services\Support\CurrencyResolver;
use DoubleScale\Pro\Modules\Analytics\Services\Support\ReportPeriod;
use DoubleScale\Pro\Modules\Analytics\Support\EntityReportDescriptor;

/**
 * Aggregates invoice KPIs, trend, status breakdown, and aging.
 */
class InvoiceReportService extends EntityReportService {

	/**
	 * Statuses that carry an outstanding balance.
	 */
	const OUTSTANDING_STATUSES = array(
		InvoiceStatus::UNPAID,
		InvoiceStatus::PARTIALLY_PAID,
		InvoiceStatus::OVERDUE,
	);

	/**
	 * @return string
	 */
	protected function entity_key() {
		return EntityReportDescriptor::INVOICES;
	}

	/**
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_kpis( ReportPeriod $period, array $filters ) {
		$invoiced_now  = $this->records_in_period( $period, $filters, 'invoice_date' );
		$invoiced_prev = $this->records_in_previous_period( $period, $filters, 'invoice_date' );

		$invoiced_cur_now  = $this->sum_amount_by_currency( $invoiced_now, $filters );
		$invoiced_cur_prev = $this->sum_amount_by_currency( $invoiced_prev, $filters );

		$collected_cur_now  = $this->collected_by_currency( $period, $filters, false );
		$collected_cur_prev = $this->collected_by_currency( $period, $filters, true );

		$overdue = $this->overdue_snapshot( $filters );

		$avg_days_now  = $this->average_days_to_pay( $period, $filters, false );
		$avg_days_prev = $this->average_days_to_pay( $period, $filters, true );

		$paid_now  = $this->paid_invoice_count( $period, $filters, false );
		$paid_prev = $this->paid_invoice_count( $period, $filters, true );

		return array(
			$this->money_kpi(
				'collected',
				__( 'Collected', 'doublescale' ),
				$collected_cur_now,
				$collected_cur_prev
			),
			$this->money_kpi(
				'invoiced',
				__( 'Invoiced', 'doublescale' ),
				$invoiced_cur_now,
				$invoiced_cur_prev
			),
			$this->snapshot_money_kpi(
				'outstanding',
				__( 'Outstanding', 'doublescale' ),
				$this->outstanding_by_currency( $filters )
			),
			$this->snapshot_money_kpi(
				'overdue_amount',
				__( 'Overdue Amount', 'doublescale' ),
				$overdue['amount_by_currency'],
				true
			),
			$this->snapshot_kpi(
				'overdue_count',
				__( 'Overdue Invoices', 'doublescale' ),
				$overdue['count'],
				'number',
				true
			),
			$this->kpi(
				'collection_rate',
				__( 'Collection Rate', 'doublescale' ),
				$this->ratio( array_sum( $collected_cur_now ), array_sum( $invoiced_cur_now ) ),
				$this->ratio( array_sum( $collected_cur_prev ), array_sum( $invoiced_cur_prev ) ),
				'percent'
			),
			$this->kpi(
				'avg_days_to_pay',
				__( 'Avg Days to Pay', 'doublescale' ),
				$avg_days_now,
				$avg_days_prev,
				'days',
				true
			),
			$this->kpi(
				'paid_invoices',
				__( 'Paid Invoices', 'doublescale' ),
				$paid_now,
				$paid_prev
			),
			$this->money_kpi(
				'avg_invoice_value',
				__( 'Avg Invoice Value', 'doublescale' ),
				$this->average_amount_by_currency( $invoiced_now, $filters ),
				$this->average_amount_by_currency( $invoiced_prev, $filters )
			),
		);
	}

	/**
	 * Trend: invoiced (invoice_date) and collected (payment_date) per bucket.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_trend( ReportPeriod $period, array $filters ) {
		$wanted   = $this->wanted_currencies( $filters );
		$invoiced = $this->filter_by_currency(
			$this->records_in_period( $period, $filters, 'invoice_date' ),
			$wanted
		);

		return array(
			'labels' => $period->buckets(),
			'series' => array(
				$this->series(
					'invoiced',
					__( 'Invoiced', 'doublescale' ),
					array_values( $this->bucket_records( $period, $invoiced, 'invoice_date', 'total' ) ),
					self::COLOR_PRIMARY
				),
				$this->series(
					'collected',
					__( 'Collected', 'doublescale' ),
					array_values( $this->collected_buckets( $period, $filters ) ),
					self::COLOR_POSITIVE
				),
			),
		);
	}

	/**
	 * Status breakdown plus an aging table for outstanding balances.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_breakdown( ReportPeriod $period, array $filters ) {
		$breakdown              = parent::build_breakdown( $period, $filters );
		$breakdown['secondary'] = $this->build_aging( $filters );

		return $breakdown;
	}

	/**
	 * Total invoiced for a window — reused by CreditNoteReportService for the
	 * credit-to-invoice ratio.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return float
	 */
	public function invoiced_total_for( ReportPeriod $period, array $filters, $use_previous ) {
		$records = $use_previous
			? $this->records_in_previous_period( $period, $filters, 'invoice_date' )
			: $this->records_in_period( $period, $filters, 'invoice_date' );

		return $this->sum_amount( $records, $filters );
	}

	/**
	 * Payments made within the window, scoped to the owner filter.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return mixed Eloquent collection of payments with invoice loaded.
	 */
	protected function payments_in_period( ReportPeriod $period, array $filters, $use_previous ) {
		$start = substr( $use_previous ? $period->previous_start() : $period->current_start(), 0, 10 );
		$end   = substr( $use_previous ? $period->previous_end() : $period->current_end(), 0, 10 );

		$owner_id = isset( $filters['owner_id'] ) ? (int) $filters['owner_id'] : null;

		$query = PaymentModel::query()
			->whereDate( 'payment_date', '>=', $start )
			->whereDate( 'payment_date', '<=', $end );

		if ( null !== $owner_id && $owner_id > 0 ) {
			$query->whereHas(
				'invoice',
				function ( $q ) use ( $owner_id ) {
					$q->where( 'sale_agent_user_id', $owner_id );
				}
			);
		}

		return $query->with( 'invoice' )->get();
	}

	/**
	 * Collected total within the window (from payments), grouped by currency.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return array<string, float> Currency => amount.
	 */
	protected function collected_by_currency( ReportPeriod $period, array $filters, $use_previous ) {
		$wanted = $this->wanted_currencies( $filters );
		$totals = array();

		foreach ( $this->payments_in_period( $period, $filters, $use_previous ) as $payment ) {
			$amount = (float) $payment->amount;
			if ( $amount <= 0 || ! $this->payment_currency_matches( $payment, $wanted ) ) {
				continue;
			}
			$currency = $payment->invoice
				? CurrencyResolver::resolve( $payment->invoice )
				: CurrencyResolver::global_currency();
			if ( ! isset( $totals[ $currency ] ) ) {
				$totals[ $currency ] = 0.0;
			}
			$totals[ $currency ] += $amount;
		}

		return CurrencyResolver::round_map( $totals );
	}

	/**
	 * Collected amount per trend bucket.
	 *
	 * @param ReportPeriod         $period  Reporting period.
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, float>
	 */
	protected function collected_buckets( ReportPeriod $period, array $filters ) {
		$wanted = $this->wanted_currencies( $filters );
		$totals = array_fill_keys( $period->buckets(), 0.0 );

		foreach ( $this->payments_in_period( $period, $filters, false ) as $payment ) {
			$amount = (float) $payment->amount;
			if ( $amount <= 0 || ! $this->payment_currency_matches( $payment, $wanted ) ) {
				continue;
			}
			$bucket = $period->bucket_for( $payment->payment_date );
			if ( array_key_exists( $bucket, $totals ) ) {
				$totals[ $bucket ] += $amount;
			}
		}

		return $totals;
	}

	/**
	 * Outstanding balance across unpaid/partial/overdue invoices (point-in-time),
	 * grouped by currency.
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, float> Currency => balance.
	 */
	protected function outstanding_by_currency( array $filters ) {
		$wanted  = $this->wanted_currencies( $filters );
		$records = $this->base_query( $filters )
			->whereIn( 'status', self::OUTSTANDING_STATUSES )
			->get();

		$totals = array();
		foreach ( $records as $invoice ) {
			if ( ! $this->currency_matches( $invoice, $wanted ) ) {
				continue;
			}
			$balance = max( 0.0, (float) $invoice->total - (float) $invoice->amount_paid );
			if ( $balance <= 0 ) {
				continue;
			}
			$currency = CurrencyResolver::resolve( $invoice );
			if ( ! isset( $totals[ $currency ] ) ) {
				$totals[ $currency ] = 0.0;
			}
			$totals[ $currency ] += $balance;
		}

		return CurrencyResolver::round_map( $totals );
	}

	/**
	 * Overdue count and amount-by-currency (point-in-time).
	 *
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array{amount_by_currency: array<string, float>, count: int}
	 */
	protected function overdue_snapshot( array $filters ) {
		$today   = current_time( 'Y-m-d' );
		$wanted  = $this->wanted_currencies( $filters );
		$records = $this->base_query( $filters )
			->whereIn( 'status', self::OUTSTANDING_STATUSES )
			->whereNotNull( 'due_date' )
			->whereDate( 'due_date', '<', $today )
			->get();

		$by_currency = array();
		$count       = 0;
		foreach ( $records as $invoice ) {
			if ( ! $this->currency_matches( $invoice, $wanted ) ) {
				continue;
			}
			$balance = max( 0.0, (float) $invoice->total - (float) $invoice->amount_paid );
			if ( $balance <= 0 ) {
				continue;
			}
			$currency = CurrencyResolver::resolve( $invoice );
			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = 0.0;
			}
			$by_currency[ $currency ] += $balance;
			$count++;
		}

		return array(
			'amount_by_currency' => CurrencyResolver::round_map( $by_currency ),
			'count'              => $count,
		);
	}

	/**
	 * Classify a "days past due" value into an aging bucket key.
	 *
	 * Boundaries are inclusive on the upper edge: 30 days late is still 1–30,
	 * 31 tips into 31–60. Zero or negative (not yet due) is "current".
	 *
	 * @param int $days_late Whole days past the due date.
	 * @return string One of current|1_30|31_60|61_90|90_plus.
	 */
	public static function aging_bucket( $days_late ) {
		$days_late = (int) $days_late;

		if ( $days_late > 90 ) {
			return '90_plus';
		}
		if ( $days_late > 60 ) {
			return '61_90';
		}
		if ( $days_late > 30 ) {
			return '31_60';
		}
		if ( $days_late > 0 ) {
			return '1_30';
		}

		return 'current';
	}

	/**
	 * @param array<string, mixed> $filters Normalized filters.
	 * @return array<string, mixed>
	 */
	protected function build_aging( array $filters ) {
		$today  = strtotime( current_time( 'Y-m-d' ) );
		$wanted = $this->wanted_currencies( $filters );

		$labels  = array(
			'current' => __( 'Current', 'doublescale' ),
			'1_30'    => __( '1–30 days', 'doublescale' ),
			'31_60'   => __( '31–60 days', 'doublescale' ),
			'61_90'   => __( '61–90 days', 'doublescale' ),
			'90_plus' => __( '90+ days', 'doublescale' ),
		);
		$buckets = array();
		foreach ( $labels as $key => $label ) {
			$buckets[ $key ] = array( 'count' => 0, 'value' => 0.0, 'by_currency' => array() );
		}

		$records = $this->base_query( $filters )
			->whereIn( 'status', self::OUTSTANDING_STATUSES )
			->get();

		foreach ( $records as $invoice ) {
			if ( ! $this->currency_matches( $invoice, $wanted ) ) {
				continue;
			}
			$balance = max( 0.0, (float) $invoice->total - (float) $invoice->amount_paid );
			if ( $balance <= 0 ) {
				continue;
			}

			$due       = ! empty( $invoice->due_date ) ? strtotime( (string) $invoice->due_date ) : 0;
			$days_late = $due ? (int) floor( ( $today - $due ) / DAY_IN_SECONDS ) : 0;
			$key       = self::aging_bucket( $days_late );
			$currency  = CurrencyResolver::resolve( $invoice );

			$buckets[ $key ]['count']++;
			$buckets[ $key ]['value'] += $balance;
			if ( ! isset( $buckets[ $key ]['by_currency'][ $currency ] ) ) {
				$buckets[ $key ]['by_currency'][ $currency ] = 0.0;
			}
			$buckets[ $key ]['by_currency'][ $currency ] += $balance;
		}

		$total = 0.0;
		foreach ( $buckets as $bucket ) {
			$total += $bucket['value'];
		}

		$rows = array();
		foreach ( $buckets as $key => $bucket ) {
			$rows[] = array(
				'key'               => $key,
				'label'             => $labels[ $key ],
				'count'             => $bucket['count'],
				'value'             => $this->round_amount( $bucket['value'] ),
				'value_by_currency' => CurrencyResolver::round_map( $bucket['by_currency'] ),
				'share'             => $this->ratio( $bucket['value'], $total ),
			);
		}

		return array(
			'type'    => 'aging',
			'title'   => __( 'Outstanding by Age', 'doublescale' ),
			'columns' => array(
				array( 'key' => 'label', 'label' => __( 'Age', 'doublescale' ), 'format' => 'text' ),
				array( 'key' => 'count', 'label' => __( 'Invoices', 'doublescale' ), 'format' => 'number' ),
				array( 'key' => 'value', 'label' => __( 'Balance', 'doublescale' ), 'format' => 'currency' ),
				array( 'key' => 'share', 'label' => __( '% Share', 'doublescale' ), 'format' => 'percent' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Count invoices whose final payment landed in the window.
	 *
	 * Keyed off MAX(payment_date) rather than updated_at, so editing a paid
	 * invoice does not shift it between reporting periods.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return int
	 */
	protected function paid_invoice_count( ReportPeriod $period, array $filters, $use_previous ) {
		$start  = substr( $use_previous ? $period->previous_start() : $period->current_start(), 0, 10 );
		$end    = substr( $use_previous ? $period->previous_end() : $period->current_end(), 0, 10 );
		$wanted = $this->wanted_currencies( $filters );

		$last_payment = array();
		$invoices     = array();

		foreach ( $this->payments_in_period( $period, $filters, $use_previous ) as $payment ) {
			$invoice = $payment->invoice;
			if ( ! $invoice || InvoiceStatus::PAID !== $invoice->status ) {
				continue;
			}
			if ( ! $this->currency_matches( $invoice, $wanted ) ) {
				continue;
			}

			$id   = (int) $invoice->id;
			$date = (string) $payment->payment_date;
			if ( ! isset( $last_payment[ $id ] ) || $date > $last_payment[ $id ] ) {
				$last_payment[ $id ] = $date;
			}
			$invoices[ $id ] = true;
		}

		$count = 0;
		foreach ( $last_payment as $date ) {
			if ( $date >= $start && $date <= $end ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Mean days from invoice_date to final payment for invoices paid in the window.
	 *
	 * @param ReportPeriod         $period       Reporting period.
	 * @param array<string, mixed> $filters      Normalized filters.
	 * @param bool                 $use_previous Use the comparison window.
	 * @return float
	 */
	protected function average_days_to_pay( ReportPeriod $period, array $filters, $use_previous ) {
		$wanted = $this->wanted_currencies( $filters );

		$last_payment = array();
		$invoice_date = array();

		foreach ( $this->payments_in_period( $period, $filters, $use_previous ) as $payment ) {
			$invoice = $payment->invoice;
			if ( ! $invoice || InvoiceStatus::PAID !== $invoice->status ) {
				continue;
			}
			if ( ! $this->currency_matches( $invoice, $wanted ) ) {
				continue;
			}

			$id   = (int) $invoice->id;
			$date = (string) $payment->payment_date;
			if ( ! isset( $last_payment[ $id ] ) || $date > $last_payment[ $id ] ) {
				$last_payment[ $id ] = $date;
			}
			$invoice_date[ $id ] = (string) $invoice->invoice_date;
		}

		$spans = array();
		foreach ( $last_payment as $id => $paid_on ) {
			$days = $this->days_between( $invoice_date[ $id ], $paid_on );
			if ( null !== $days ) {
				$spans[] = $days;
			}
		}

		return $this->average( $spans );
	}

	/**
	 * Mean invoice value across records.
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
	 * Currency match for a payment, resolved via its parent invoice.
	 *
	 * @param object   $payment Payment with invoice loaded.
	 * @param string[] $wanted  Currency filter.
	 * @return bool
	 */
	protected function payment_currency_matches( $payment, array $wanted ) {
		if ( empty( $wanted ) ) {
			return true;
		}

		$invoice = $payment->invoice;
		if ( ! $invoice ) {
			return false;
		}

		return in_array( CurrencyResolver::resolve( $invoice ), $wanted, true );
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
			case InvoiceStatus::PAID:
				return self::COLOR_POSITIVE;
			case InvoiceStatus::OVERDUE:
				return self::COLOR_NEGATIVE;
			case InvoiceStatus::UNPAID:
			case InvoiceStatus::PARTIALLY_PAID:
				return self::COLOR_PRIMARY;
			case InvoiceStatus::DRAFT:
			default:
				return self::COLOR_NEUTRAL;
		}
	}
}
