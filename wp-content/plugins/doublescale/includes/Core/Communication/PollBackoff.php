<?php
/**
 * Exponential backoff for recurring mailbox polls.
 *
 * A mailbox poll runs every minute. When the mailbox is unreachable (host
 * down, port blocked, wrong password, expired token) every attempt costs a
 * WordPress bootstrap plus a socket connect that can hang for the full
 * socket timeout — and the scheduler simply fires the next attempt a minute
 * later. Customers saw the poll "failing non-stop" and eating CPU.
 *
 * This keeps a per-poller failure counter and a "not before" timestamp:
 * after each consecutive failure the next real attempt is pushed out
 * 1 → 2 → 4 → … → 60 minutes. Attempts inside the window return early
 * before any work (no DB scan, no socket). The first success clears it.
 *
 * State lives in non-autoloaded options rather than transients so it
 * survives object-cache flushes and is visible in the Site Health report.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core\Communication;

defined( 'ABSPATH' ) || exit;

/**
 * Per-poller exponential backoff.
 */
final class PollBackoff {

	/** First delay after a failure, in seconds. */
	const BASE = MINUTE_IN_SECONDS;

	/** Longest delay between attempts, in seconds. */
	const MAX = HOUR_IN_SECONDS;

	/** @var string */
	private $key;

	/**
	 * @param string $name Poller identifier, e.g. 'imap_inbound' or 'user_imap_12'.
	 */
	public function __construct( $name ) {
		$this->key = 'doublescale_poll_backoff_' . sanitize_key( $name );
	}

	/**
	 * Whether the poller should skip this run.
	 *
	 * @return bool
	 */
	public function should_skip() {
		$state = $this->state();
		return $state['next_attempt'] > time();
	}

	/**
	 * Seconds until the next permitted attempt (0 when allowed now).
	 *
	 * @return int
	 */
	public function seconds_until_next() {
		return max( 0, $this->state()['next_attempt'] - time() );
	}

	/**
	 * Consecutive failures recorded so far.
	 *
	 * @return int
	 */
	public function failures() {
		return $this->state()['failures'];
	}

	/**
	 * Record a failure and push the next attempt out.
	 *
	 * @param string $reason Short error text, stored for diagnostics.
	 * @return int Delay applied, in seconds.
	 */
	public function record_failure( $reason = '' ) {
		$state    = $this->state();
		$failures = $state['failures'] + 1;
		$delay    = (int) min( self::MAX, self::BASE * ( 2 ** min( $failures - 1, 10 ) ) );

		/**
		 * Filter the backoff delay applied after a failed mailbox poll.
		 *
		 * @param int    $delay    Seconds until the next attempt.
		 * @param int    $failures Consecutive failure count including this one.
		 * @param string $key      Backoff option key.
		 */
		$delay = (int) apply_filters( 'doublescale_poll_backoff_delay', $delay, $failures, $this->key );

		update_option(
			$this->key,
			array(
				'failures'     => $failures,
				'next_attempt' => time() + $delay,
				'last_error'   => mb_substr( (string) $reason, 0, 500 ),
				'last_failure' => time(),
			),
			false
		);

		return $delay;
	}

	/**
	 * Clear the backoff after a successful poll.
	 */
	public function record_success() {
		if ( $this->state()['failures'] > 0 || get_option( $this->key, null ) !== null ) {
			delete_option( $this->key );
		}
	}

	/**
	 * Raw state for diagnostics.
	 *
	 * @return array{failures:int,next_attempt:int,last_error:string,last_failure:int}
	 */
	public function state() {
		$raw = get_option( $this->key, array() );
		return array(
			'failures'     => (int) ( $raw['failures'] ?? 0 ),
			'next_attempt' => (int) ( $raw['next_attempt'] ?? 0 ),
			'last_error'   => (string) ( $raw['last_error'] ?? '' ),
			'last_failure' => (int) ( $raw['last_failure'] ?? 0 ),
		);
	}
}
