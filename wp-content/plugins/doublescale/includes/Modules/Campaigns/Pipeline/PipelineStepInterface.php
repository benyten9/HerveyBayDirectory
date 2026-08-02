<?php

/**
 * Pipeline Step Interface
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
 * PipelineStepInterface
 *
 * Each step receives the shared context and a $next callable.
 * Call $next() to pass control to the following step.
 * Omit the $next() call to short-circuit the rest of the pipeline.
 */
interface PipelineStepInterface {

	/**
	 * Execute this pipeline step.
	 *
	 * @param CampaignContext $ctx  Shared processing state.
	 * @param callable        $next Invokes the next step in the pipeline.
	 */
	public function handle( CampaignContext $ctx, callable $next );
}
