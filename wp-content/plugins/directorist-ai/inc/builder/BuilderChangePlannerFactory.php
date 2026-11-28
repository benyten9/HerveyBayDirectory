<?php
/**
 * Trusted runtime factory for adapter-owned builder fact providers.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact trusted dependency factory.

use DirectoristAIPro\Storage\Database;

/** Keeps runtime authority dependencies fixed while Phase-5 adapters supply current facts. */
final class BuilderChangePlannerFactory {
	private Database $db;
	private GraphSnapshotStore $graphs;
	private RelationshipValidator $relationships;
	private BuilderPlanningPolicyRegistry $policies;

	public function __construct( Database $db, GraphSnapshotStore $graphs, RelationshipValidator $relationships, BuilderPlanningPolicyRegistry $policies ) {
		$this->db            = $db;
		$this->graphs        = $graphs;
		$this->relationships = $relationships;
		$this->policies      = $policies;
	}

	public function create( CandidateRelationshipFactsProvider $facts ): BuilderChangePlanner {
		return new BuilderChangePlanner( $this->db, $this->graphs, $this->relationships, $facts, $this->policies );
	}
}
