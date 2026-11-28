<?php
/**
 * Frozen recipe compiler factory for trusted native adapter readers.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Storage\Database;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact trusted factory.

/** Adapters provide current catalog, design and native facts for each task. */
final class BuilderRecipeCompilerFactory {
	private Database $db;
	private BuilderRecipeRegistry $recipes;
	private GraphSnapshotStore $graphs;
	private BuilderPlanningPolicyRegistry $policies;

	public function __construct( Database $db, BuilderRecipeRegistry $recipes, GraphSnapshotStore $graphs, BuilderPlanningPolicyRegistry $policies ) {
		$this->db       = $db;
		$this->recipes  = $recipes;
		$this->graphs   = $graphs;
		$this->policies = $policies;
	}

	public function create( BuilderTaskContextCatalogProvider $catalogs, BuilderTaskContextFactsProvider $design, RelationshipFactsProvider $relationships ): BuilderRecipeCompiler {
		return new BuilderRecipeCompiler( $this->db, $this->recipes, $this->graphs, $this->policies, $catalogs, $design, $relationships );
	}
}
