<?php

/**
 * Schedule Continuation Step
 *
 * Final pipeline step: completes the campaign if all contacts have been
 * processed, or queues a continuation run for the next cron/AJAX tick.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Pipeline\Steps;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Campaigns\Pipeline\PipelineStepInterface;
use DoubleScale\Modules\Campaigns\Pipeline\CampaignContext;

/**
 * ScheduleContinuationStep class
 */
class ScheduleContinuationStep implements PipelineStepInterface {

	/**
	 * @inheritDoc
	 */
	public function handle( CampaignContext $ctx, callable $next ) {
		if ( $ctx->total > 0 && $ctx->offset >= $ctx->total && $ctx->has_deferred_retries() ) {
			$ctx->abort();
			return;
		}

		if ( $ctx->is_complete() ) {
			$ctx->complete();
		} else {
			$ctx->queue_continuation();
		}
		// Terminal step – $next() is intentionally not called.
	}
}
