<?php
/**
 * Invoice revenue analytics for reporting.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;

/**
 * InvoiceAnalyticsService class.
 */
final class InvoiceAnalyticsService {

	/**
	 * @param string               $start_date Y-m-d.
	 * @param string               $end_date   Y-m-d.
	 * @param array<string, mixed> $filters    Optional sale_agent_user_id, currencies.
	 * @return array<string, mixed>
	 */
	public function get_revenue_summary( string $start_date, string $end_date, array $filters = array() ): array {
		$agent_id   = isset( $filters['sale_agent_user_id'] ) ? absint( $filters['sale_agent_user_id'] ) : 0;
		$currencies = $this->normalize_currencies( $filters['currencies'] ?? array() );

		$payments_query = PaymentModel::query()
			->whereDate( 'payment_date', '>=', $start_date )
			->whereDate( 'payment_date', '<=', $end_date );

		// Currency filtering happens AFTER resolution (below), not on the raw
		// `currency` column, so the report matches what is actually displayed
		// (drafts follow the global currency; sent docs keep their frozen one).
		if ( $agent_id > 0 ) {
			$payments_query->whereHas(
				'invoice',
				function ( $query ) use ( $agent_id ) {
					$query->where( 'sale_agent_user_id', $agent_id );
				}
			);
		}

		$payments = $payments_query->with( 'invoice' )->get();

		$total_collected = 0.0;
		$by_currency     = array();
		$payment_count   = 0;

		foreach ( $payments as $payment ) {
			$amount = (float) $payment->amount;
			if ( $amount <= 0 ) {
				continue;
			}
			$invoice  = $payment->invoice;
			// Global currency for drafts, frozen stored currency once sent.
			$currency = \DoubleScale\Core\Settings\Settings::document_currency(
				$invoice ? $invoice->currency : null,
				$invoice ? $invoice->sent_at : null
			);
			if ( ! empty( $currencies ) && ! in_array( $currency, $currencies, true ) ) {
				continue;
			}
			$total_collected += $amount;
			$payment_count++;
			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = 0.0;
			}
			$by_currency[ $currency ] += $amount;
		}

		$outstanding_query = InvoiceModel::query()
			->whereIn( 'status', array( InvoiceStatus::UNPAID, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::OVERDUE ) );

		if ( $agent_id > 0 ) {
			$outstanding_query->where( 'sale_agent_user_id', $agent_id );
		}

		$outstanding_total = 0.0;
		$outstanding_count = 0;
		$outstanding_by_currency = array();
		foreach ( $outstanding_query->get() as $invoice ) {
			$balance = max( 0, (float) $invoice->total - (float) $invoice->amount_paid );
			if ( $balance <= 0 ) {
				continue;
			}
			$currency = \DoubleScale\Core\Settings\Settings::document_currency( $invoice->currency, $invoice->sent_at );
			if ( ! empty( $currencies ) && ! in_array( $currency, $currencies, true ) ) {
				continue;
			}
			$outstanding_total += $balance;
			$outstanding_count++;
			if ( ! isset( $outstanding_by_currency[ $currency ] ) ) {
				$outstanding_by_currency[ $currency ] = 0.0;
			}
			$outstanding_by_currency[ $currency ] += $balance;
		}

		$paid_query = InvoiceModel::query()
			->where( 'status', InvoiceStatus::PAID )
			->whereDate( 'updated_at', '>=', $start_date )
			->whereDate( 'updated_at', '<=', $end_date );

		if ( $agent_id > 0 ) {
			$paid_query->where( 'sale_agent_user_id', $agent_id );
		}

		if ( empty( $currencies ) ) {
			$paid_invoices = $paid_query->count();
		} else {
			// Count post-resolution so the currency filter matches the displayed currency.
			$paid_invoices = 0;
			foreach ( $paid_query->get() as $invoice ) {
				$currency = \DoubleScale\Core\Settings\Settings::document_currency( $invoice->currency, $invoice->sent_at );
				if ( in_array( $currency, $currencies, true ) ) {
					$paid_invoices++;
				}
			}
		}

		return array(
			'start_date'                => $start_date,
			'end_date'                  => $end_date,
			'sale_agent_user_id'        => $agent_id > 0 ? $agent_id : null,
			'currencies'                => $currencies,
			'total_collected'           => round( $total_collected, 2 ),
			'payment_count'             => $payment_count,
			'collected_by_currency'     => $this->round_currency_map( $by_currency ),
			'outstanding_total'         => round( $outstanding_total, 2 ),
			'outstanding_count'         => $outstanding_count,
			'outstanding_by_currency'   => $this->round_currency_map( $outstanding_by_currency ),
			'paid_invoices_count'       => (int) $paid_invoices,
		);
	}

	/**
	 * Monthly revenue buckets for charting.
	 *
	 * @param int                  $year    Four-digit year.
	 * @param array<string, mixed> $filters Optional sale_agent_user_id, currencies.
	 * @return array<int, array{month: int, label: string, total: float, count: int, by_currency: array<string, float>}>
	 */
	public function get_monthly_revenue( int $year, array $filters = array() ): array {
		$agent_id   = isset( $filters['sale_agent_user_id'] ) ? absint( $filters['sale_agent_user_id'] ) : 0;
		$currencies = $this->normalize_currencies( $filters['currencies'] ?? array() );
		$rows       = array();

		for ( $month = 1; $month <= 12; $month++ ) {
			$start = sprintf( '%04d-%02d-01', $year, $month );
			$end   = gmdate( 'Y-m-t', strtotime( $start . ' UTC' ) );

			$payments_query = PaymentModel::query()
				->whereDate( 'payment_date', '>=', $start )
				->whereDate( 'payment_date', '<=', $end );

			// Currency filtering happens after resolution in the loop below.
			if ( $agent_id > 0 ) {
				$payments_query->whereHas(
					'invoice',
					function ( $query ) use ( $agent_id ) {
						$query->where( 'sale_agent_user_id', $agent_id );
					}
				);
			}

			$payments    = $payments_query->with( 'invoice' )->get();
			$total       = 0.0;
			$count       = 0;
			$by_currency = array();

			foreach ( $payments as $payment ) {
				$amount = (float) $payment->amount;
				if ( $amount <= 0 ) {
					continue;
				}
				$invoice  = $payment->invoice;
				$currency = \DoubleScale\Core\Settings\Settings::document_currency(
					$invoice ? $invoice->currency : null,
					$invoice ? $invoice->sent_at : null
				);
				if ( ! empty( $currencies ) && ! in_array( $currency, $currencies, true ) ) {
					continue;
				}
				$total += $amount;
				$count++;
				if ( ! isset( $by_currency[ $currency ] ) ) {
					$by_currency[ $currency ] = 0.0;
				}
				$by_currency[ $currency ] += $amount;
			}

			$rows[] = array(
				'month'       => $month,
				'label'       => gmdate( 'M', strtotime( $start . ' UTC' ) ),
				'total'       => round( $total, 2 ),
				'count'       => $count,
				'by_currency' => $this->round_currency_map( $by_currency ),
			);
		}

		return $rows;
	}

	/**
	 * Distinct invoice currencies used in the system.
	 *
	 * @return string[]
	 */
	public function get_available_currencies(): array {
		// Return the RESOLVED currencies (global for drafts, frozen for sent), so
		// the filter options match what the report groups by. Only `currency` and
		// `sent_at` are needed to resolve each row.
		$rows = InvoiceModel::query()
			->select( array( 'currency', 'sent_at' ) )
			->get();

		$currencies = array();
		foreach ( $rows as $invoice ) {
			$currency = strtoupper(
				trim( (string) \DoubleScale\Core\Settings\Settings::document_currency( $invoice->currency, $invoice->sent_at ) )
			);
			if ( '' !== $currency ) {
				$currencies[] = $currency;
			}
		}

		$currencies = array_values( array_unique( $currencies ) );
		sort( $currencies );

		return $currencies;
	}

	/**
	 * @param mixed $currencies Raw filter value.
	 * @return string[]
	 */
	private function normalize_currencies( $currencies ): array {
		if ( is_string( $currencies ) ) {
			$currencies = array_map( 'trim', explode( ',', $currencies ) );
		}
		if ( ! is_array( $currencies ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $currencies as $currency ) {
			$currency = strtoupper( sanitize_text_field( (string) $currency ) );
			if ( '' !== $currency ) {
				$normalized[] = $currency;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * @param array<string, float> $map Currency totals.
	 * @return array<string, float>
	 */
	private function round_currency_map( array $map ): array {
		$rounded = array();
		foreach ( $map as $currency => $amount ) {
			$rounded[ (string) $currency ] = round( (float) $amount, 2 );
		}
		ksort( $rounded );
		return $rounded;
	}
}
