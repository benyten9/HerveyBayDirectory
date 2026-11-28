<?php
/**
 * Exact import scheduling effect, separate from row outcomes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\{EffectOutcome, EffectPlan, EffectTarget, NativeEffect};

/** A successful launch receipt says queued, never that listings were created. */
final class LaunchEffect implements NativeEffect {
	public function __construct( private ImportService $service, private Context $context, private string $job_id ) {}
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$this->service->queue_launch( $this->context, $this->job_id, $plan );
		return $this->reconcile( $plan, $target, $fence );
	}
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$job = $this->service->authorize_launch( $this->context, $this->job_id, $plan );
		if ( 'reviewed' === $job['state'] ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'launch_outcome_unavailable' ); }
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			$job['schema_hash'],
			(object) array(
				'job_id'             => $this->job_id,
				'queued'             => true,
				'listings_completed' => false,
				'record_count'       => count( $job['manifest']['approved_manifest']['record_hashes'] ),
			)
		);
	}
}
