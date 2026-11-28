<?php
/**
 * Trusted adapter completion of non-containment candidate relationships.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Storage\Owner;

/** A native adapter may derive context and dependency links absent from a model plan. */
interface CandidateGraphRelationshipAugmentor {
	/**
	 * Append only native-derived context/dependency relations for inserted nodes.
	 *
	 * @param Owner        $owner Authorized native owner.
	 * @param string       $document_key Exact current document key.
	 * @param GraphVersion $version Exact saved graph version.
	 * @param object       $candidate Planned complete graph before native completion.
	 * @param array        $inserted_node_ids Trusted normalized IDs of inserted nodes.
	 * @return object Completed graph with original fields, nodes and edges unchanged.
	 */
	public function complete_candidate( Owner $owner, string $document_key, GraphVersion $version, object $candidate, array $inserted_node_ids ): object;
}
