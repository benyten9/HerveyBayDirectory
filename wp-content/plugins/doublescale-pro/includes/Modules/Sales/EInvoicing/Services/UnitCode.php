<?php
/**
 * Map free-text line units to UN/ECE Recommendation 20 codes.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

/**
 * UnitCode helper.
 */
final class UnitCode {

	/**
	 * `C62` ("one") is the neutral unit every validator accepts.
	 */
	public const FALLBACK = 'C62';

	/**
	 * @var array<string, string>
	 */
	private const MAP = array(
		'h'         => 'HUR',
		'hr'        => 'HUR',
		'hrs'       => 'HUR',
		'hour'      => 'HUR',
		'hours'     => 'HUR',
		'std'       => 'HUR',
		'stunde'    => 'HUR',
		'stunden'   => 'HUR',
		'min'       => 'MIN',
		'minute'    => 'MIN',
		'minutes'   => 'MIN',
		'd'         => 'DAY',
		'day'       => 'DAY',
		'days'      => 'DAY',
		'tag'       => 'DAY',
		'tage'      => 'DAY',
		'week'      => 'WEE',
		'weeks'     => 'WEE',
		'woche'     => 'WEE',
		'wochen'    => 'WEE',
		'month'     => 'MON',
		'months'    => 'MON',
		'monat'     => 'MON',
		'monate'    => 'MON',
		'year'      => 'ANN',
		'years'     => 'ANN',
		'jahr'      => 'ANN',
		'jahre'     => 'ANN',
		'pc'        => 'H87',
		'pcs'       => 'H87',
		'piece'     => 'H87',
		'pieces'    => 'H87',
		'stk'       => 'H87',
		'stuck'     => 'H87',
		'stück'     => 'H87',
		'kg'        => 'KGM',
		'g'         => 'GRM',
		't'         => 'TNE',
		'l'         => 'LTR',
		'litre'     => 'LTR',
		'liter'     => 'LTR',
		'm'         => 'MTR',
		'meter'     => 'MTR',
		'metre'     => 'MTR',
		'km'        => 'KMT',
		'm2'        => 'MTK',
		'sqm'       => 'MTK',
		'm3'        => 'MTQ',
		'kwh'       => 'KWH',
		'set'       => 'SET',
		'pauschal'  => 'LS',
		'lump sum'  => 'LS',
		'flat'      => 'LS',
	);

	/**
	 * @param string|null $unit Free-text unit from the line item.
	 * @return string UN/ECE Rec 20 code.
	 */
	public static function from( ?string $unit ): string {
		$key = strtolower( trim( (string) $unit, " \t\n\r\0\x0B." ) );
		if ( '' === $key ) {
			return self::FALLBACK;
		}

		return self::MAP[ $key ] ?? self::FALLBACK;
	}
}
