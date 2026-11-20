<?php
/**
 * Normalize free-text country values to ISO 3166-1 alpha-2.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

/**
 * CountryCode helper.
 *
 * Contact `country` is free text: WooCommerce sync writes alpha-2 codes, manual
 * entry and CSV imports write whatever was typed ("UK", "Germany",
 * "Deutschland"). EN 16931 needs the alpha-2 code, so anything this class
 * cannot resolve is reported as a blocker rather than guessed.
 */
final class CountryCode {

	/**
	 * Every assigned ISO 3166-1 alpha-2 code.
	 */
	private const ALPHA2 = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW';

	/**
	 * Common alpha-3 codes, English/German names and colloquial aliases.
	 * Keys are lower-case with accents and punctuation removed.
	 *
	 * @var array<string, string>
	 */
	private const ALIASES = array(
		// Aliases that collide with, or are not, ISO codes.
		'uk'                       => 'GB',
		'el'                       => 'GR',
		// DACH + EU/EEA.
		'deu'                      => 'DE',
		'germany'                  => 'DE',
		'deutschland'              => 'DE',
		'aut'                      => 'AT',
		'austria'                  => 'AT',
		'osterreich'               => 'AT',
		'oesterreich'              => 'AT',
		'che'                      => 'CH',
		'switzerland'              => 'CH',
		'schweiz'                  => 'CH',
		'suisse'                   => 'CH',
		'bel'                      => 'BE',
		'belgium'                  => 'BE',
		'belgien'                  => 'BE',
		'bgr'                      => 'BG',
		'bulgaria'                 => 'BG',
		'bulgarien'                => 'BG',
		'hrv'                      => 'HR',
		'croatia'                  => 'HR',
		'kroatien'                 => 'HR',
		'cyp'                      => 'CY',
		'cyprus'                   => 'CY',
		'zypern'                   => 'CY',
		'cze'                      => 'CZ',
		'czech republic'           => 'CZ',
		'czechia'                  => 'CZ',
		'tschechien'               => 'CZ',
		'dnk'                      => 'DK',
		'denmark'                  => 'DK',
		'danemark'                 => 'DK',
		'est'                      => 'EE',
		'estonia'                  => 'EE',
		'estland'                  => 'EE',
		'fin'                      => 'FI',
		'finland'                  => 'FI',
		'finnland'                 => 'FI',
		'fra'                      => 'FR',
		'france'                   => 'FR',
		'frankreich'               => 'FR',
		'grc'                      => 'GR',
		'greece'                   => 'GR',
		'griechenland'             => 'GR',
		'hun'                      => 'HU',
		'hungary'                  => 'HU',
		'ungarn'                   => 'HU',
		'irl'                      => 'IE',
		'ireland'                  => 'IE',
		'irland'                   => 'IE',
		'ita'                      => 'IT',
		'italy'                    => 'IT',
		'italien'                  => 'IT',
		'lva'                      => 'LV',
		'latvia'                   => 'LV',
		'lettland'                 => 'LV',
		'ltu'                      => 'LT',
		'lithuania'                => 'LT',
		'litauen'                  => 'LT',
		'lux'                      => 'LU',
		'luxembourg'               => 'LU',
		'luxemburg'                => 'LU',
		'mlt'                      => 'MT',
		'malta'                    => 'MT',
		'nld'                      => 'NL',
		'netherlands'              => 'NL',
		'the netherlands'          => 'NL',
		'holland'                  => 'NL',
		'niederlande'              => 'NL',
		'pol'                      => 'PL',
		'poland'                   => 'PL',
		'polen'                    => 'PL',
		'prt'                      => 'PT',
		'portugal'                 => 'PT',
		'rou'                      => 'RO',
		'romania'                  => 'RO',
		'rumanien'                 => 'RO',
		'svk'                      => 'SK',
		'slovakia'                 => 'SK',
		'slowakei'                 => 'SK',
		'svn'                      => 'SI',
		'slovenia'                 => 'SI',
		'slowenien'                => 'SI',
		'esp'                      => 'ES',
		'spain'                    => 'ES',
		'spanien'                  => 'ES',
		'swe'                      => 'SE',
		'sweden'                   => 'SE',
		'schweden'                 => 'SE',
		'nor'                      => 'NO',
		'norway'                   => 'NO',
		'norwegen'                 => 'NO',
		'isl'                      => 'IS',
		'iceland'                  => 'IS',
		'island'                   => 'IS',
		'lie'                      => 'LI',
		'liechtenstein'            => 'LI',
		'gbr'                      => 'GB',
		'united kingdom'           => 'GB',
		'great britain'            => 'GB',
		'england'                  => 'GB',
		'scotland'                 => 'GB',
		'wales'                    => 'GB',
		'grossbritannien'          => 'GB',
		'vereinigtes konigreich'   => 'GB',
		// Major trading partners.
		'usa'                      => 'US',
		'us'                       => 'US',
		'united states'            => 'US',
		'united states of america' => 'US',
		'vereinigte staaten'       => 'US',
		'can'                      => 'CA',
		'canada'                   => 'CA',
		'kanada'                   => 'CA',
		'aus'                      => 'AU',
		'australia'                => 'AU',
		'australien'               => 'AU',
		'chn'                      => 'CN',
		'china'                    => 'CN',
		'jpn'                      => 'JP',
		'japan'                    => 'JP',
		'ind'                      => 'IN',
		'india'                    => 'IN',
		'indien'                   => 'IN',
		'tur'                      => 'TR',
		'turkey'                   => 'TR',
		'turkiye'                  => 'TR',
		'turkei'                   => 'TR',
		'rus'                      => 'RU',
		'russia'                   => 'RU',
		'russland'                 => 'RU',
		'ukr'                      => 'UA',
		'ukraine'                  => 'UA',
		'bra'                      => 'BR',
		'brazil'                   => 'BR',
		'brasilien'                => 'BR',
		'mex'                      => 'MX',
		'mexico'                   => 'MX',
		'mexiko'                   => 'MX',
		'zaf'                      => 'ZA',
		'south africa'             => 'ZA',
		'sudafrika'                => 'ZA',
		// MENA.
		'egy'                      => 'EG',
		'egypt'                    => 'EG',
		'agypten'                  => 'EG',
		'sau'                      => 'SA',
		'saudi arabia'             => 'SA',
		'saudi-arabien'            => 'SA',
		'saudi arabien'            => 'SA',
		'ksa'                      => 'SA',
		'are'                      => 'AE',
		'uae'                      => 'AE',
		'united arab emirates'     => 'AE',
		'vereinigte arabische emirate' => 'AE',
		'qat'                      => 'QA',
		'qatar'                    => 'QA',
		'katar'                    => 'QA',
		'kwt'                      => 'KW',
		'kuwait'                   => 'KW',
		'bhr'                      => 'BH',
		'bahrain'                  => 'BH',
		'omn'                      => 'OM',
		'oman'                     => 'OM',
		'jor'                      => 'JO',
		'jordan'                   => 'JO',
		'jordanien'                => 'JO',
		'mar'                      => 'MA',
		'morocco'                  => 'MA',
		'marokko'                  => 'MA',
		'tun'                      => 'TN',
		'tunisia'                  => 'TN',
		'tunesien'                 => 'TN',
		'dza'                      => 'DZ',
		'algeria'                  => 'DZ',
		'algerien'                 => 'DZ',
		'isr'                      => 'IL',
		'israel'                   => 'IL',
		'lbn'                      => 'LB',
		'lebanon'                  => 'LB',
		'libanon'                  => 'LB',
		'irq'                      => 'IQ',
		'iraq'                     => 'IQ',
		'irak'                     => 'IQ',
	);

	/**
	 * @param string|null $value Free-text country.
	 * @return string|null Alpha-2 code, or null when the value cannot be resolved.
	 */
	public static function to_alpha2( ?string $value ): ?string {
		$key = self::normalize( (string) $value );
		if ( '' === $key ) {
			return null;
		}

		if ( isset( self::ALIASES[ $key ] ) ) {
			return self::ALIASES[ $key ];
		}

		if ( 2 === strlen( $key ) ) {
			$upper = strtoupper( $key );
			if ( in_array( $upper, explode( ' ', self::ALPHA2 ), true ) ) {
				return $upper;
			}
		}

		return null;
	}

	/**
	 * Lower-case, strip accents and punctuation, collapse whitespace.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = strtr(
			$value,
			array(
				'ä' => 'a',
				'ö' => 'o',
				'ü' => 'u',
				'ß' => 'ss',
				'é' => 'e',
				'è' => 'e',
				'ç' => 'c',
				'Ä' => 'a',
				'Ö' => 'o',
				'Ü' => 'u',
			)
		);
		$value = (string) preg_replace( '/[.,()]/', '', $value );

		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}
}
