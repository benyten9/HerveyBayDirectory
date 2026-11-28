<?php
/**
 * Background wake-up interface; durable state remains in AI Pro tables.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

/** Queue uniqueness is an optimization, never effect idempotency. */
interface WakeQueue {
	/**
	 * Schedule a due run once logically.
	 *
	 * @param string $run_id Opaque run ID.
	 */
	public function enqueue( string $run_id ): bool;

	/**
	 * Schedule a future wake.
	 *
	 * @param string $run_id Opaque run ID.
	 * @param int    $timestamp Earliest Unix timestamp.
	 */
	public function schedule( string $run_id, int $timestamp ): bool;

	/**
	 * Determine whether a pending or running wake exists.
	 *
	 * @param string $run_id Opaque run ID.
	 */
	public function has( string $run_id ): bool;

	/**
	 * Remove pending wakes for a terminal or cancelled run.
	 *
	 * @param string $run_id Opaque run ID.
	 */
	public function unschedule( string $run_id ): void;

	/** Ensure recovery and lifecycle sweeps are scheduled. */
	public function ensure_recurring(): void;

	/** Return current queue availability/version diagnostics. */
	public function health(): array;
}
