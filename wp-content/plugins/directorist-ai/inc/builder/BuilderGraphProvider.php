<?php
/**
 * Native builder graph extraction boundary.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- The interface method is self-describing.

/** Installed integration code owns native document reads and resolver behavior. */
interface BuilderGraphProvider {
	/** Stable launch builder identity. */
	public function builder(): string;

	/** Stable provider identity used for registration diagnostics. */
	public function provider_id(): string;

	/** Extract one current, authorized saved or editor-bound document. */
	public function inspect( BuilderGraphInspectionRequest $request ): BuilderGraphResult;
}
