<?php

/**
 * Check Completion Step
 *
 * Short-circuits the pipeline when the campaign is already finished
 * (offset >= total) before the processing loop even starts.
 * This handles the case where a continuation is triggered after the
 * last batch was already committed.
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
 * CheckCompletionStep class
 */
class CheckCompletionStep implements PipelineStepInterface {

	/**
	 * @inheritDoc
	 */
	public function handle( CampaignContext $ctx, callable $next ) {
		// An audience of nobody is a terminal state, not work in progress.
		//
		// `total` is resolved in InitialiseStep, the step immediately before
		// this one, and a failed resolution throws — which halts the pipeline
		// before this step runs at all. So reaching here with `total === 0`
		// can only mean the audience resolved successfully to nobody; it is
		// never an uninitialised default.
		//
		// Without this, is_complete() (`total > 0 && …`) could never be
		// satisfied, so the pipeline queued a continuation, the continuation
		// found the offset unmoved, and after five cycles the no-progress
		// watchdog ended the campaign with "no progress after 5 consecutive
		// attempts" — a diagnosis meant for a stuck send, applied to a
		// campaign that never had anything to send.
		if ( 0 === (int) $ctx->total ) {
			$ctx->fail(
				'no_recipients',
				__( 'Campaign failed: no contacts matched its audience.', 'doublescale' )
			);
			return;
		}

		if ( $ctx->is_complete() ) {
			// Marks campaign completed and sets ctx->aborted = true.
			$ctx->complete();
			return;
		}

		$next();
	}
}
