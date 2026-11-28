<?php
/**
 * Trusted runtime factory for bounded builder task context.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact trusted dependency factory.

use DirectoristAIPro\Storage\Database;

/** Holds only fixed server dependencies; adapters provide fresh read-only sources. */
final class BuilderTaskContextFactory {
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

	public function create(): BuilderTaskContextAssembler {
		return new BuilderTaskContextAssembler( $this->db, $this->graphs, $this->relationships, $this->policies );
	}
}
