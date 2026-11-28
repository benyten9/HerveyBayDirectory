<?php
/**
 * Server-issued one-operation execution permit.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Approvals;

use DirectoristAIPro\Effects\EffectPlan;

/** This object is never accepted as model or transport input. */
final class ExecutionPermit {
	/**
	 * Exact reconstructed approved plan.
	 *
	 * @var EffectPlan
	 */
	public EffectPlan $plan;
	/**
	 * Opaque approval ID.
	 *
	 * @var string
	 */
	public string $approval_id;
	/**
	 * Exact approved review hash.
	 *
	 * @var string
	 */
	public string $review_hash;

	/**
	 * Bind one reserved approval to its reconstructed exact plan.
	 *
	 * @param EffectPlan $plan Exact reconstructed approved plan.
	 * @param string     $approval_id Opaque approval ID.
	 * @param string     $review_hash Exact approved review hash.
	 */
	public function __construct( EffectPlan $plan, string $approval_id, string $review_hash ) {
		$this->plan        = $plan;
		$this->approval_id = $approval_id;
		$this->review_hash = $review_hash;
	}
}
