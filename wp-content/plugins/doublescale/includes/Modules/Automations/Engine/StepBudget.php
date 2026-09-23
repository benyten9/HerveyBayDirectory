<?php
/**
 * Execution budget for running consecutive automation steps in one request.
 *
 * Every step used to be its own Action Scheduler action: a 5-step
 * automation cost five queued actions, five meta rows and five runner
 * claims per contact, and a contact's journey advanced one step per queue
 * tick. Most steps are cheap (tag, field update, condition, end); only
 * delays and goals genuinely need to wait.
 *
 * A queue handler opens a budget for the request; while it lasts,
 * {@see \DoubleScale\Modules\Automations\ProcessAutomation::enqueue_step()}
 * runs the next eligible step immediately instead of queueing it. When the
 * budget is spent — time, depth or a step that must wait — the remaining
 * work is queued exactly as before, so nothing depends on the budget for
 * correctness. Enrolments created before this change continue from their
 * queued step with no migration.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Per-request budget for inline step execution.
 */
final class StepBudget {

	/** Longest chain of steps run inline for one contact per request. */
	const MAX_DEPTH = 25;

	/** @var float|null Wall-clock deadline (microtime) or null when no budget is open. */
	private static $deadline = null;

	/** @var int Steps currently being run inline (recursion depth). */
	private static $depth = 0;

	/** @var int Steps run inline during the current budget (diagnostics). */
	private static $inline_steps = 0;

	/**
	 * Run a callback with an inline budget open, then close it.
	 *
	 * Nested calls reuse the outer budget.
	 *
	 * @param callable   $callback Work that processes automation steps.
	 * @param float|null $seconds  Budget length; default from settings/filters.
	 * @return mixed
	 */
	public static function run( callable $callback, ?float $seconds = null ) {
		if ( null !== self::$deadline ) {
			return $callback();
		}

		self::$deadline     = microtime( true ) + ( null === $seconds ? self::default_seconds() : $seconds );
		self::$depth        = 0;
		self::$inline_steps = 0;
		try {
			return $callback();
		} finally {
			self::$deadline = null;
			self::$depth    = 0;
		}
	}

	/**
	 * Default inline budget: a slice of PHP's execution limit, capped so a
	 * queue action never approaches Action Scheduler's per-request budget.
	 *
	 * @return float
	 */
	public static function default_seconds(): float {
		$max_exec = (int) ini_get( 'max_execution_time' );
		$slice    = $max_exec > 0 ? $max_exec * 0.3 : 8.0;
		$seconds  = (float) apply_filters( 'doublescale_automation_inline_budget', min( 8.0, max( 2.0, $slice ) ) );
		return max( 0.0, $seconds );
	}

	/**
	 * Whether another step may run inline right now.
	 *
	 * @return bool
	 */
	public static function can_run_inline(): bool {
		if ( null === self::$deadline ) {
			return false;
		}
		if ( self::$depth >= self::MAX_DEPTH ) {
			return false;
		}
		return microtime( true ) < self::$deadline;
	}

	/**
	 * Run one step inline, tracking depth.
	 *
	 * @param callable $step Callback that processes the step.
	 */
	public static function inline( callable $step ): void {
		++self::$depth;
		++self::$inline_steps;
		try {
			$step();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Steps run inline under the current (or last) budget.
	 *
	 * @return int
	 */
	public static function inline_steps(): int {
		return self::$inline_steps;
	}

	/**
	 * Whether a budget is currently open.
	 *
	 * @return bool
	 */
	public static function is_open(): bool {
		return null !== self::$deadline;
	}

	/**
	 * Tests only.
	 */
	public static function reset(): void {
		self::$deadline     = null;
		self::$depth        = 0;
		self::$inline_steps = 0;
	}
}
