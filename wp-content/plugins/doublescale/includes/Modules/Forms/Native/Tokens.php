<?php
/**
 * Signed tokens for DoubleScale forms (no database rows):
 *
 * - Prefill: "this contact, this form, until this time" — put in {{forms:link}} links so the
 *   form can fill in the contact's details. Scoped to one form, expires, cannot be forged.
 * - Issued: "this form was handed out at this time" — the time trap of the spam check uses
 *   it instead of a duration the browser reports.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC tokens.
 */
final class Tokens {

	public const PREFILL_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * A prefill token for a contact and a form.
	 *
	 * @param int $contact_id Contact id.
	 * @param int $form_id    Form id.
	 * @param int $ttl        Seconds it stays valid.
	 * @return string
	 */
	public static function prefill( int $contact_id, int $form_id, int $ttl = self::PREFILL_TTL ): string {
		$data = $contact_id . '.' . $form_id . '.' . ( time() + $ttl );
		return self::encode( $data ) . '.' . self::sign( 'prefill|' . $data );
	}

	/**
	 * The contact id a prefill token is for, or 0 when it is forged, expired or for another form.
	 *
	 * @param string $token   Token.
	 * @param int    $form_id Form the visitor is on.
	 * @return int
	 */
	public static function prefill_contact( string $token, int $form_id ): int {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return 0;
		}
		$data = self::decode( $parts[0] );
		if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $data, $m ) ) {
			return 0;
		}
		if ( ! hash_equals( self::sign( 'prefill|' . $data ), $parts[1] ) ) {
			return 0;
		}
		if ( (int) $m[2] !== $form_id || (int) $m[3] < time() ) {
			return 0;
		}
		return (int) $m[1];
	}

	/**
	 * When a form was handed out, signed.
	 *
	 * @param int      $form_id Form id.
	 * @param int|null $at      Time (now by default).
	 * @return string
	 */
	public static function issued( int $form_id, ?int $at = null ): string {
		$at = null === $at ? time() : $at;
		return $at . '.' . self::sign( 'issued|' . $form_id . '|' . $at );
	}

	/**
	 * Seconds since a form was handed out, or null when the token is missing or forged.
	 *
	 * @param string $token   Token.
	 * @param int    $form_id Form id.
	 * @return int|null
	 */
	/** A page older than this is treated as stale for sending (the form gets a fresh token). */
	public const ISSUED_TTL = 7 * DAY_IN_SECONDS;

	/** Older than this, a token is no good at all (uploads, unfinished entries). */
	public const ISSUED_MAX = 30 * DAY_IN_SECONDS;

	public static function issued_age( string $token, int $form_id ): ?int {
		if ( ! preg_match( '/^(\d{9,11})\.([A-Za-z0-9_\-]{20,})$/', $token, $m ) ) {
			return null;
		}
		if ( ! hash_equals( self::sign( 'issued|' . $form_id . '|' . $m[1] ), $m[2] ) ) {
			return null;
		}
		return time() - (int) $m[1];
	}

	/**
	 * HMAC-SHA256, URL-safe, shortened to 32 characters.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	private static function sign( string $data ): string {
		return substr( self::encode( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) . 'doublescale-forms', true ) ), 0, 32 );
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	private static function encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token encoding, not obfuscation.
	}

	/**
	 * Decode URL-safe base64.
	 *
	 * @param string $data Encoded.
	 * @return string
	 */
	private static function decode( string $data ): string {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe token decoding.
		return false === $decoded ? '' : $decoded;
	}
}
