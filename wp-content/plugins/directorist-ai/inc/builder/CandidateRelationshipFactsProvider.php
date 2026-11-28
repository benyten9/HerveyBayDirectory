<?php
/**
 * Trusted adapter resolver for a fully materialized candidate graph.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Compact adapter contract.

use DirectoristAIPro\Storage\Owner;

/** Structural plans needing new contexts/dependencies must be resolved by native adapter facts. */
interface CandidateRelationshipFactsProvider extends RelationshipFactsProvider {
	/** Resolve current native facts for the complete materialized candidate. */
	public function read_candidate( Owner $owner, string $document_key, GraphVersion $version, object $candidate ): RelationshipFacts;
}
