<?php
/**
 * Adapter-owned atomic compare-and-commit and reconciliation boundary.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Effects;

/** The implementation must reject a changed native version at commit time. */
interface NativeEffect {
	/**
	 * Apply once atomically against the target's expected version.
	 *
	 * @param EffectPlan   $plan Exact reviewed plan.
	 * @param EffectTarget $target Current target.
	 * @param int          $fence Target fencing token.
	 */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome;

	/**
	 * Inspect evidence after a lost response without repeating the effect.
	 *
	 * @param EffectPlan   $plan Exact original plan.
	 * @param EffectTarget $target Current target.
	 * @param int          $fence Target fencing token.
	 */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome;
}
