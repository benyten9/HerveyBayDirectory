<?php

/**
 * Batch Dispatch Result
 *
 * Value object returned by BatchDispatchStrategyInterface::process_batch().
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Pipeline;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BatchResult class
 */
class BatchResult {

	/** @var bool True when processing should stop (time limit, memory, non-fatal error). */
	public $stop = false;

	/** @var bool True when the campaign must be marked failed and processing halted. */
	public $fatal = false;

	/**
	 * Successful batch – continue looping.
	 */
	public static function ok() {
		return new self();
	}

	/**
	 * Stop the current processing run; resume via continuation.
	 */
	public static function stop() {
		$r       = new self();
		$r->stop = true;
		return $r;
	}

	/**
	 * Unrecoverable error; mark campaign failed and halt.
	 */
	public static function fatal() {
		$r        = new self();
		$r->fatal = true;
		return $r;
	}
}
