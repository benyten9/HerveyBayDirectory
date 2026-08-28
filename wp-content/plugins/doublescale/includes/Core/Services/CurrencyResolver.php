<?php
/**
 * Currency resolution for documents and reports.
 *
 * THE RULE: never group or filter on a raw `currency` column. Resolve first via
 * Settings::document_currency() — a non-empty stored code is the explicit (or
 * frozen) choice; NULL/empty inherits the global — and only then apply the
 * currency filter. Reports must group by what the user actually sees.
 *
 * Mixed-currency totals are never collapsed into a single scalar here —
 * silently adding EUR to USD is the kind of error that destroys trust in a
 * finance report. Callers that need one number pick a currency.
 *
 * @since 2.1.0
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * Resolves and aggregates report currencies.
 */
class CurrencyResolver {

	/**
	 * Resolve the display currency for a record.
	 *
	 * @param object $record Model instance exposing `currency` (and optionally `sent_at`).
	 * @return string Uppercase currency code.
	 */
	public static function resolve( $record ) {
		if ( ! is_object( $record ) ) {
			return self::global_currency();
		}

		return self::resolve_raw(
			isset( $record->currency ) ? $record->currency : null,
			isset( $record->sent_at ) ? $record->sent_at : null
		);
	}

	/**
	 * Resolve from raw column values.
	 *
	 * `$sent_at` is kept in the signature for call-site compatibility; freeze
	 * happens on the write path now, so it is unused for resolution.
	 *
	 * @param string|null $currency Stored currency column.
	 * @param string|null $sent_at  Stored sent_at column (unused).
	 * @return string Uppercase currency code.
	 */
	public static function resolve_raw( $currency, $sent_at ) {
		unset( $sent_at );
		return self::normalize( Settings::document_currency( $currency, null ) );
	}

	/**
	 * Global currency — used by entities with no currency column (projects).
	 *
	 * @return string
	 */
	public static function global_currency() {
		return self::normalize( Settings::get_currency() );
	}

	/**
	 * Whether a record's resolved currency is in the wanted set.
	 *
	 * An empty wanted set means "all currencies".
	 *
	 * @param object   $record Model instance.
	 * @param string[] $wanted Normalized currency codes.
	 * @return bool
	 */
	public static function matches( $record, array $wanted ) {
		if ( empty( $wanted ) ) {
			return true;
		}

		return in_array( self::resolve( $record ), $wanted, true );
	}

	/**
	 * Normalize a raw filter value (CSV string or array) to currency codes.
	 *
	 * @param mixed $currencies Raw filter value.
	 * @return string[]
	 */
	public static function normalize_list( $currencies ) {
		if ( is_string( $currencies ) ) {
			$currencies = explode( ',', $currencies );
		}
		if ( ! is_array( $currencies ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $currencies as $currency ) {
			$code = self::normalize( $currency );
			if ( '' !== $code ) {
				$normalized[] = $code;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Sum an amount column across records, grouped by resolved currency.
	 *
	 * @param iterable $records       Model instances.
	 * @param string   $amount_column Column to sum.
	 * @param string[] $wanted        Optional currency filter.
	 * @return array<string, float> Currency code => total.
	 */
	public static function sum_by_currency( $records, $amount_column, array $wanted = array() ) {
		$totals = array();

		foreach ( $records as $record ) {
			if ( ! self::matches( $record, $wanted ) ) {
				continue;
			}

			$amount = isset( $record->{$amount_column} ) ? (float) $record->{$amount_column} : 0.0;
			if ( 0.0 === $amount ) {
				continue;
			}

			$currency = self::resolve( $record );
			if ( ! isset( $totals[ $currency ] ) ) {
				$totals[ $currency ] = 0.0;
			}
			$totals[ $currency ] += $amount;
		}

		return self::round_map( $totals );
	}

	/**
	 * Distinct resolved currencies present for a model.
	 *
	 * SELECT DISTINCT currency plus a global-currency union for the NULL
	 * bucket — equivalent under NULL-means-inherit, cheaper than loading every row.
	 *
	 * @param string $model_class Eloquent model FQCN.
	 * @return string[] Sorted, unique.
	 */
	public static function available_for( $model_class ) {
		if ( ! class_exists( $model_class ) ) {
			return array();
		}

		$rows = $model_class::query()
			->select( array( 'currency' ) )
			->distinct()
			->get();

		$currencies = array();
		$has_inherit = false;
		foreach ( $rows as $row ) {
			$raw = isset( $row->currency ) ? $row->currency : null;
			if ( null === $raw || '' === trim( (string) $raw ) ) {
				$has_inherit = true;
				continue;
			}
			$code = self::normalize( $raw );
			if ( '' !== $code ) {
				$currencies[] = $code;
			}
		}

		if ( $has_inherit ) {
			$currencies[] = self::global_currency();
		}

		$currencies = array_values( array_unique( $currencies ) );
		sort( $currencies );

		return $currencies;
	}

	/**
	 * Round every amount in a currency map and sort by code.
	 *
	 * @param array<string, float> $map Currency totals.
	 * @return array<string, float>
	 */
	public static function round_map( array $map ) {
		$rounded = array();
		foreach ( $map as $currency => $amount ) {
			$rounded[ (string) $currency ] = round( (float) $amount, 2 );
		}
		ksort( $rounded );

		return $rounded;
	}

	/**
	 * @param mixed $currency Raw code.
	 * @return string
	 */
	private static function normalize( $currency ) {
		return strtoupper( trim( (string) $currency ) );
	}
}
