<?php

/**
 * Campaign Processing Pipeline
 *
 * Executes an ordered list of PipelineStepInterface steps against a
 * shared CampaignContext.  Any step can halt the pipeline by either
 * calling $ctx->abort() or simply not calling $next().
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
 * CampaignProcessingPipeline class
 */
class CampaignProcessingPipeline {

	/** @var PipelineStepInterface[] */
	private $steps;

	/**
	 * @param PipelineStepInterface[] $steps Ordered list of steps.
	 */
	public function __construct( array $steps ) {
		$this->steps = array_values( $steps );
	}

	/**
	 * Run the pipeline from the first step.
	 *
	 * @param CampaignContext $ctx
	 */
	public function run( CampaignContext $ctx ) {
		$this->invoke( 0, $ctx );
	}

	/**
	 * Recursively invoke step at $index, passing a $next closure.
	 *
	 * @param int             $index
	 * @param CampaignContext $ctx
	 */
	private function invoke( $index, CampaignContext $ctx ) {
		if ( $ctx->aborted || $index >= count( $this->steps ) ) {
			return;
		}

		$step = $this->steps[ $index ];
		$self = $this;
		$next = function () use ( $self, $index, $ctx ) {
			$self->invoke( $index + 1, $ctx );
		};

		$step->handle( $ctx, $next );
	}
}
