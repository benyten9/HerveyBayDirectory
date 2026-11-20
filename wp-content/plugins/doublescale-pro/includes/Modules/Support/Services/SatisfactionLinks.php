<?php
/**
 * Signed rating links for a ticket, and the five faces they go in.
 *
 * A link is `?ds_rate=<token>&score=<1-5>` on the home page. The token is
 * base64url("<ticket id>.<expires>") + "." + an HMAC of id, expiry and the
 * ticket's own hash (wp_salt('auth')): it cannot be made for another ticket,
 * stops working when it expires, and every old link stops when the ticket's
 * hash changes. The score is chosen on the page, so it is not signed.
 *
 * The faces end the customer's "solved / closed" email (free's
 * `doublescale_support_status_email_body`), while the owner has it on.
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Models\TicketModel;
use WP_Error;

/**
 * SatisfactionLinks class.
 */
final class SatisfactionLinks {

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'doublescale_support_status_email_body', array( self::class, 'append_to_email' ), 10, 3 );
	}

	/**
	 * The five faces, worst first: score => [face, word].
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function faces(): array {
		return array(
			1 => array( '😞', __( 'Very bad', 'doublescale' ) ),
			2 => array( '🙁', __( 'Bad', 'doublescale' ) ),
			3 => array( '😐', __( 'Okay', 'doublescale' ) ),
			4 => array( '🙂', __( 'Good', 'doublescale' ) ),
			5 => array( '😄', __( 'Great', 'doublescale' ) ),
		);
	}

	/**
	 * @param TicketModel $ticket  Ticket.
	 * @param int|null    $expires Unix time it stops working (null: the setting's days from now).
	 * @return string
	 */
	public static function token( TicketModel $ticket, ?int $expires = null ): string {
		$expires = null !== $expires ? $expires : time() + SatisfactionSettings::get()['days'] * DAY_IN_SECONDS;

		return self::b64( $ticket->id . '.' . $expires ) . '.' . self::sign( (int) $ticket->id, $expires, (string) $ticket->hash );
	}

	/**
	 * The ticket a token is for, or why not: csat_invalid (forged, broken,
	 * another ticket's, an old hash) or csat_expired (a real link, too old).
	 *
	 * @param string $token Token from the link.
	 * @return TicketModel|WP_Error
	 */
	public static function verify( string $token ) {
		$invalid = new WP_Error( 'csat_invalid', __( 'This rating link is not valid.', 'doublescale' ), array( 'status' => 400 ) );
		$parts   = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return $invalid;
		}
		$payload = explode( '.', (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ) );
		if ( 2 !== count( $payload ) || ! ctype_digit( $payload[0] ) || ! ctype_digit( $payload[1] ) ) {
			return $invalid;
		}
		$ticket = TicketModel::find( (int) $payload[0] );
		if ( ! $ticket || ! hash_equals( self::sign( (int) $ticket->id, (int) $payload[1], (string) $ticket->hash ), $parts[1] ) ) {
			return $invalid;
		}
		if ( (int) $payload[1] < time() ) {
			return new WP_Error( 'csat_expired', __( 'This rating link has expired.', 'doublescale' ), array( 'status' => 410 ) );
		}

		return $ticket;
	}

	/**
	 * @param string $token Token.
	 * @param int    $score 1 to 5.
	 * @return string
	 */
	public static function url( string $token, int $score ): string {
		return add_query_arg(
			array(
				'ds_rate' => rawurlencode( $token ),
				'score'   => $score,
			),
			home_url( '/' )
		);
	}

	/**
	 * End the customer's "solved / closed" email with the five faces.
	 *
	 * @param string $body   Email body HTML.
	 * @param mixed  $ticket Ticket.
	 * @param string $status New status.
	 * @return string
	 */
	public static function append_to_email( $body, $ticket, $status = '' ) {
		unset( $status );
		if ( ! $ticket instanceof TicketModel || ! SatisfactionSettings::available() || ! SatisfactionSettings::get()['enabled'] ) {
			return $body;
		}
		$token = self::token( $ticket );
		$cells = '';
		foreach ( self::faces() as $score => $face ) {
			$cells .= '<td style="padding:0 4px;text-align:center"><a href="' . esc_url( self::url( $token, $score ) ) . '" style="display:inline-block;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;color:#374151;font-size:13px" title="' . esc_attr( $face[1] ) . '"><span style="font-size:26px;line-height:1">' . $face[0] . '</span><br>' . esc_html( $face[1] ) . '</a></td>';
		}

		return (string) $body
			. '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb">'
			. '<p style="margin:0 0 10px;font-weight:600">' . esc_html__( 'How did we do?', 'doublescale' ) . '</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table>'
			. '</div>';
	}

	/**
	 * @param int    $id      Ticket id.
	 * @param int    $expires Expiry.
	 * @param string $hash    Ticket hash.
	 * @return string
	 */
	private static function sign( int $id, int $expires, string $hash ): string {
		return self::b64( hash_hmac( 'sha256', $id . '|' . $expires . '|' . $hash, wp_salt( 'auth' ), true ) );
	}

	/**
	 * @param string $raw Bytes.
	 * @return string
	 */
	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
