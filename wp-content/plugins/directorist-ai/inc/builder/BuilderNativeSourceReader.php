<?php
/**
 * Trusted saved-native-byte reader for private draft staging.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Narrow installed-adapter boundary.

/** Implementations must recheck document rights and the complete extracted version. */
interface BuilderNativeSourceReader extends BuilderGraphProvider {
	/** Return saved source bytes only to the private artifact capture service. */
	public function read_saved_source( BuilderGraphInspectionRequest $request, BuilderGraphResult $expected ): string;
}
