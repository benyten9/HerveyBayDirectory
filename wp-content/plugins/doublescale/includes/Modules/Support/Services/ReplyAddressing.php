<?php
/**
 * ReplyAddressing — the thread-stable markers that tie an outbound support email
 * to its ticket and let an inbound reply find that ticket again.
 *
 * Two markers are produced on every outbound message and read back on inbound:
 *
 *   1. Reply-To plus sub-address — `local+ticket-{id}-{token}@domain`. This is
 *      the PRIMARY signal because it survives providers that rewrite the
 *      Message-ID on relay (Gmail/Outlook do). The customer's client replies to
 *      the Reply-To, so the inbound `To:` carries the ticket id; mailbox routing
 *      still resolves because the `+tag` is a deliverable sub-address of the
 *      mailbox inbox (`user+x@gmail.com` is delivered to `user@gmail.com`).
 *   2. Structured Message-ID — `<doublescale-support-{id}-…@host>`. A secondary
 *      signal for providers that DO preserve the Message-ID, matched through the
 *      reply's In-Reply-To.
 *
 * `{token}` is the leading hex of the ticket's public `hash`. The inbound parser
 * verifies it against the loaded ticket before trusting the id, so a customer
 * cannot thread into an arbitrary ticket by editing the id in the address.
 *
 * Free owns this format because Free composes the outbound mail; Pro's inbound
 * factory consumes the same class so the two halves can never drift apart.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ReplyAddressing class.
 */
final class ReplyAddressing {

	/**
	 * Number of leading hash characters used as the per-ticket token. Long enough
	 * that it can't be guessed, short enough to keep the address tidy.
	 *
	 * @var int
	 */
	private const TOKEN_LENGTH = 10;

	/**
	 * Build the threaded Reply-To for a ticket's outbound mail.
	 *
	 * Returns the unmodified address when it can't be plus-tagged safely (no `@`,
	 * an address that already carries a `+` sub-address, or a ticket with no usable
	 * hash) — the outbound mail still sends, threading just falls back to the
	 * Message-ID / other matchers.
	 *
	 * @param string $from_email   Mailbox sending address (the inbox replies land in).
	 * @param int    $ticket_id    Ticket id.
	 * @param string $ticket_hash  Ticket public hash (token source).
	 * @return string Reply-To address.
	 */
	public static function build_reply_to( string $from_email, int $ticket_id, string $ticket_hash ): string {
		$at = strrpos( $from_email, '@' );
		if ( false === $at || $ticket_id <= 0 ) {
			return $from_email;
		}

		$local  = substr( $from_email, 0, $at );
		$domain = substr( $from_email, $at );

		// Never stack a second sub-address on an already plus-tagged inbox.
		if ( false !== strpos( $local, '+' ) ) {
			return $from_email;
		}

		$token = self::token( $ticket_hash );
		if ( '' === $token ) {
			return $from_email;
		}

		return $local . '+ticket-' . $ticket_id . '-' . $token . $domain;
	}

	/**
	 * Build the structured outbound Message-ID for a ticket.
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param string $host      Site host for the Message-ID domain part.
	 * @return string Full `<…>` Message-ID.
	 */
	public static function build_message_id( int $ticket_id, string $host ): string {
		$host = '' !== $host ? $host : 'localhost';
		$uniq = function_exists( 'wp_generate_password' )
			? strtolower( wp_generate_password( 16, false ) )
			: substr( md5( uniqid( (string) $ticket_id, true ) ), 0, 16 );

		return sprintf( '<doublescale-support-%d-%s@%s>', $ticket_id, $uniq, $host );
	}

	/**
	 * Parse a `+ticket-{id}-{token}` marker out of an inbound recipient address.
	 *
	 * @param string $to_email Inbound `To:` / recipient address.
	 * @return array{ticket_id:int, token:string}|null Parsed parts, or null when absent.
	 */
	public static function parse_recipient( string $to_email ): ?array {
		if ( '' === $to_email || false === strpos( $to_email, '+' ) ) {
			return null;
		}
		if ( ! preg_match( '/\+ticket-(\d+)-([a-z0-9]+)@/i', $to_email, $m ) ) {
			return null;
		}
		return array(
			'ticket_id' => (int) $m[1],
			'token'     => strtolower( $m[2] ),
		);
	}

	/**
	 * The per-ticket token: the leading hex of the ticket hash, lower-cased.
	 *
	 * @param string $ticket_hash Ticket public hash.
	 * @return string Token, or '' when the hash has no usable hex.
	 */
	public static function token( string $ticket_hash ): string {
		$hex = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', $ticket_hash ) );
		return substr( $hex, 0, self::TOKEN_LENGTH );
	}

	/**
	 * Whether a parsed token matches the ticket it claims to belong to.
	 * Constant-time compare so a near-miss token can't be probed by timing.
	 *
	 * @param string $ticket_hash Ticket public hash (the truth).
	 * @param string $token       Token parsed from the inbound address.
	 * @return bool
	 */
	public static function token_matches( string $ticket_hash, string $token ): bool {
		$expected = self::token( $ticket_hash );
		return '' !== $expected && hash_equals( $expected, strtolower( $token ) );
	}
}
