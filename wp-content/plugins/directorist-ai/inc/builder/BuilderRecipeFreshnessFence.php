<?php
/**
 * Exact current recipe, catalog, design and native-fact recheck.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact immutable fence.

use DirectoristAIPro\Capabilities\Context;

/** Planner can only activate a recipe review after this trusted compiler recheck. */
final class BuilderRecipeFreshnessFence {
	private BuilderRecipeCompiler $compiler;
	private Context $context;
	private string $document_key;
	private GraphVersion $version;
	private object $scope;
	private BuilderRecipeSelection $selection;

	public function __construct( BuilderRecipeCompiler $compiler, Context $context, string $document_key, GraphVersion $version, object $scope, BuilderRecipeSelection $selection ) {
		$this->compiler     = $compiler;
		$this->context      = $context;
		$this->document_key = $document_key;
		$this->version      = $version;
		$this->scope        = $scope;
		$this->selection    = $selection;
	}

	public function assert_current(): void {
		$this->compiler->assert_selection_current( $this->context, $this->document_key, $this->version, $this->scope, $this->selection );
	}
}
