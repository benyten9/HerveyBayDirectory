<?php
/**
 * Request-bound native relationship facts for reviewed draft effects.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Trusted installed-adapter factory.

/** A fresh execution request must never reuse a prior run's facts reader. */
interface BuilderCandidateRelationshipFactsFactory {
	public function builder(): string;

	public function create( BuilderGraphInspectionRequest $request ): CandidateRelationshipFactsProvider;
}
