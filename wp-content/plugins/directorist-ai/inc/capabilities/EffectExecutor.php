<?php
/**
 * Required execution boundary for every non-read operation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Capabilities;

/** Implemented by the approval/ledger layer before writes can be exposed. */
interface EffectExecutor {
	/**
	 * Reserve authorization/budget, recheck versions and record actual effects.
	 *
	 * @param Operation $operation Trusted definition.
	 * @param object    $input Strict input.
	 * @param Context   $context Server authority.
	 * @return mixed Verified result or explicit uncertain-outcome failure.
	 */
	public function execute( Operation $operation, object $input, Context $context );
}
