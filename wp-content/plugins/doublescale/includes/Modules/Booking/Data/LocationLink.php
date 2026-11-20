<?php
/**
 * Where a booking's location opens: the meeting, a map, or a call.
 *
 * One rule for every page that shows "Where" (the public confirm page and the
 * client portal). The host's address opens a map and their phone a call; the
 * client's own address or phone (the host comes to them, or calls them) is
 * not linked: a call link to yourself is no help.
 *
 * @package DoubleScale\Modules\Booking\Data
 */

namespace DoubleScale\Modules\Booking\Data;

defined( 'ABSPATH' ) || exit;

final class LocationLink {

	const MEETING = 'meeting';
	const MAP     = 'map';
	const CALL    = 'call';

	/**
	 * Types whose value may be a link to the meeting. A custom place keeps
	 * whatever the host wrote; a location stored as a bare string has no type.
	 */
	const MEETING_TYPES = array( 'online', 'zoom', 'ms-teams', 'google-meet', 'custom', '' );

	/**
	 * The link of a location, or null when it has none.
	 *
	 * @param string $type  Location type ("google-meet", "person_phone", …).
	 * @param string $value What the booking stored: link, address or number.
	 * @return array{kind: string, url: string}|null
	 */
	public static function for_location( string $type, string $value ): ?array {
		$type  = strtolower( trim( $type ) );
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( 'person_address' === $type ) {
			return array(
				'kind' => self::MAP,
				'url'  => self::is_web_link( $value )
					? esc_url_raw( $value )
					: 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $value ),
			);
		}

		if ( 'person_phone' === $type ) {
			$digits = preg_replace( '/\D+/', '', $value );
			if ( strlen( $digits ) < 3 ) {
				return null;
			}
			$plus = 0 === strpos( $value, '+' ) || 0 === strpos( $value, '00' ) ? '+' : '';
			if ( '+' === $plus && 0 === strpos( $value, '00' ) ) {
				$digits = substr( $digits, 2 );
			}

			return array(
				'kind' => self::CALL,
				'url'  => 'tel:' . $plus . $digits,
			);
		}

		if ( in_array( $type, self::MEETING_TYPES, true ) && self::is_web_link( $value ) ) {
			return array(
				'kind' => self::MEETING,
				'url'  => esc_url_raw( $value ),
			);
		}

		return null;
	}

	/**
	 * An http(s) link, judged by its form alone. Not wp_http_validate_url():
	 * that one looks the host up in DNS (it guards requests the server makes),
	 * so a server that cannot resolve the host lost the join link, and a list
	 * of bookings paid one lookup each.
	 */
	private static function is_web_link( string $value ): bool {
		if ( false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true );
	}
}
