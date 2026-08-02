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
		if ( $ctx->is_complete() ) {
			// Marks campaign completed and sets ctx->aborted = true.
			$ctx->complete();
			return;
		}

		$next();
	}
}
