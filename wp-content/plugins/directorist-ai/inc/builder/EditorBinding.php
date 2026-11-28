<?php
/**
 * Server-verified live editor evidence for catalog eligibility.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Interface contract is described at each method boundary.

use DirectoristAIPro\Capabilities\Context;

/** Phase-5 adapters implement epoch/current-user verification behind this boundary. */
interface EditorBinding {
	/** Reject stale, forged or cross-actor editor state before source discovery. */
	public function assert_current( Context $context, string $builder ): void;

	/** Return a stable fingerprint of the verified editor epoch and revision. */
	public function fingerprint(): string;
}
