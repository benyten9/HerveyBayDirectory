<?php
/**
 * Read-only layout capability discovery before model admission.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\UI;

use DirectoristAIPro\Builder;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Bounded read-only native contract projection.

/** Summarizes installed native planning contracts; never grants write approval. */
final class BuilderPreflight {
	public function inspect( object $document, object $scope, ?object $selection, string $intent = 'refine-layout' ): array {
		if ( ! property_exists( $scope, 'directory_id' ) ) {
			throw new ContractException( 'invalid_input', 'A complete native layout scope is required.' );
		}
		$context      = Context::current( 'builder', 'ui' );
		$integrations = new Builder\IntegrationRegistry();
		do_action( 'directorist_ai_pro_register_builder_providers', $integrations );
		$integrations->freeze();
		$environment = new Builder\CatalogEnvironmentReader( $integrations );
		$readers     = new Builder\BuilderGraphRegistry();
		do_action( 'directorist_ai_pro_register_builder_graph_providers', $readers );
		$readers->freeze();
		$reader   = $readers->provider( $document->builder );
		$adapters = new Builder\BuilderDraftAdapterRegistry();
		do_action( 'directorist_ai_pro_register_builder_draft_adapters', $adapters );
		$adapters->freeze();
		$adapter = $adapters->adapter( $document->builder );
		if ( null === $reader || null === $adapter || ! is_callable( array( $adapter->compiler, 'authoring_contract' ) ) ) {
			throw new ContractException( 'unsupported_dependency', 'This editor has no installed native draft adapter. Open a supported editable document.' );
		}
		$directories  = new Builder\DirectoristDirectoryFactsReader( new \DirectoristAIPro\Domain\DirectoristInspector() );
		$directory_id = null === $scope->directory_id ? null : (int) $scope->directory_id;
		$facts        = null === $directory_id ? null : $directories->read( $context, $directory_id );
		$request      = new Builder\BuilderGraphInspectionRequest( $context, $document, $scope, array(), $facts, null, $environment );
		$result       = $reader->inspect( $request );
		$graph        = $result->graph();
		if ( null === $selection ) {
			$request->assert_result( $result );
		}
		$selected = null;
		if ( null !== $selection ) {
			Builder\BuilderEditorSelection::assert_source( $selection, $document );
			if ( 'gutenberg' === $document->builder ) {
				$selection = Builder\BuilderEditorSelection::saved_gutenberg_selection( $selection, $document );
			}
			$selected     = Builder\BuilderEditorSelection::resolve_node( $selection, $graph );
			$scope        = $selected->scope;
			$directory_id = null === $scope->directory_id ? null : (int) $scope->directory_id;
		}
		$selection_reason = self::selected_readiness_reason( $graph, $selected );
		if ( null !== $selection_reason ) {
			// This authenticated native read already fenced the saved source.
			// Rejecting an invalid placement grants no capability and does not
			// require materializing unrelated catalog and control schemas.
			$request->assert_environment_current();
			return array(
				'ready'                    => false,
				'document'                 => $document,
				'scope'                    => $scope,
				'source_version'           => $result->version()->hash(),
				'purposes'                 => array(),
				'components'               => array(),
				'capabilities'             => array( 'edit_controls' => false, 'insert_content' => false, 'insert_listing_layout' => false, 'insert_search_layout' => false ),
				'selected_area'            => true,
				'readiness_reason'         => $selection_reason,
				'requires_full_validation' => true,
			);
		}
		$rules = new Builder\SemanticRuleRegistry();
		do_action( 'directorist_ai_pro_register_builder_semantic_rules', $rules );
		$rules->freeze();
		$relationships   = new Builder\RelationshipValidator( $rules );
		$semantic        = $relationships->validate( $result->document_key(), $result->version(), $graph, $result->facts() );
		$copy            = Builder\BuilderPrivateCopyCoverage::for_saved_template( $request, $result );
		$copy_report     = null === $copy ? null : $relationships->validate( $result->document_key(), $result->version(), $graph, $result->facts(), $copy );
		$coverage_ready  = ( 'complete' === $graph->consumer_coverage && $semantic->accepted ) || ( null !== $copy_report && $copy_report->accepted );
		$selection_ready = null === $selected || null === $selected->opaque_ref;
		$policies        = new Builder\BuilderPlanningPolicyRegistry();
		do_action( 'directorist_ai_pro_register_builder_planning_policies', $policies );
		$policies->freeze();
		// Share the installed, owner-scoped catalog cache and native provider
		// schema isolation instead of compiling another full catalog here.
		$catalog  = \DirectoristAIPro\Core\Runtime::get_instance()->gateway()->execute(
			'builder.describe',
			wp_json_encode( array( 'builder' => $document->builder, 'document_kind' => $document->native_kind, 'directory_id' => $directory_id ) ),
			$context
		);
		$contract = null === $selected && is_callable( array( $adapter->compiler, 'authoring_contract_for_graph' ) ) ? $adapter->compiler->authoring_contract_for_graph( $graph ) : $adapter->compiler->authoring_contract();
		// A Gutenberg page can expose a compact initial insertion recipe even
		// when it already contains editable blocks in the requested view.
		$control_contract = 'gutenberg' === $document->builder && null === $selected && 'create-layout' !== $intent
			? $adapter->compiler->authoring_contract()
			: $contract;
		if ( 'create-layout' === $intent && null === $selected && ( $contract->first_loop->scope ?? null ) instanceof \stdClass ) {
			$placement = $contract->first_loop->scope;
			if ( ( $placement->directory_id ?? null ) !== $scope->directory_id ) {
				throw new ContractException( 'scope_conflict', 'The native layout placement belongs to another directory.' );
			}
			// The new loop's Grid/List view does not rebind its document parent.
			$scope   = clone $placement;
			$request = new Builder\BuilderGraphInspectionRequest( $context, $document, $scope, array(), $facts, null, $environment );
			$request->assert_result( $result );
		}
		$components = array();
		$editable   = 0;
		$insertable = array();
		$candidates = array();

		$insert_identities = Builder\BuilderAuthoringContract::insertion_identities_for_document( $adapter->compiler, $document );

		$can_insert = in_array( 'insert', $contract->operations ?? array(), true ) && null === $selected;
		if ( null !== $selected && in_array( 'insert', $contract->operations ?? array(), true ) ) {
			$can_insert = in_array( $selected->native_id, $contract->selected_insert_parents ?? array(), true );
			foreach ( $contract->insert_parents ?? array() as $parent ) {
				if ( $parent->provider_id === $selected->provider_id && $parent->native_id === $selected->native_id ) {
					$can_insert = true; }
			}
		}
		foreach ( $catalog->components as $component ) {
			if ( ! in_array( $component->availability, array( 'available', 'read_only' ), true ) ) {
				continue;
			}
			$source_nodes = array_filter( $graph->nodes, static fn( object $node ): bool => null === $node->opaque_ref && $node->provider_id === $component->source_provider_id && $node->native_id === $component->native_id );
			if ( null !== $selected ) {
				$path         = $selected->locator->slot_path;
				$source_nodes = array_filter( $source_nodes, static fn( object $node ): bool => array_slice( $node->locator->slot_path, 0, count( $path ) ) === $path );
			} elseif ( 'gutenberg' === $document->builder && 'refine-layout' === $intent && null !== $scope->view ) {
				$source_nodes = array_filter( $source_nodes, static fn( object $node ): bool => $node->scope->directory_id === $scope->directory_id && $node->scope->view === $scope->view && $node->scope->breakpoint_id === $scope->breakpoint_id && $node->scope->interaction === $scope->interaction );
			}
			$insert_candidate = $can_insert && ( null === $insert_identities || isset( $insert_identities[ $component->source_provider_id . '|' . $component->native_id ] ) );
			if ( ! $source_nodes && ! $insert_candidate ) {
				continue; }
			$policy = $policies->component( $component->source_provider_id, $component->native_id );
			if ( null === $policy ) {
				continue; }
			$controls = $source_nodes ? array_values( array_map( static fn( object $control ): string => $control->path, array_filter( $policy->controls, static fn( object $control ): bool => ! $control->private ) ) ) : array();
			if ( is_callable( array( $adapter->compiler, 'public_control_paths_for_node' ) ) ) {
				$native_paths = array();
				foreach ( $source_nodes as $source_node ) {
					$paths = $adapter->compiler->public_control_paths_for_node( $source_node );
					if ( is_array( $paths ) ) {
						$native_paths = array_merge( $native_paths, $paths ); }
				}
				$controls = array_values( array_intersect( $controls, $native_paths ) );
			} else {
				if ( isset( $control_contract->selected_control_targets ) && ! in_array( $component->native_id, $control_contract->selected_control_targets, true ) ) {
					$controls = array(); }
				if ( isset( $control_contract->control_edits ) ) {
					$controls = array_values( array_intersect( $controls, $control_contract->control_edits->{$component->native_id} ?? array() ) ); }
			}
			if ( $controls && $source_nodes && in_array( 'set_control', $control_contract->operations ?? array(), true ) ) {
				$editable += count( $controls );
			}
			// A direct catalog read_only entry can still have exact isolated draft
			// support. The trusted policy, compiler and containment proof below own
			// this separate capability; direct mutation permissions stay unchanged.
			if ( $insert_candidate && ! array_filter( $policy->controls, static fn( object $control ): bool => $control->private ) ) {
				$candidates[ $component->source_provider_id . '|' . $component->native_id ] = $component;
			}
			// Expose control names only, never native values or private policy schemas.
			if ( $source_nodes ) {
				$components[] = array( 'native_id' => $component->native_id, 'controls' => $controls );
			}
		}
		$insertion_contract = $contract;
		if ( 'divi' === $document->builder && $adapter->compiler instanceof Builder\BuilderDraftAuthoringExpander ) {
			// Compact model guidance omits native trees. Capability checks still
			// verify the installed full recipes against this exact fresh placement.
			$insertion_contract                      = clone $contract;
			$insertion_contract->native_loop_recipes = $adapter->compiler->authoring_contract()->native_loop_recipes ?? new \stdClass();
		}
		$insertion  = $this->insertion_capabilities( $request, $result, $scope, $selected, $insertion_contract, $candidates, $relationships );
		$native_loop = $this->template_loop_capabilities( $request, $result, $scope, $selected, $contract, $catalog, $policies, $relationships, $adapter->compiler, $reader );
		if ( null !== $native_loop ) {
			$insertion = $native_loop;
		}
		$insertable = $insertion['roots'];
		$kind_ready = in_array( $document->native_kind, $contract->document_kinds ?? array(), true );
		$purposes   = array();
		if ( $kind_ready && ( $editable > 0 || $insertable ) ) {
			$purposes[] = 'refine';
		}
		if ( $kind_ready && null === $selected && $insertable ) {
			if ( in_array( $document->native_kind, array( 'page', 'template_part' ), true ) && $insertion['content'] ) {
				$purposes[] = 'general';
			}
			if ( 'template:single_listing' === $document->native_kind && null !== $directory_id ) {
				$purposes[] = 'single-listing';
			}
			if ( null !== $directory_id && $insertion['listing_layout'] ) {
				$views = $contract->listing_layout_views ?? array( 'grid', 'list' );
				if ( in_array( 'grid', $views, true ) ) {
					$purposes[] = 'listing-grid';
				}
				if ( in_array( 'list', $views, true ) ) {
					$purposes[] = 'listing-list';
				}
				if ( $insertion['search_layout'] && false !== ( $contract->listing_search_ready ?? null ) ) {
					$purposes[] = 'search';
				}
			}
		}
		$request->assert_environment_current();
		$fresh = $reader->inspect( $request );
		if ( null === $selection ) {
			$request->assert_result( $fresh );
		}
		if ( ! hash_equals( $result->version()->hash(), $fresh->version()->hash() ) || ! hash_equals( $result->graph_hash(), $fresh->graph_hash() ) || ! hash_equals( $result->facts()->revision(), $fresh->facts()->revision() ) || ! hash_equals( $result->opaque_hash(), $fresh->opaque_hash() ) ) {
			throw new ContractException( 'evidence_stale', 'The saved layout changed. Refresh the document context before preparing a draft.' );
		}
		if ( null !== $selection ) {
			Builder\BuilderEditorSelection::assert_source( $selection, $document );
		}
		$ready = $kind_ready && $coverage_ready && $selection_ready && (bool) $purposes && 'complete' === $graph->completeness;
		return array(
			'ready'                    => $ready,
			'document'                 => $document,
			'scope'                    => $scope,
			'catalog_revision'         => $catalog->revision,
			'catalog_providers'        => array_map( static fn( object $provider ): array => array( 'reader_id' => $provider->reader_id, 'status' => $provider->status ), $catalog->providers ),
			'catalog_diagnostics'      => array_values( array_unique( array_map( static fn( object $diagnostic ): string => $diagnostic->code, $catalog->diagnostics ) ) ),
			'source_version'           => $result->version()->hash(),
			'compiler_revision'        => $contract->revision ?? null,
			'purposes'                 => $purposes,
			'components'               => array_slice( $components, 0, 100 ),
			'capabilities'             => array( 'edit_controls' => $editable > 0, 'insert_content' => $insertion['content'], 'insert_listing_layout' => $insertion['listing_layout'], 'insert_search_layout' => $insertion['search_layout'] ),
			'scope_requirements'       => array(
				'initial_listing_view' => null === $selected && 'elementor' === $document->builder && isset( $contract->first_loop ) && 'template:single_listing' !== $document->native_kind && ! array_filter( $graph->nodes, static fn( object $node ): bool => 'directorist-elementor' === $node->provider_id && in_array( $node->native_id, array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) && ( $node->scope->directory_id ?? null ) === ( $scope->directory_id ?? null ) ),
				'listing_views'        => array_values( array_intersect( $contract->listing_layout_views ?? array( 'grid', 'list' ), array( 'grid', 'list' ) ) ),
			),
			'destination'              => 'separate_private_draft',
			'selected_area'            => null !== $selected,
			'readiness_reason'         => self::selected_readiness_reason( $graph, $selected ) ?? ( $ready ? null : self::catalog_readiness_reason( $catalog ) ),
			'requires_full_validation' => true,
			'validation_note'          => 'Planning capability only. Exact proposed changes, relationships and native draft output require server validation and human review.',
		);
	}

	/** Explain a blocked integration without exposing native error text or values. */
	private static function catalog_readiness_reason( object $catalog ): ?string {
		$conflict = false;
		foreach ( $catalog->providers ?? array() as $provider ) {
			if ( 'available' === ( $provider->status ?? null ) ) {
				return null;
			}
			$conflict = $conflict || 'conflict' === ( $provider->status ?? null );
		}
		if ( $conflict ) {
			foreach ( $catalog->diagnostics ?? array() as $diagnostic ) {
				if ( 'version_evidence_conflict' === ( $diagnostic->code ?? null ) ) {
					return 'integration_version_conflict';
				}
			}
		}
		return null;
	}

	/** Return only an allowlisted reason belonging to the exact selected node. */
	private static function selected_readiness_reason( object $graph, ?object $selected ): ?string {
		if ( null === $selected || ! is_string( $selected->id ?? null ) || ! is_array( $graph->omissions ?? null ) ) {
			return null;
		}
		foreach ( $graph->omissions as $omission ) {
			if ( $omission instanceof \stdClass && 'listing_card_context_required' === ( $omission->code ?? null ) && in_array( $omission->path ?? null, array( $selected->id, 'nodes.' . $selected->id ), true ) ) {
				return 'listing_card_context_required';
			}
		}
		return null;
	}

	/** Exact private-draft candidate roots and bounded native recipe availability. */
	private function insertion_capabilities( Builder\BuilderGraphInspectionRequest $request, Builder\BuilderGraphResult $result, object $scope, ?object $selected, object $contract, array $candidates, Builder\RelationshipValidator $relationships ): array {
		$none = array( 'roots' => array(), 'content' => false, 'listing_layout' => false, 'search_layout' => false );
		if ( ! $candidates || ! in_array( 'insert', $contract->operations ?? array(), true ) ) {
			return $none;
		}
		$graph     = $result->graph();
		$document  = $request->document();
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$scope_key = $canonical->encode( $scope );
		$parents   = array();
		foreach ( $graph->nodes as $node ) {
			if ( null !== $node->opaque_ref || $scope_key !== $canonical->encode( $node->scope ) ) {
				continue;
			}
			if ( null !== $selected && array_slice( $node->locator->slot_path, 0, count( $selected->locator->slot_path ) ) !== $selected->locator->slot_path ) {
				continue;
			}
			foreach ( $contract->insert_parents ?? array() as $parent ) {
				if ( $parent->provider_id === $node->provider_id && $parent->native_id === $node->native_id ) {
					$parents[] = $node;
				}
			}
			if ( in_array( $node->native_id, $contract->selected_insert_parents ?? array(), true ) ) {
				$parents[] = $node;
			}
		}
		$shapes = array();
		foreach ( $contract->insert_child_shapes ?? array() as $shape ) {
			$shapes[ $shape->native_id ] = $shape->allowed_children;
		}
		$roots = array();
		foreach ( $parents as $parent ) {
			$required = $contract->selected_insert_roots->{$parent->native_id} ?? null;
			foreach ( $candidates as $key => $candidate ) {
				if ( ( null !== $required && $required !== $candidate->native_id ) || ! $relationships->supports_relationship( 'contains', $parent->provider_id, $parent->native_id, $candidate->source_provider_id, $candidate->native_id ) ) {
					continue;
				}
				$root = $contract->required_insert_root ?? null;
				if ( null !== $root && ( $root->provider_id !== $candidate->source_provider_id || $root->native_id !== $candidate->native_id ) ) {
					continue;
				}
				$roots[ $key ] = true;
			}
		}
		// A first Elementor loop introduces a view below an unchanged saved root.
		// Use its existing typed proof rather than accepting arbitrary view changes.
		$first_loop = null;
		if ( null === $selected && 'elementor' === $document->builder && null !== $scope->directory_id && in_array( $scope->view, array( 'grid', 'list' ), true ) ) {
			$probe_scope = clone $scope;
			$probe       = new Builder\BuilderGraphInspectionRequest( $request->context(), $document, $probe_scope, array(), $request->directory_facts(), null, $this->environment_for() );
			$proof       = Builder\BuilderElementorLoopCreationProof::for_saved_page( $probe, $result );
			if ( null !== $proof ) {
				$hint = $proof->authoring_hint();
				$key  = $hint->provider_id . '|' . $hint->native_id;
				if ( isset( $candidates[ $key ] ) ) {
					$first_loop    = $key;
					$roots[ $key ] = true;
				}
			}
		}
		$reachable = $roots;
		for ( $depth = 0; $depth < min( 12, $contract->max_insert_depth ?? 12 ); ++$depth ) {
			$before = count( $reachable );
			foreach ( array_keys( $reachable ) as $source_key ) {
				$source = $candidates[ $source_key ];
				foreach ( $candidates as $key => $candidate ) {
					if ( isset( $shapes[ $source->native_id ] ) && ! in_array( $candidate->native_id, $shapes[ $source->native_id ], true ) ) {
						continue;
					}
					if ( $relationships->supports_relationship( 'contains', $source->source_provider_id, $source->native_id, $candidate->source_provider_id, $candidate->native_id ) ) {
						$reachable[ $key ] = true;
					}
				}
			}
			if ( count( $reachable ) === $before ) {
				break;
			}
		}
		$content = (bool) array_filter( array_keys( $roots ), static fn( string $key ): bool => str_starts_with( $key, 'wordpress/core|' ) || str_starts_with( $key, 'elementor/core|' ) || str_starts_with( $key, 'divi/core|' ) );
		$listing = false;
		$search  = false;
		if ( null === $selected && ! in_array( $document->native_kind, array( 'template_part', 'template:single_listing' ), true ) ) {
			if ( 'elementor' === $document->builder ) {
				// Native first-loop creation supplies configured cards/pagination.
				// Standalone new search forms are not a supported compiler operation.
				$listing = null !== $first_loop;
			} elseif ( 'gutenberg' === $document->builder ) {
				$needed  = array( 'directorist-gb-integration/listings-loop', 'directorist-gb-integration/listing-card-template', 'directorist-gb-integration/listing-card-title', 'directorist-gb-integration/listings-pagination' );
				$listing = ! array_filter( $needed, static fn( string $native ): bool => ! isset( $reachable[ 'directorist-gb-integration|' . $native ] ) );
				$search  = $listing && isset( $reachable['directorist-gb-integration|directorist-gb-integration/listings-search'] );
			} elseif ( 'divi' === $document->builder ) {
				foreach ( get_object_vars( $contract->native_loop_recipes ?? new \stdClass() ) as $recipe ) {
					if ( ! in_array( $document->native_kind, $recipe->document_kinds, true ) || ! $recipe->tree ) {
						continue;
					}
					$valid = true;
					foreach ( $recipe->tree as $row ) {
						$provider = str_starts_with( $row[0], 'divi/' ) ? 'divi/core' : 'directorist-divi-integration';
						$valid    = $valid && isset( $reachable[ $provider . '|' . $row[0] ] );
						if ( null !== $row[1] ) {
							$parent_native   = $recipe->tree[ $row[1] ][0];
							$parent_provider = str_starts_with( $parent_native, 'divi/' ) ? 'divi/core' : 'directorist-divi-integration';
							$valid           = $valid && $relationships->supports_relationship( 'contains', $parent_provider, $parent_native, $provider, $row[0] );
						}
					}
					$listing = $listing || $valid;
					$search  = $search || $valid;
				}
			}
		}
		return array( 'roots' => array_keys( $roots ), 'content' => $content, 'listing_layout' => $listing, 'search_layout' => $search );
	}

	private function environment_for(): Builder\CatalogEnvironmentReader {
		$integrations = new Builder\IntegrationRegistry();
		do_action( 'directorist_ai_pro_register_builder_providers', $integrations );
		$integrations->freeze();
		return new Builder\CatalogEnvironmentReader( $integrations );
	}

	/** Validate the exact installed template factory in memory before advertising it. */
	private function template_loop_capabilities( Builder\BuilderGraphInspectionRequest $request, Builder\BuilderGraphResult $result, object $scope, ?object $selected, object $contract, object $catalog, Builder\BuilderPlanningPolicyRegistry $policies, Builder\RelationshipValidator $relationships, object $compiler, object $reader ): ?array {
		$document = $request->document();
		if ( null !== $selected || ! in_array( $document->builder, array( 'gutenberg', 'divi' ), true ) || ! in_array( $document->native_kind, array( 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) || ! $compiler instanceof Builder\BuilderDraftAuthoringExpander || ! $reader instanceof Builder\BuilderNativeSourceReader || ! is_callable( array( $compiler, 'template_layout_authoring_components' ) ) ) {
			return null;
		}
		$none = array( 'roots' => array(), 'content' => false, 'listing_layout' => false, 'search_layout' => false );
		try {
			Builder\BuilderAuthoringContract::assert_metadata( $contract );
			$graph = $result->graph(); $recipe = $contract->first_loop ?? null; $canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
			$identities = $compiler->template_layout_authoring_components( $document, $scope );
			if ( 'complete' !== $graph->completeness || 'complete' !== $graph->consumer_coverage || array() !== $graph->omissions || null !== $graph->version->editor_epoch || ! in_array( $document->native_kind, $contract->document_kinds ?? array(), true ) || ! in_array( 'insert', $contract->operations ?? array(), true ) || 1 !== count( $identities ) || ! $recipe instanceof \stdClass || 1 !== ( $recipe->max_changes ?? null ) || 1 !== ( $recipe->max_insert_nodes ?? null ) || ! is_int( $recipe->position ?? null ) || $recipe->position < 0 || ! ( $recipe->parent ?? null ) instanceof \stdClass || ! ( $recipe->carrier_attributes_schema ?? null ) instanceof \stdClass || $canonical->encode( $recipe->scope ?? null ) !== $canonical->encode( $scope ) ) { return $none; }
			$parents = array_values( array_filter( $graph->nodes, static fn( object $node ): bool => null === $node->opaque_ref && $canonical->encode( $node->locator ) === $canonical->encode( $recipe->parent ) && $canonical->encode( $node->scope ) === $canonical->encode( $scope ) ) );
			$tree = $recipe->subtree ?? null; $carrier = $tree->nodes[0] ?? null;
			if ( 1 !== count( $parents ) || ! $tree instanceof \stdClass || ! is_array( $tree->nodes ?? null ) || 1 !== count( $tree->nodes ) || array() !== ( $tree->edges ?? null ) || ! $carrier instanceof \stdClass || $tree->root_client_id !== $carrier->client_id || $carrier->provider_id !== $identities[0]->provider_id || $carrier->native_id !== $identities[0]->native_id || ! $carrier->attributes instanceof \stdClass || array( 'native_loop_recipe' ) !== array_keys( get_object_vars( $carrier->attributes ) ) ) { return $none; }
			$policies->validate_insert( (object) array( 'insert_attributes_schema' => $recipe->carrier_attributes_schema ), $carrier->attributes );
			$change = (object) array( 'op' => 'insert', 'parent' => $recipe->parent, 'position' => $recipe->position, 'scope' => $scope, 'subtree' => $tree );
			$expanded = $compiler->expand_authoring_changes( $graph, array( $change ) );
			( new \DirectoristAIPro\Validation\JsonDecoder( 2097152, 32 ) )->check( $expanded );
			if ( 1 !== count( $expanded ) || 'insert' !== ( $expanded[0]->op ?? null ) || $expanded[0]->position !== $recipe->position || $canonical->encode( $expanded[0]->parent ) !== $canonical->encode( $recipe->parent ) || $canonical->encode( $expanded[0]->scope ) !== $canonical->encode( $scope ) ) { return $none; }
			$tree = $expanded[0]->subtree; $nodes = array(); $remap = array(); $normalized_nodes = array(); $normalized_edges = array(); $children = array(); $owners = array(); $available = array();
			foreach ( $catalog->components as $component ) { if ( in_array( $component->availability, array( 'available', 'read_only' ), true ) ) { $available[ $component->source_provider_id . '|' . $component->native_id ] = true; } }
			$insertions = Builder\BuilderAuthoringContract::insertion_identities_for_document( $compiler, $document );
			if ( ! is_array( $tree->nodes ?? null ) || ! $tree->nodes || count( $tree->nodes ) > 100 || ! is_array( $tree->edges ?? null ) || count( $tree->edges ) !== count( $tree->nodes ) - 1 ) { return $none; }
			foreach ( $tree->nodes as $node ) {
				foreach ( array( 'client_id', 'provider_id', 'native_id' ) as $field ) { Builder\GraphVersion::identifier( $node->{$field} ); }
				$key = $node->provider_id . '|' . $node->native_id; $policy = $policies->component( $node->provider_id, $node->native_id );
				if ( isset( $nodes[ $node->client_id ] ) || ! isset( $available[ $key ] ) || ( null !== $insertions && ! isset( $insertions[ $key ] ) ) || null === $policy || ! $node->attributes instanceof \stdClass || ! $relationships->supports_component( $node->provider_id, $node->native_id ) ) { return $none; }
				$policies->validate_insert( $policy, $node->attributes ); $nodes[ $node->client_id ] = $node; $remap[ $node->client_id ] = 'new-' . substr( hash( 'sha256', 'native-preflight|' . $node->client_id ), 0, 24 );
				foreach ( $graph->nodes as $saved ) { if ( $saved->id === $remap[ $node->client_id ] ) { return $none; } }
				$normalized_nodes[] = (object) array( 'node_id' => $remap[ $node->client_id ], 'provider_id' => $node->provider_id, 'native_id' => $node->native_id, 'attributes' => $node->attributes );
			}
			$root = $nodes[ $tree->root_client_id ] ?? null; $parent = $parents[0];
			if ( null === $root || ! $relationships->supports_relationship( 'contains', $parent->provider_id, $parent->native_id, $root->provider_id, $root->native_id ) ) { return $none; }
			foreach ( $tree->edges as $edge ) {
				$from = $nodes[ $edge->source_client_id ] ?? null; $to = $nodes[ $edge->target_client_id ] ?? null;
				if ( 'contains' !== $edge->kind || null === $from || null === $to || $from === $to || ! is_int( $edge->order ) || $edge->order < 0 || isset( $owners[ $to->client_id ] ) || isset( $children[ $from->client_id ][ $edge->order ] ) || $to->client_id === $tree->root_client_id || ! $relationships->supports_relationship( 'contains', $from->provider_id, $from->native_id, $to->provider_id, $to->native_id ) ) { return $none; }
				$owners[ $to->client_id ] = $from->client_id; $children[ $from->client_id ][ $edge->order ] = $to->client_id;
				$normalized_edges[] = (object) array( 'kind' => 'contains', 'source_id' => $remap[ $from->client_id ], 'target_id' => $remap[ $to->client_id ], 'order' => $edge->order );
			}
			$seen = array(); $queue = array( $tree->root_client_id );
			while ( $queue ) { $id = array_pop( $queue ); if ( isset( $seen[ $id ] ) ) { return $none; } $seen[ $id ] = true; $descendants = $children[ $id ] ?? array(); ksort( $descendants ); if ( $descendants && array_keys( $descendants ) !== range( 0, count( $descendants ) - 1 ) ) { return $none; } $queue = array_merge( $queue, array_values( $descendants ) ); }
			if ( count( $seen ) !== count( $nodes ) ) { return $none; }
			$normalized = (object) array( 'sequence' => 0, 'op' => 'insert', 'planned_node_id' => $remap[ $tree->root_client_id ], 'parent_id' => $parent->id, 'position' => $recipe->position, 'scope' => $scope, 'subtree' => (object) array( 'root_node_id' => $remap[ $tree->root_client_id ], 'nodes' => $normalized_nodes, 'edges' => $normalized_edges ) );
			$source = $reader->read_saved_source( $request, $result ); $compiled = $compiler->compile( $source, $graph, array( $normalized ) );
			if ( ! is_string( $compiled->content ?? null ) || '' === $compiled->content || strlen( $compiled->content ) > 2097152 || $compiled->content === $source || ! hash_equals( hash( 'sha256', $compiled->content ), $compiled->content_hash ?? '' ) || ! is_int( $compiled->block_count ?? null ) || $compiled->block_count < 1 || ! is_string( $compiled->root_name ?? null ) ) { return $none; }
			$native = array_column( $tree->nodes, 'native_id' ); $listing = in_array( 'directorist-gb-integration/listings-loop', $native, true ) || in_array( 'directorist/listings-archive', $native, true ) || in_array( 'directorist-gb-integration/homepage-search-loop', $native, true ) || in_array( 'directorist/homepage-search-loop', $native, true );
			$search = (bool) array_intersect( $native, array( 'directorist-gb-integration/listings-search', 'directorist-gb-integration/homepage-search', 'directorist/archive-search', 'directorist/homepage-search' ) );
			return $listing ? array( 'roots' => array( $carrier->provider_id . '|' . $carrier->native_id ), 'content' => false, 'listing_layout' => true, 'search_layout' => $search ) : $none;
		} catch ( ContractException $error ) {
			return $none;
		}
	}
}
