<?php
/**
 * Collect trigger enrolments fired inside a bulk operation into batch jobs.
 *
 * A bulk action (add tag to 5,000 matching contacts, CSV import, …) fires
 * the per-contact hook once per contact — and must keep doing so, because
 * lead scoring, activities and other listeners depend on it. Left alone,
 * each of those hook calls made the automation trigger write one queue
 * action plus one meta row, so the bulk request spent most of its time
 * inserting 5,000 Action Scheduler rows.
 *
 * Wrapping the bulk loop in {@see self::run()} tells {@see Trigger::process()}
 * to hand its (automation_id, packed args) pairs to this collector instead.
 * On exit the collector groups them by automation and enqueues one
 * `process_automations_batch` action per {@see self::CHUNK} contacts. The
 * batch handler then performs exactly the enrolment the per-contact action
 * would have — same ContactEnrollment, same data snapshot per contact, same
 * first-step enqueue — so conditions and merge tags see no difference.
 *
 * Outside a batch scope nothing changes: single-contact triggers still
 * enqueue `process_automations` as before.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Engine;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\PluginKernel;

/**
 * Batch collector for trigger enrolments.
 */
final class TriggerBatch {

	/** Contacts per batch action. */
	const CHUNK = 100;

	/** Hook name the batch handler is registered under. */
	const HOOK = 'process_automations_batch';

	/** @var int Nesting depth of run()/begin() scopes. */
	private static $depth = 0;

	/** @var array<int, array<int, array>> automation_id => list of packed args. */
	private static $items = array();

	/**
	 * Queue function: fn( string $hook, mixed ...$args ): int|false.
	 * Null means the kernel's automations task queue. Tests inject a recorder.
	 *
	 * @var callable|null
	 */
	private static $enqueuer = null;

	/**
	 * Replace the queue function (tests). Pass null to restore the default.
	 *
	 * @param callable|null $enqueuer fn( $hook, ...$args ): int|false.
	 */
	public static function set_enqueuer( $enqueuer ) {
		self::$enqueuer = is_callable( $enqueuer ) ? $enqueuer : null;
	}

	/**
	 * @param string $hook Hook.
	 * @param mixed  ...$args Args.
	 * @return int|false
	 */
	private static function enqueue( $hook, ...$args ) {
		if ( null !== self::$enqueuer ) {
			return call_user_func( self::$enqueuer, $hook, ...$args );
		}
		return PluginKernel::instance()->automations_tasks->enqueue_async( $hook, ...$args );
	}

	/**
	 * Whether triggers should collect instead of enqueue.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::$depth > 0;
	}

	/**
	 * Run a callback with collection on; flush when the outermost scope ends.
	 *
	 * The flush runs in `finally`, so an exception inside the callback still
	 * enqueues whatever was collected before it — a half-tagged bulk action
	 * must not silently lose the enrolments that did happen.
	 *
	 * @param callable $callback Work that fires triggers.
	 * @return mixed The callback's return value.
	 */
	public static function run( callable $callback ) {
		self::begin();
		try {
			return $callback();
		} finally {
			self::end();
		}
	}

	/**
	 * Open a collection scope (nestable).
	 */
	public static function begin() {
		++self::$depth;
	}

	/**
	 * Close a collection scope; the outermost close flushes.
	 *
	 * @return int Number of batch actions enqueued by this close (0 when nested).
	 */
	public static function end() {
		if ( self::$depth <= 0 ) {
			self::$depth = 0;
			return 0;
		}
		--self::$depth;
		if ( 0 === self::$depth ) {
			return self::flush();
		}
		return 0;
	}

	/**
	 * Record one enrolment to be queued at flush time.
	 *
	 * @param int   $automation_id Automation id.
	 * @param array $args          Packed trigger args (see TriggerPayload::pack()).
	 */
	public static function collect( $automation_id, array $args ) {
		$automation_id = (int) $automation_id;
		if ( $automation_id <= 0 ) {
			return;
		}
		if ( ! isset( self::$items[ $automation_id ] ) ) {
			self::$items[ $automation_id ] = array();
		}
		self::$items[ $automation_id ][] = $args;
	}

	/**
	 * Number of enrolments currently collected.
	 *
	 * @return int
	 */
	public static function pending_count() {
		$n = 0;
		foreach ( self::$items as $list ) {
			$n += count( $list );
		}
		return $n;
	}

	/**
	 * Enqueue everything collected, one action per automation per CHUNK.
	 *
	 * When the batch action cannot be queued (scheduler unavailable) the
	 * items fall back to individual `process_automations` actions so no
	 * enrolment is lost — only the optimisation is.
	 *
	 * @return int Batch actions enqueued.
	 */
	public static function flush() {
		$items       = self::$items;
		self::$items = array();

		if ( empty( $items ) ) {
			return 0;
		}

		$enqueued = 0;

		foreach ( $items as $automation_id => $list ) {
			foreach ( array_chunk( $list, self::CHUNK ) as $chunk ) {
				$action_id = self::enqueue( self::HOOK, $automation_id, $chunk );
				if ( $action_id ) {
					++$enqueued;
					continue;
				}

				doublescale_get_logger()->error(
					'Automation batch could not be queued; falling back to per-contact actions',
					array(
						'code'          => 'automation_batch_enqueue_failed',
						'automation_id' => $automation_id,
						'contacts'      => count( $chunk ),
					)
				);
				foreach ( $chunk as $args ) {
					self::enqueue( 'process_automations', $automation_id, $args );
				}
			}
		}

		return $enqueued;
	}

	/**
	 * Drop collected items and reset depth. Tests only.
	 */
	public static function reset() {
		self::$depth    = 0;
		self::$items    = array();
		self::$enqueuer = null;
	}
}
