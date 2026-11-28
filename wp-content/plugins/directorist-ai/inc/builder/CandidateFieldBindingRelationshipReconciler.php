<?php
/**
 * Trusted reconciliation of existing native field selectors.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Storage\Owner;

/** Optional native adapter hook; it cannot add nodes or expand graph authority. */
interface CandidateFieldBindingRelationshipReconciler {
	/**
	 * Reconcile only field edges for server-normalized bind_field records.
	 *
	 * The adapter freshly verifies owner/version, exact selector alias, before
	 * value and native field eligibility. Targets must already be inspected.
	 * All graph members other than these field edges remain byte-exact.
	 *
	 * @param Owner        $owner Current verified owner.
	 * @param string       $document_key Exact source document key.
	 * @param GraphVersion $version Current source version.
	 * @param object       $candidate Planned graph retaining source preimages.
	 * @param array        $normalized_bindings Normalized bind_field changes only.
	 * @return object Graph with only declared native field edges reconciled.
	 */
	public function reconcile_field_bindings( Owner $owner, string $document_key, GraphVersion $version, object $candidate, array $normalized_bindings ): object;
}
