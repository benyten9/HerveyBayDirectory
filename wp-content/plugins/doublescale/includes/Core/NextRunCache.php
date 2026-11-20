<?php
/**
 * Remembers when scheduled jobs next run.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Recurring jobs check on every request that they are still scheduled
 * (`as_next_scheduled_action()`), which is one or two Action Scheduler
 * queries each. A job scheduled for a future time cannot have run before
 * then, so the answer is kept in one autoloaded option until that time, and
 * for at most an hour in case the job was cancelled outside DoubleScale.
 */
final class NextRunCache {

	const OPTION = 'doublescale_next_runs';

	/**
	 * Longest an answer is trusted, in seconds.
	 */
	const MAX_AGE = HOUR_IN_SECONDS;

	/**
	 * How long "overdue" or "running" is remembered. Low-traffic sites run
	 * WP-Cron rarely, so overdue actions are common; without this each request
	 * asked Action Scheduler again. Callers only reschedule on `false`, so the
	 * worst case is a missing action being rescheduled a few minutes later.
	 */
	const PENDING_AGE = 300; // 5 minutes.

	/**
	 * @var array<string, array{0:int, 1:int}>|null Key => [next run, checked at].
	 */
	private static $runs = null;

	/**
	 * @var bool
	 */
	private static $dirty = false;

	/**
	 * When a job next runs: a timestamp, true while it runs, false when it
	 * is not scheduled. Same as as_next_scheduled_action().
	 *
	 * @param string     $hook  Hook.
	 * @param array|null $args  Args, or null for any.
	 * @param string     $group Group.
	 *
	 * @return int|bool
	 */
	public static function get( $hook, $args = null, $group = '' ) {
		self::load();
		$key = self::key( $hook, $args, $group );
		$now = time();

		if ( isset( self::$runs[ $key ] ) ) {
			list( $next, $checked ) = self::$runs[ $key ];
			$future = is_int( $next ) && $next > $now;
			if ( $now - $checked < ( $future ? self::MAX_AGE : self::PENDING_AGE ) ) {
				return $next;
			}
			unset( self::$runs[ $key ] );
			self::save_later();
		}

		$next = as_next_scheduled_action( $hook, $args, $group );
		// Remember any existing action: upcoming, overdue (past timestamp) or
		// running (true). Never remember "none" (false): callers schedule then.
		if ( true === $next || ( is_int( $next ) && $next > 0 ) ) {
			self::$runs[ $key ] = array( $next, $now );
			self::save_later();
		}

		return $next;
	}

	/**
	 * Forget what is known about a hook (after unscheduling it).
	 *
	 * @param string $hook Hook.
	 */
	public static function forget( $hook ) {
		self::load();
		foreach ( array_keys( self::$runs ) as $key ) {
			if ( 0 === strpos( $key, $hook . '|' ) ) {
				unset( self::$runs[ $key ] );
				self::save_later();
			}
		}
	}

	/**
	 * Forget everything (plugin deactivated).
	 */
	public static function flush() {
		self::$runs  = array();
		self::$dirty = false;
		delete_option( self::OPTION );
	}

	/**
	 * Write the changes once, at the end of the request.
	 */
	public static function save() {
		if ( self::$dirty ) {
			self::$dirty = false;
			update_option( self::OPTION, self::$runs, true );
		}
	}

	private static function load() {
		if ( null === self::$runs ) {
			$runs       = get_option( self::OPTION, array() );
			self::$runs = is_array( $runs ) ? $runs : array();
		}
	}

	private static function save_later() {
		if ( ! self::$dirty ) {
			self::$dirty = true;
			add_action( 'shutdown', array( __CLASS__, 'save' ) );
		}
	}

	private static function key( $hook, $args, $group ) {
		return $hook . '|' . $group . '|' . ( null === $args ? '*' : md5( wp_json_encode( $args ) ) );
	}
}
