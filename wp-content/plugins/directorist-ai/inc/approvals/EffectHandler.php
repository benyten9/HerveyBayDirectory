<?php
/**
 * Native effect plus current environment fingerprint.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Approvals;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\NativeEffect;

/** Each registered capability owns one trusted handler. */
interface EffectHandler extends NativeEffect {
	/**
	 * Recheck native authority for every exact target in this plan.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @param Context    $context Current server-resolved context.
	 */
	public function permitted( EffectPlan $plan, Context $context ): bool;

	/**
	 * Return current dependency/schema/editor environment for this plan.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 */
	public function environment_hash( EffectPlan $plan ): string;
}
