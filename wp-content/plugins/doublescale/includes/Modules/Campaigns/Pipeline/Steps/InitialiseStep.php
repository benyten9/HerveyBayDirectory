<?php

/**
 * Initialise Step
 *
 * Populates the CampaignContext with the total contact count, the saved
 * offset, and the per-run rate/batch configuration before the main loop
 * begins.
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
 * InitialiseStep class
 */
class InitialiseStep implements PipelineStepInterface {

	/**
	 * @inheritDoc
	 */
	public function handle( CampaignContext $ctx, callable $next ) {
		// Sync total contact count with the campaign record.
		$count      = $ctx->contact_filter->get_contact_count( $ctx->channel, $ctx->filters );
		$ctx->total = $count;

		if ( $ctx->campaign->count !== $count ) {
			$ctx->campaign->count = $count;
			$ctx->campaign->save();
		}

		// Load and validate the persisted offset.
		$ctx->offset = (int) get_option( $ctx->offset_key, 0 );

		if ( $ctx->offset > $count ) {
			// Stale offset from a prior run or changed filters.
			$ctx->offset = 0;
			delete_option( $ctx->offset_key );
		}

		// Batch size (filterable, strategy may override).
		$ctx->batch_size = apply_filters( 'doublescale_campaign_batch_count', 100, $ctx->channel );

		// Per-second send cap: prefer campaign setting, fall back to channel default.
		$ctx->max_per_second = $ctx->settings['max_in_second']
			?? $ctx->rate_limiter->get_default_per_second_limit( $ctx->channel );

		// Reset the in-memory per-second counter.
		$ctx->rate_limiter->init_second_tracker( $ctx->channel );

		$next();
	}
}
