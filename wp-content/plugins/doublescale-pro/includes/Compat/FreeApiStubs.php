<?php
/**
 * Soft stubs for free-plugin APIs added in DoubleScale ≥ 1.3.8.
 *
 * WordPress updates plugins one at a time. When Pro is newer than free, these
 * classes (and Settings helpers) may be missing. Defining stubs here keeps Pro
 * booting; the admin notice asks the site to update free.
 *
 * Only loaded when the real free class is absent — never overrides a real API.
 *
 * @package DoubleScale\Pro\Compat
 */

defined( 'ABSPATH' ) || exit;

// Autoload so a current free plugin wins; only stub when the class is truly absent.
if ( ! class_exists( \DoubleScale\Core\Services\CurrencyResolver::class ) ) {
	/**
	 * Temporary CurrencyResolver until free ≥ 1.3.8 is installed.
	 */
	class DoubleScale_Pro_Stub_CurrencyResolver {

		/**
		 * @param mixed $record Unused when free is outdated.
		 * @return string
		 */
		public static function resolve( $record ) {
			return self::global_currency();
		}

		/**
		 * @param mixed $currency Unused.
		 * @param mixed $sent_at  Unused.
		 * @return string
		 */
		public static function resolve_raw( $currency, $sent_at ) {
			unset( $sent_at );
			$code = is_string( $currency ) ? strtoupper( trim( $currency ) ) : '';
			return '' !== $code ? $code : self::global_currency();
		}

		/**
		 * @return string
		 */
		public static function global_currency() {
			if ( class_exists( \DoubleScale\Core\Settings\Settings::class, false )
				&& method_exists( \DoubleScale\Core\Settings\Settings::class, 'get_currency' ) ) {
				return strtoupper( (string) \DoubleScale\Core\Settings\Settings::get_currency() );
			}
			return 'USD';
		}

		/**
		 * @param mixed              $record Unused.
		 * @param array<int, string> $wanted Unused.
		 * @return bool
		 */
		public static function matches( $record, array $wanted ) {
			return empty( $wanted ) || in_array( self::resolve( $record ), $wanted, true );
		}

		/**
		 * @param mixed $currencies Raw filter.
		 * @return array<int, string>
		 */
		public static function normalize_list( $currencies ) {
			if ( is_string( $currencies ) ) {
				$currencies = explode( ',', $currencies );
			}
			if ( ! is_array( $currencies ) ) {
				return array();
			}
			$out = array();
			foreach ( $currencies as $currency ) {
				$code = strtoupper( trim( (string) $currency ) );
				if ( '' !== $code ) {
					$out[] = $code;
				}
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * @param iterable           $records       Models.
		 * @param string             $amount_column Column.
		 * @param array<int, string> $wanted        Filter.
		 * @return array<string, float>
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
		 * @param string $model_class Unused on outdated free.
		 * @return array<int, string>
		 */
		public static function available_for( $model_class ) {
			return array( self::global_currency() );
		}

		/**
		 * @param array<string, float> $map Totals.
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
	}

	class_alias( DoubleScale_Pro_Stub_CurrencyResolver::class, \DoubleScale\Core\Services\CurrencyResolver::class );
}

if ( ! class_exists( \DoubleScale\Core\Services\DocumentCurrency::class ) ) {
	/**
	 * Temporary DocumentCurrency until free ≥ 1.3.8 is installed.
	 */
	class DoubleScale_Pro_Stub_DocumentCurrency {

		/**
		 * @param mixed $raw Raw input.
		 * @return string|null|\WP_Error
		 */
		public static function sanitize_input( $raw ) {
			if ( null === $raw || '' === $raw ) {
				return null;
			}
			$code = strtoupper( trim( (string) $raw ) );
			return '' !== $code ? $code : null;
		}

		/**
		 * @param object $model Document model.
		 * @return void
		 */
		public static function freeze_on_send( $model ): void {
			if ( ! is_object( $model ) ) {
				return;
			}
			if ( empty( $model->currency )
				&& class_exists( \DoubleScale\Core\Settings\Settings::class, false )
				&& method_exists( \DoubleScale\Core\Settings\Settings::class, 'get_currency' ) ) {
				$model->currency = \DoubleScale\Core\Settings\Settings::get_currency();
			}
		}

		/**
		 * @param object $model             Document.
		 * @param mixed  $new_currency      Incoming.
		 * @param bool   $lock_when_settled Unused on stub.
		 * @return null
		 */
		public static function reject_if_locked( $model, $new_currency, bool $lock_when_settled = false ) {
			unset( $model, $new_currency, $lock_when_settled );
			return null;
		}
	}

	class_alias( DoubleScale_Pro_Stub_DocumentCurrency::class, \DoubleScale\Core\Services\DocumentCurrency::class );
}

if ( ! class_exists( \DoubleScale\Core\Database\NullableCurrencyColumn::class ) ) {
	/**
	 * Temporary NullableCurrencyColumn until free ≥ 1.3.8 is installed.
	 */
	class DoubleScale_Pro_Stub_NullableCurrencyColumn {

		/**
		 * @param string $logical_table Unused.
		 * @return void
		 */
		public static function ensure( string $logical_table ): void {
			unset( $logical_table );
		}
	}

	class_alias( DoubleScale_Pro_Stub_NullableCurrencyColumn::class, \DoubleScale\Core\Database\NullableCurrencyColumn::class );
}
