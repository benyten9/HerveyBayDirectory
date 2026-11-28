<?php
/**
 * Bounded native policies and relationships for model authoring.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Bounded contract assembly.

/** Reuses installed policies and current native catalog; this packet never authorizes a write. */
final class BuilderAuthoringContract {
	/** Full trusted native descriptors include their typed creation schemas. */
	public const MAX_METADATA_BYTES = 65536;
	public const MAX_METADATA_DEPTH = 32;

	private BuilderPlanningPolicyRegistry $policies;
	private RelationshipValidator $relationships;

	public function __construct( BuilderPlanningPolicyRegistry $policies, RelationshipValidator $relationships ) {
		$this->policies      = $policies;
		$this->relationships = $relationships;
	}

	/**
	 * Bound installed, server-produced authoring metadata without changing it.
	 *
	 * Never use this for model values, request input or native document trees;
	 * their independent decoders and exact schemas remain authoritative.
	 *
	 * @param object $contract Trusted native compiler descriptor, including schemas.
	 * @throws \DirectoristAIPro\Validation\ContractException Installed metadata is unavailable.
	 */
	public static function assert_metadata( object $contract ): void {
		try {
			( new \DirectoristAIPro\Validation\JsonDecoder( self::MAX_METADATA_BYTES, self::MAX_METADATA_DEPTH ) )->check( $contract );
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Installed native authoring metadata exceeds its supported bounds.' );
		}
	}

	/**
	 * Return document-scoped native insertion identities without changing global policies.
	 *
	 * @throws \DirectoristAIPro\Validation\ContractException When installed identities are malformed.
	 */
	public static function insertion_identities_for_document( object $compiler, ?object $document = null ): ?array {
		$scoped = null !== $document && is_callable( array( $compiler, 'insertion_component_identities_for_document' ) );
		if ( ! $scoped && ! is_callable( array( $compiler, 'insertion_component_identities' ) ) ) {
			return null;
		}
		$declared = $scoped ? $compiler->insertion_component_identities_for_document( $document ) : $compiler->insertion_component_identities();
		( new \DirectoristAIPro\Validation\JsonDecoder( 32768, 8 ) )->check( $declared );
		if ( ! is_array( $declared ) || ! array_is_list( $declared ) || count( $declared ) > 200 ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Native insertion discovery is unavailable.' );
		}
		$identities = array();
		foreach ( $declared as $identity ) {
			if ( ! $identity instanceof \stdClass || ! is_string( $identity->provider_id ?? null ) || ! is_string( $identity->native_id ?? null ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Native insertion identity is invalid.' );
			}
			GraphVersion::identifier( $identity->provider_id );
			GraphVersion::identifier( $identity->native_id );
			$identities[ $identity->provider_id . '|' . $identity->native_id ] = true;
		}
		return $identities;
	}

	/** Remove unavailable insertion schemas while retaining existing saved control contracts. */
	public static function narrow_document_insertions( object $packet, object $compiler, object $document ): void {
		$identities = self::insertion_identities_for_document( $compiler, $document );
		if ( null === $identities ) {
			return;
		}
		foreach ( $packet->components ?? array() as $component ) {
			if ( ! isset( $identities[ $component->provider_id . '|' . $component->native_id ] ) ) {
				$component->insert_attributes_schema = null;
			}
		}
		if ( isset( $packet->suggested_authoring_components ) ) {
			$packet->suggested_authoring_components = array_values( array_filter( $packet->suggested_authoring_components, static fn( object $component ): bool => isset( $identities[ $component->provider_id . '|' . $component->native_id ] ) ) );
		}
	}

	/** A complete packet must carry a usable schema or an exact native insertion recipe. */
	public static function has_actionable_contract( object $packet ): bool {
		foreach ( $packet->components ?? array() as $component ) {
			if ( ( $component->insert_attributes_schema ?? null ) instanceof \stdClass ) {
				return true;
			}
			// A schema without a saved instance is discovery, not an edit target.
			if ( false === ( $component->saved_instance_available ?? null ) ) {
				continue;
			}
			foreach ( $component->controls ?? array() as $control ) {
				if ( false === ( $control->private ?? null ) && ( $control->value_schema ?? null ) instanceof \stdClass ) {
					return true;
				}
			}
		}
		$recipe = $packet->draft->first_loop ?? null;
		if ( ! $recipe instanceof \stdClass || ! ( $recipe->parent ?? null ) instanceof \stdClass || ! ( $recipe->scope ?? null ) instanceof \stdClass || ! is_int( $recipe->position ?? null ) || $recipe->position < 0 || ! ( $recipe->subtree ?? null ) instanceof \stdClass ) {
			return false;
		}
		$tree = $recipe->subtree;
		if ( ! is_string( $tree->root_client_id ?? null ) || ! is_array( $tree->nodes ?? null ) || ! $tree->nodes || ! is_array( $tree->edges ?? null ) ) {
			return false;
		}
		$root = false;
		foreach ( $tree->nodes as $node ) {
			if ( ! $node instanceof \stdClass || ! is_string( $node->client_id ?? null ) || ! is_string( $node->provider_id ?? null ) || ! is_string( $node->native_id ?? null ) || ! ( $node->attributes ?? null ) instanceof \stdClass ) {
				return false;
			}
			$root = $root || $tree->root_client_id === $node->client_id;
		}
		return $root;
	}

	/** Narrow public planning hints to paths supported by this saved native node. */
	public static function narrow_selected_controls( object $packet, ?object $node, object $compiler ): void {
		if ( null === $node || ! is_callable( array( $compiler, 'public_control_paths_for_node' ) ) ) {
			return;
		}
		$paths = $compiler->public_control_paths_for_node( $node );
		foreach ( $packet->control_index ?? array() as $index ) {
			if ( $index->provider_id === $node->provider_id && $index->native_id === $node->native_id ) {
				$index->paths = array_values( array_filter( $index->paths, static fn( string $path ): bool => is_array( $paths ) && in_array( $path, $paths, true ) ) );
				if ( isset( $index->hints ) ) {
					$index->hints = (object) array_intersect_key( get_object_vars( $index->hints ), array_fill_keys( $index->paths, true ) ); }
			}
		}
		foreach ( $packet->components as $component ) {
			if ( $component->provider_id === $node->provider_id && $component->native_id === $node->native_id ) {
				$component->controls = array_values( array_filter( $component->controls, static fn( object $control ): bool => is_array( $paths ) && in_array( $control->path, $paths, true ) ) );
			}
		}
		self::check_requested_control_coverage( $packet );
	}

	/** Keep adapter hint tables bounded to the exact returned public control contracts. */
	public static function narrow_draft_controls( object $contract, object $packet, ?object $node = null, ?object $compiler = null ): object {
		if ( ! ( $contract->control_edits ?? null ) instanceof \stdClass ) {
			return $contract; }
		$copy                = clone $contract;
		$copy->control_edits = new \stdClass();
		$index               = array();
		foreach ( $packet->components ?? array() as $component ) {
			$index[ $component->native_id ] = array_column( $component->controls ?? array(), 'path' );
		}
		foreach ( $packet->control_index ?? array() as $component ) {
			$index[ $component->native_id ] = $index[ $component->native_id ] ?? $component->paths;
		}
		foreach ( get_object_vars( $contract->control_edits ) as $native => $paths ) {
			if ( ! isset( $index[ $native ] ) || ! is_array( $paths ) ) {
				continue; }
			$copy->control_edits->{$native} = array_values( array_intersect( $paths, $index[ $native ] ) );
		}
		if ( null !== $node && null !== $compiler && is_callable( array( $compiler, 'public_control_paths_for_node' ) ) && in_array( 'set_control', $contract->operations ?? array(), true ) ) {
			$paths = $compiler->public_control_paths_for_node( $node );
			foreach ( $packet->components ?? array() as $component ) {
				if ( $component->provider_id !== $node->provider_id || $component->native_id !== $node->native_id ) {
					continue; }
				$copy->control_edits->{$node->native_id} = is_array( $paths ) ? array_values( array_intersect( array_column( $component->controls ?? array(), 'path' ), $paths ) ) : array();
			}
		}
		return $copy;
	}

	/** Read only public scalar absence from the same private native source receipt. */
	public static function describe_unset_controls( object $packet, ?object $node, object $compiler, BuilderGraphResult $source ): array {
		if ( null === $node || null !== ( $node->opaque_ref ?? null ) || 'complete_for_selection' !== ( $packet->state ?? null ) || true !== ( $packet->can_propose_private_draft ?? null ) || ! is_callable( array( $compiler, 'public_unset_control_paths_for_node' ) ) ) {
			return array(); }
		$paths = array();
		foreach ( $packet->components ?? array() as $component ) {
			if ( $component->provider_id !== $node->provider_id || $component->native_id !== $node->native_id ) {
				continue; }
			foreach ( $component->controls ?? array() as $control ) {
				if ( false === ( $control->private ?? null ) && ! ( $control->read_only ?? false ) && in_array( $control->value_schema->type ?? null, array( 'string', 'number', 'integer', 'boolean' ), true ) ) {
					$paths[] = $control->path; }
			}
		}
		if ( ! $paths || count( $paths ) > 200 ) {
			return array(); }
		$unset = $compiler->public_unset_control_paths_for_node( $node, $paths, $source->native_snapshot() );
		return is_array( $unset ) && array_is_list( $unset ) && ! array_diff( $unset, $paths ) ? $unset : array();
	}

	/** Describe inactive saved values without changing preimages or granting controls. */
	public static function describe_control_effectiveness( object $packet, ?object $node, object $compiler ): ?object {
		if ( null === $node || null !== ( $node->opaque_ref ?? null ) || 'complete_for_selection' !== ( $packet->state ?? null ) || true !== ( $packet->can_propose_private_draft ?? null ) || ! is_callable( array( $compiler, 'public_control_effectiveness_for_node' ) ) ) {
			return null;
		}
		$controls = array();
		foreach ( $packet->components ?? array() as $component ) {
			if ( $component->provider_id !== $node->provider_id || $component->native_id !== $node->native_id ) {
				continue;
			}
			foreach ( $component->controls ?? array() as $control ) {
				if ( false === ( $control->private ?? null ) ) {
					$controls[ $control->path ] = $control->value_schema ?? null;
				}
			}
		}
		if ( ! $controls ) {
			return null;
		}
		$hint = $compiler->public_control_effectiveness_for_node( $node, array_keys( $controls ) );
		if ( ! $hint instanceof \stdClass ) {
			return null;
		}
		try {
			( new \DirectoristAIPro\Validation\JsonDecoder( 2048, 12 ) )->check( $hint );
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			return null;
		}
		if ( ! self::effectiveness_keys( $hint, array( 'revision', 'groups', 'truncated', 'note' ) ) || ! is_string( $hint->revision ) || ! preg_match( '/^[a-z][a-z0-9-]{0,63}$/D', $hint->revision ) || ! is_bool( $hint->truncated ) || ! is_string( $hint->note ) || strlen( $hint->note ) > 400 || ! is_array( $hint->groups ) || ! $hint->groups || count( $hint->groups ) > 8 ) {
			return null;
		}
		foreach ( $hint->groups as $group ) {
			if ( ! $group instanceof \stdClass || ! self::effectiveness_keys( $group, array( 'control_path', 'inactive_saved_members' ) ) || ! is_string( $group->control_path ) || ! isset( $controls[ $group->control_path ] ) || ! is_array( $group->inactive_saved_members ) || ! $group->inactive_saved_members || count( $group->inactive_saved_members ) > 8 ) {
				return null;
			}
			$schema = $controls[ $group->control_path ];
			$saved  = self::effectiveness_group( $node, $group->control_path );
			foreach ( $group->inactive_saved_members as $member ) {
				if ( ! $member instanceof \stdClass || ! self::effectiveness_keys( $member, array( 'member', 'condition', 'controllers' ) ) || ! is_string( $member->member ) || ! ( $schema->properties->{$member->member} ?? null ) instanceof \stdClass || true === ( $schema->properties->{$member->member}->{'readOnly'} ?? false ) || ! $saved instanceof \stdClass || ! property_exists( $saved, $member->member ) || ! $member->condition instanceof \stdClass || ! is_array( $member->controllers ) || ! $member->controllers || count( $member->controllers ) > 3 ) {
					return null;
				}
				$condition_names  = array();
				$condition_values = array();
				foreach ( $member->controllers as $controller ) {
					if ( ! $controller instanceof \stdClass || ! self::effectiveness_keys( $controller, array( 'member', 'control_path', 'value_source', 'current_value', 'allowed_values' ) ) || ! is_string( $controller->control_path ) || ! isset( $controls[ $controller->control_path ] ) || ! is_string( $controller->member ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/D', $controller->member ) || ! in_array( $controller->value_source, array( 'saved_override', 'native_default' ), true ) || ! is_array( $controller->allowed_values ) || ! $controller->allowed_values || count( $controller->allowed_values ) > 16 ) {
						return null;
					}
					$controller_schema = $controls[ $controller->control_path ]->properties->{$controller->member} ?? null;
					$enum              = self::effectiveness_enum( $controller_schema, $controls[ $controller->control_path ] );
					if ( ! is_array( $enum ) || ( null !== $controller->current_value && ! is_scalar( $controller->current_value ) ) || ! in_array( $controller->current_value, $enum, true ) || true === ( $controller_schema->{'readOnly'} ?? false ) ) {
						return null;
					}
					foreach ( $controller->allowed_values as $value ) {
						if ( ( null !== $value && ! is_scalar( $value ) ) || ! in_array( $value, $enum, true ) ) {
							return null;
						}
					}
					$controller_saved = self::effectiveness_group( $node, $controller->control_path );
					$present          = $controller_saved instanceof \stdClass && property_exists( $controller_saved, $controller->member );
					if ( ( 'saved_override' === $controller->value_source && ( ! $present || $controller_saved->{$controller->member} !== $controller->current_value ) ) || ( 'native_default' === $controller->value_source && $present ) ) {
						return null;
					}
					$condition_names[ $controller->member ]  = $enum;
					$condition_values[ $controller->member ] = $controller->current_value;
				}
				if ( ! get_object_vars( $member->condition ) || count( get_object_vars( $member->condition ) ) > 3 ) {
					return null;
				}
				foreach ( $member->condition as $name => $value ) {
					$key = str_ends_with( $name, '!' ) ? substr( $name, 0, -1 ) : $name;
					if ( ! isset( $condition_names[ $key ] ) || ! in_array( $value, $condition_names[ $key ], true ) ) {
						return null;
					}
					$equal = $condition_values[ $key ] === $value;
					if ( str_ends_with( $name, '!' ) ? ! $equal : $equal ) {
						return null;
					}
				}
			}
		}
		return json_decode( wp_json_encode( $hint ), false, 12, JSON_THROW_ON_ERROR );
	}

	/** Compare only the closed advisory shape; it is never an operation schema. */
	private static function effectiveness_keys( object $value, array $expected ): bool {
		$keys = array_keys( get_object_vars( $value ) );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}

	/** Read only exact already-public native group values. */
	private static function effectiveness_group( object $node, string $path ): ?object {
		$value = $node->attributes ?? null;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! $value instanceof \stdClass || ! property_exists( $value, $part ) ) {
				return null;
			}
			$value = $value->{$part};
		}
		return $value instanceof \stdClass ? $value : null;
	}

	/** Resolve only a closed scalar enum in its original public schema resource. */
	private static function effectiveness_enum( ?object $schema, object $root, array $seen = array(), int $depth = 0 ): ?array {
		if ( ! $schema instanceof \stdClass || $depth > 8 || true === ( $schema->{'readOnly'} ?? false ) ) {
			return null;
		}
		$annotations = array( 'title', 'description', 'default', 'examples', 'readOnly' );
		if ( isset( $schema->{'$ref'} ) ) {
			$reference = $schema->{'$ref'};
			if ( array_diff( array_keys( get_object_vars( $schema ) ), array_merge( $annotations, array( '$ref' ) ) ) || ! is_string( $reference ) || ! preg_match( '/^#\/(definitions|\$defs)\/([A-Za-z_][A-Za-z0-9_-]{0,127})$/D', $reference, $match ) || isset( $seen[ $reference ] ) ) {
				return null;
			}
			$seen[ $reference ] = true;
			return self::effectiveness_enum( $root->{$match[1]}->{$match[2]} ?? null, $root, $seen, $depth + 1 );
		}
		if ( isset( $schema->{'allOf'} ) ) {
			if ( array_diff( array_keys( get_object_vars( $schema ) ), array_merge( $annotations, array( 'allOf' ) ) ) || ! is_array( $schema->{'allOf'} ) || 1 !== count( $schema->{'allOf'} ) ) {
				return null;
			}
			return self::effectiveness_enum( $schema->{'allOf'}[0] instanceof \stdClass ? $schema->{'allOf'}[0] : null, $root, $seen, $depth + 1 );
		}
		if ( array_diff( array_keys( get_object_vars( $schema ) ), array_merge( $annotations, array( 'type', 'enum' ) ) ) || ! is_array( $schema->enum ?? null ) || ! $schema->enum ) {
			return null;
		}
		$types = is_string( $schema->type ?? null ) ? array( $schema->type ) : ( $schema->type ?? array() );
		if ( ! is_array( $types ) || array_diff( $types, array( 'string', 'integer', 'number', 'boolean', 'null' ) ) ) {
			return null;
		}
		foreach ( $schema->enum as $value ) {
			$type = null === $value ? 'null' : ( is_string( $value ) ? 'string' : ( is_bool( $value ) ? 'boolean' : ( is_int( $value ) ? 'integer' : ( is_float( $value ) ? 'number' : 'unsupported' ) ) ) );
			if ( 'unsupported' === $type || ( $types && ! in_array( $type, $types, true ) && ! ( 'integer' === $type && in_array( 'number', $types, true ) ) ) ) {
				return null;
			}
		}
		return $schema->enum;
	}

	/** Model discovery index; full catalog stays authoritative behind the gateway. */
	public static function discovery( object $catalog, array $priority = array() ): object {
		$packet = (object) array( 'contract' => 'directorist-ai/builder-discovery-v1', 'builder' => $catalog->builder, 'document_kind' => $catalog->document_kind, 'directory_id' => $catalog->directory_id, 'revision' => $catalog->revision, 'state' => 'complete_for_discovery', 'components' => array(), 'omitted_components' => 0, 'next_read' => 'Use builder.inspect with include_authoring true and at most six authoring_components to obtain exact planning schemas and relationships. This discovery index does not authorize native writes.' );
		// Index completeness refers only to the current authorized catalog, never
		// to all possible native modules. Preserve degraded provider coverage.
		$packet->catalog_provider_state = 'complete';
		$packet->unavailable_providers  = array();
		foreach ( $catalog->providers as $provider ) {
			if ( 'available' !== $provider->status ) {
				$packet->catalog_provider_state  = 'incomplete';
				$packet->unavailable_providers[] = (object) array( 'reader_id' => $provider->reader_id, 'status' => $provider->status );
			}
		}
		$components = $catalog->components;
		// Keep currently declared creation identities discoverable within the same
		// bounded index. Alphabetical Directorist aliases must not hide core widgets.
		usort(
			$components,
			static function ( object $left, object $right ) use ( $priority ): int {
				$left_key  = $left->source_provider_id . '|' . $left->native_id;
				$right_key = $right->source_provider_id . '|' . $right->native_id;
				$rank      = ( isset( $priority[ $left_key ] ) ? 0 : 1 ) <=> ( isset( $priority[ $right_key ] ) ? 0 : 1 );
				return 0 !== $rank ? $rank : strcmp( $left_key, $right_key );
			}
		);
		foreach ( $components as $component ) {
			$card                 = (object) array( 'provider_id' => $component->source_provider_id, 'native_id' => $component->native_id, 'availability' => $component->availability, 'operations' => array_values( array_unique( array_map( static fn( object $feature ): string => $feature->operation, $component->features ), SORT_STRING ) ) );
			$packet->components[] = $card;
			if ( count( $packet->components ) > 100 || strlen( wp_json_encode( $packet ) ) > 24576 ) {
				array_pop( $packet->components );
				break;
			}
		}
		$packet->omitted_components = count( $components ) - count( $packet->components );
		if ( $packet->omitted_components > 0 ) {
			$packet->state = 'needs_narrowing';
		}
		return $packet;
	}

	public function describe( object $catalog, object $graph, array $selection, array $candidates, bool $server_completion = false, bool $existing_parents_only = false, array $control_paths = array(), bool $insertion_only = false, ?callable $creation_controls = null, array $insertion_schemas = array(), bool $native_factory = false ): object {
		// Only a server-matched native factory may carry its complete creation
		// schemas. Ordinary saved-node discovery retains the smaller read budget.
		if ( $native_factory && ( ! $insertion_only || $control_paths ) ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'A native factory packet requires its matched insertion-only contract.' );
		}
		$packet_byte_limit = $native_factory ? self::MAX_METADATA_BYTES : 24576;
		if ( $insertion_schemas && ( ! $insertion_only || $control_paths || count( $insertion_schemas ) > 7 ) ) { throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Bounded native insertion schemas require a matched insertion-only contract.' ); }
		if ( $insertion_schemas ) { self::assert_metadata( (object) $insertion_schemas ); }
		if ( count( $control_paths ) > 200 ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Control inspection paths must be bounded and distinct.' );
		}
		foreach ( $control_paths as $path ) {
			if ( ! is_string( $path ) || strlen( $path ) > 256 || 1 !== preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,15}$/D', $path ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Control inspection path is invalid.' );
			}
		}
		if ( count( array_unique( $control_paths, SORT_STRING ) ) !== count( $control_paths ) ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Control inspection paths must be distinct.' );
		}
		$control_index = array();
		$control_hints = array();
		$wanted        = array();
		$source_keys   = array();
		$canonical     = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$selected      = array_fill_keys( array_map( static fn( object $locator ): string => $canonical->encode( $locator ), $selection ), true );
		foreach ( $graph->nodes as $node ) {
			$source_keys[ $node->provider_id . '|' . $node->native_id ] = true;
			if ( $selected && ! isset( $selected[ $canonical->encode( $node->locator ) ] ) ) {
				continue;
			}
			$wanted[ $node->provider_id . '|' . $node->native_id ] = (object) array( 'provider_id' => $node->provider_id, 'native_id' => $node->native_id );
		}
		$candidate_keys = array();
		foreach ( $candidates as $candidate ) {
			$wanted[ $candidate->provider_id . '|' . $candidate->native_id ]         = $candidate;
			$candidate_keys[ $candidate->provider_id . '|' . $candidate->native_id ] = true;
		}
		ksort( $wanted, SORT_STRING );
		$packet = (object) array( 'state' => 'complete_for_selection', 'readiness_scope' => 'requested_component_set', 'catalog_revision' => $catalog->revision, 'semantic_rule_set_revision' => $this->relationships->rule_set_revision(), 'candidate_relationship_completion' => $server_completion ? 'installed_adapter' : 'explicit_plan', 'components' => array(), 'relationships' => array(), 'diagnostics' => array(), 'unavailable_requested_components' => array(), 'requires_full_server_validation' => true );
		if ( $existing_parents_only ) {
			$packet->source_contract_mode = 'existing_insertion_parents';
		}
		if ( $insertion_only ) {
			$packet->source_contract_mode = 'new_insertions_only';
		}
		if ( count( $wanted ) > 30 ) {
			return $this->narrow( $packet, 'component_contract_bound_exceeded', $candidates );
		}
		$available = array();
		foreach ( $catalog->components as $component ) {
			$available[ $component->source_provider_id . '|' . $component->native_id ] = $component;
		}
		// Dependency pseudo-nodes have no editor widget/catalog entry. Derive only
		// those required by an eligible installed component and its trusted rules.
		$dependencies = array();
		foreach ( $wanted as $key => $identity ) {
			$component = $available[ $key ] ?? null;
			$policy    = $this->policies->component( $identity->provider_id, $identity->native_id );
			$rule      = $this->relationships->component_rule( $identity->provider_id, $identity->native_id );
			if ( null === $component || null === $policy || null === $rule || ! in_array( $component->availability, array( 'available', 'read_only' ), true ) ) {
				continue;
			}
			foreach ( $rule->requires_dependencies as $dependency ) {
				$proven = false;
				foreach ( $this->relationships->dependency_targets( $identity->provider_id, $identity->native_id ) as $target ) {
					if ( ! in_array( $dependency, $target->rule->dependency_ids, true ) ) {
						continue;
					}
					$dependency_identity = $target->component;
					$dependency_policy   = $this->policies->component( $dependency_identity->provider_id, $dependency_identity->native_id );
					$schema              = $dependency_policy->insert_attributes_schema ?? null;
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Standard JSON Schema property.
					if ( null === $dependency_policy || $dependency_policy->controls || ! ( $schema instanceof \stdClass ) || 'object' !== ( $schema->type ?? null ) || ! ( ( $schema->properties ?? null ) instanceof \stdClass ) || get_object_vars( $schema->properties ) || false !== ( $schema->additionalProperties ?? null ) || ! empty( $schema->required ) ) {
						continue;
					}
					$dependency_key                  = $dependency_identity->provider_id . '|' . $dependency_identity->native_id;
					$dependencies[ $dependency_key ] = true;
					$wanted[ $dependency_key ]       = (object) array( 'provider_id' => $dependency_identity->provider_id, 'native_id' => $dependency_identity->native_id );
					$proven                          = true;
				}
				// Saved dependencies can already be covered by the source graph.
				// Missing insertion policies narrow new candidate authoring only;
				// they do not block an unrelated existing-node text refinement.
				if ( ! $proven && ! $server_completion && isset( $candidate_keys[ $key ] ) && ! isset( $source_keys[ $key ] ) ) {
					$packet->state         = 'needs_narrowing';
					$packet->diagnostics[] = (object) array( 'code' => 'dependency_authoring_unavailable', 'provider_id' => $identity->provider_id, 'native_id' => $identity->native_id, 'dependency_id' => $dependency );
				}
			}
		}
		ksort( $wanted, SORT_STRING );
		if ( count( $wanted ) > 30 ) {
			return $this->narrow( $packet, 'component_contract_bound_exceeded', $candidates );
		}
		foreach ( $wanted as $key => $identity ) {
			$dependency = isset( $dependencies[ $key ] );
			$component  = $available[ $key ] ?? null;
			$policy     = $this->policies->component( $identity->provider_id, $identity->native_id );
			$rule       = $this->relationships->component_rule( $identity->provider_id, $identity->native_id );
			if ( ( ! $dependency && ( null === $component || ! in_array( $component->availability, array( 'available', 'read_only' ), true ) ) ) || null === $policy || null === $rule ) {
				// Structural/context-only graph nodes have no editable catalog policy.
				$packet->diagnostics[] = (object) array( 'code' => 'component_authoring_unavailable', 'provider_id' => $identity->provider_id, 'native_id' => $identity->native_id );
				if ( isset( $candidate_keys[ $key ] ) ) {
					$packet->state                              = 'needs_narrowing';
					$packet->unavailable_requested_components[] = $identity;
				}
				continue;
			}
			$private_paths = array_map( static fn( object $control ): string => $control->path, array_filter( $policy->controls, static fn( object $control ): bool => $control->private ) );
			$controls      = array_values(
				array_filter(
					$policy->controls,
					static function ( object $control ) use ( $private_paths ): bool {
						if ( $control->private ) {
							return false;
						}
						foreach ( $private_paths as $private_path ) {
							if ( str_starts_with( $private_path, $control->path . '.' ) || str_starts_with( $control->path, $private_path . '.' ) ) {
								return false;
							}
						}
						return true;
					}
				)
			);
			// Withhold the entire insert schema when any private branch exists. No
			// private property names, defaults, enums or descendant constraints leak.
			$insert = count( $controls ) === count( $policy->controls ) ? $policy->insert_attributes_schema : null;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Draft-07 uses the exact additionalProperties property.
			$selected_only = null !== $insert && 'object' === ( $insert->type ?? null ) && false === ( $insert->additionalProperties ?? null ) && ( $insert->properties ?? null ) instanceof \stdClass && array_filter( $insert->required ?? array(), static fn( string $name ): bool => ! property_exists( $insert->properties, $name ) );
			if ( $selected_only ) {
				$insert = null; }
			if ( isset( $insertion_schemas[$key] ) ) {
				$bounded = $insertion_schemas[$key];
				$native_insert = $policy->insert_attributes_schema;
				if ( ! $bounded instanceof \stdClass || 'object' !== ( $bounded->type ?? null ) || false !== ( $bounded->additionalProperties ?? null ) || ! ( $bounded->properties ?? null ) instanceof \stdClass || ! ( $native_insert->properties ?? null ) instanceof \stdClass || array_diff( array_keys( get_object_vars( $bounded->properties ) ), array_keys( get_object_vars( $native_insert->properties ) ) ) || array_diff( array_keys( get_object_vars( $bounded->properties ) ), array( 'initial_controls' ) ) ) { throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Bounded native insertion schemas cannot introduce new attributes.' ); }
				$initial = $bounded->properties->initial_controls ?? null;
				$definitions = $bounded->definitions ?? new \stdClass(); $used_definitions = array();
				if ( ! $definitions instanceof \stdClass || count( get_object_vars( $definitions ) ) > 8 ) { throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Bounded native creation definitions are unavailable.' ); }
				if ( null !== $initial ) {
					if ( 'array' !== ( $initial->type ?? null ) || 1 !== ( $initial->minItems ?? null ) || ! is_int( $initial->maxItems ?? null ) || $initial->maxItems > 8 || ! is_array( $initial->items->oneOf ?? null ) ) { throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Bounded native creation controls require closed public scalar branches.' ); }
					foreach ( $initial->items->oneOf as $branch ) {
						$paths = $branch->properties->control_path->enum ?? array(); $public = false;
						$value_schema = $branch->properties->value ?? null;
						if ( $value_schema instanceof \stdClass && property_exists( $value_schema, '$ref' ) ) {
							if ( array( '$ref' ) !== array_keys( get_object_vars( $value_schema ) ) || ! is_string( $value_schema->{'$ref'} ) || ! preg_match( '/^#\/definitions\/([A-Za-z][A-Za-z0-9_]{0,63})$/D', $value_schema->{'$ref'}, $reference ) || ! ( $definitions->{$reference[1]} ?? null ) instanceof \stdClass ) { throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Bounded native creation values require exact local definitions.' ); }
							$used_definitions[$reference[1]] = true; $value_schema = $definitions->{$reference[1]};
						}
						foreach ( $policy->controls as $control ) { if ( 1 === count( $paths ) && false === $control->private && $control->path === $paths[0] && $canonical->encode( $control->value_schema ) === $canonical->encode( $value_schema ) ) { $public = true; break; } }
						if ( ! $public || 'object' !== ( $branch->type ?? null ) || false !== ( $branch->additionalProperties ?? null ) || array( 'control_path', 'value' ) !== ( $branch->required ?? null ) || array( 'control_path', 'value' ) !== array_keys( get_object_vars( $branch->properties ) ) ) { throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Bounded native creation controls cannot expose a private or altered native schema.' ); }
					}
				}
				if ( array_diff( array_keys( get_object_vars( $definitions ) ), array_keys( $used_definitions ) ) ) { throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Bounded native creation definitions must each be a verified public value schema.' ); }
				// Only individually verified existing public controls enter this matched
				// creation subset; all cold policy and native checks remain unchanged.
				$insert = $bounded;
			}
			$parent_only   = $existing_parents_only && ! isset( $candidate_keys[ $key ] ) && ! $dependency;
			$new_component = ! $dependency && isset( $candidate_keys[ $key ] ) && ! isset( $source_keys[ $key ] );

			if ( $control_paths && $new_component && null !== $insert && null !== $creation_controls ) {
				$creation = $creation_controls( clone $identity, $control_paths );
				if ( null !== $creation ) {
					( new \DirectoristAIPro\Validation\JsonDecoder( 16384, 16 ) )->check( $creation );
					if ( ! $creation instanceof \stdClass || array( 'controls', 'schema' ) !== array_keys( get_object_vars( $creation ) ) || ! is_array( $creation->controls ) || count( $creation->controls ) > 200 || ! $creation->schema instanceof \stdClass ) {
						throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Native creation control read is invalid.' ); }
					foreach ( $creation->controls as $control ) {
						if ( ! $control instanceof \stdClass || ! is_string( $control->path ?? null ) || ! in_array( $control->path, $control_paths, true ) || false !== ( $control->private ?? null ) || array() !== ( $control->field_types ?? null ) || ! $control->value_schema instanceof \stdClass ) {
							throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Native creation control is not public or requested.' ); }
					}
					$allowed  = $insert->properties->initial_controls->items->properties->control_path->enum ?? null;
					$declared = $creation->schema->items->properties->control_path->enum ?? null;
					if ( ! is_array( $allowed ) || ! is_array( $declared ) || array_diff( $declared, $allowed ) ) {
						throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Creation reads cannot add undeclared insertion controls.' ); }
					$controls = $creation->controls;
					if ( null !== $insert ) {
						$insert                               = clone $insert;
						$insert->properties                   = clone $insert->properties;
						$insert->properties->initial_controls = $creation->schema; }
				}
			}

			if ( $insertion_only || $existing_parents_only || ( $new_component && ! $control_paths ) ) {
				// The private-path fence above still decides whether insertion is public.
				// Creation needs the exact insertion schema, not saved-node edit groups.
				$controls = array();
			}
			$public_paths = array_column( $controls, 'path' );
			foreach ( $controls as $control ) {
				$description = $control->value_schema->description ?? null;
				if ( is_string( $description ) && '' !== $description && preg_match( '/^native_(?:controls|style|query)\./', $control->path ) ) {
					$control_hints[ $key ][ $control->path ] = mb_strcut( $description, 0, 160, 'UTF-8' );
				}
			}
			if ( ! $dependency && ! $parent_only ) {
				$control_index[] = (object) array( 'provider_id' => $identity->provider_id, 'native_id' => $identity->native_id, 'paths' => $public_paths );
			}
			if ( $control_paths ) {
				// Inspection may resolve a native member to its declared whole group;
				// insertion and writable paths remain the frozen adapter contracts.
				$insert = null;
			}
			if ( $parent_only ) {
				$controls = array();
				$insert   = null;
			}
			$packet->components[] = (object) array(
				'provider_id'              => $identity->provider_id,
				'native_id'                => $identity->native_id,
				'policy_revision'          => $policy->revision,
				'saved_instance_available' => isset( $source_keys[ $key ] ),
				'authoring_role'           => $new_component ? 'new_component_insertion' : 'saved_component',
				'availability'             => $dependency ? 'planning_only' : $component->availability,
				'features'                 => $dependency ? array() : $component->features,
				'insert_attributes_schema' => $insert,
				'controls'                 => $controls,
				'required_contexts'        => $rule->requires_contexts,
				'required_dependencies'    => $rule->requires_dependencies,
				'existing_parent_only'     => $parent_only,
				'selected_only'            => (bool) $selected_only,
			);
		}
		if ( $control_paths ) {
			self::resolve_requested_controls( $packet, $control_paths );
		}
		foreach ( $wanted as $source ) {
			foreach ( $wanted as $target ) {
				foreach ( array( 'contains', 'context', 'field', 'style', 'dependency' ) as $kind ) {
					foreach ( $this->relationships->relationship_rules( $kind, $source->provider_id, $source->native_id, $target->provider_id, $target->native_id ) as $rule ) {
						// Tuple identities and immutable rule revision bind this compact
						// guidance to the full frozen rule checked by the server. Repeated
						// provenance/resolver IDs are not authoring inputs.
						$public_rule             = (object) array( 'id' => $rule->id, 'revision' => $rule->revision, 'kind' => $rule->kind, 'context_id' => $rule->context_id, 'field_types' => $rule->field_types, 'dependency_ids' => $rule->dependency_ids, 'reference_mode' => $rule->reference_mode, 'scope_policy' => $rule->scope_policy );
						$packet->relationships[] = (object) array( 'source' => $source, 'target' => $target, 'rule' => $public_rule );
						if ( count( $packet->relationships ) > 256 ) {
							return $this->narrow( $packet, 'relationship_contract_bound_exceeded', $candidates );
						}
					}
				}
			}
		}
		if ( $packet->unavailable_requested_components ) {
			$packet->suggested_authoring_components = array_values(
				array_map(
					static fn( object $component ): object => (object) array( 'provider_id' => $component->provider_id, 'native_id' => $component->native_id ),
					array_filter( $packet->components, static fn( object $component ): bool => isset( $candidate_keys[ $component->provider_id . '|' . $component->native_id ] ) && null !== $component->insert_attributes_schema )
				)
			);
			$packet->next_read                      = 'Only unavailable_requested_components lack requested contracts. Other returned components retain their public private-draft schemas. Catalog availability read_only restricts direct native mutation, not isolated private-draft planning. Inspect suggested_authoring_components without the unavailable identities; do not repeat the mixed batch or claim every component is unavailable. A complete ready_subsets contract, when present, is an exact supported alternative for this same graph; follow only its declared components, relationships and operation bounds.';
		}
		$oversized = strlen( $canonical->encode( $packet ) ) > $packet_byte_limit;
		if ( $oversized || $control_paths ) {
			$packet->control_index          = array();
			$packet->control_index_complete = true;
			foreach ( $control_index as $item ) {
				if ( strlen( $canonical->encode( $packet->control_index ) ) + strlen( $canonical->encode( $item ) ) > 16384 ) {
					$packet->control_index_complete = false;
					break;
				}
				$packet->control_index[] = $item;
			}
			// Exact paths take priority over optional human-readable discovery
			// hints. A hint never substitutes for the complete control schema.
			foreach ( $packet->control_index as $item ) {
				foreach ( $control_hints[ $item->provider_id . '|' . $item->native_id ] ?? array() as $path => $hint ) {
					if ( strlen( $canonical->encode( $packet->control_index ) ) + strlen( $hint ) + strlen( $path ) + 32 > 16384 ) {
						break; }
					$item->hints          = $item->hints ?? new \stdClass();
					$item->hints->{$path} = $hint;
				}
			}
		}
		// An exact filtered read needs its complete requested schemas, not a
		// repeated index of every other control. Optional discovery metadata must
		// not make an otherwise complete small read fail its packet bound.
		if ( $control_paths && isset( $packet->control_index ) && strlen( $canonical->encode( $packet ) ) > $packet_byte_limit ) {
			$index          = $packet->control_index;
			$index_complete = $packet->control_index_complete;
			unset( $packet->control_index, $packet->control_index_complete );
			if ( strlen( $canonical->encode( $packet ) ) > $packet_byte_limit ) {
				// A genuinely oversized contract still needs its recovery choices.
				$packet->control_index          = $index;
				$packet->control_index_complete = $index_complete;
			}
		}
		if ( strlen( $canonical->encode( $packet ) ) > $packet_byte_limit ) {
			if ( ! $existing_parents_only && ! $control_paths && $candidates ) {
				// New insertions need exact existing parents, not every unrelated
				// source edit schema. The full graph and server validation stay intact.
				$insertion_candidates = $candidates;
				if ( $selected ) {
					$selected_types = array();
					foreach ( $graph->nodes as $node ) {
						if ( isset( $selected[ $canonical->encode( $node->locator ) ] ) ) {
							$selected_types[ $node->provider_id . '|' . $node->native_id ] = true;
						}
					}
					// Inspecting the selected component itself is ordinary saved-node
					// editing. It must retain its control-index recovery, not become
					// an insertion-only read just because it can contain its own type.
					$insertion_candidates = array_values( array_filter( $candidates, static fn( object $candidate ): bool => ! isset( $selected_types[ $candidate->provider_id . '|' . $candidate->native_id ] ) ) );
				}
				$parents = array();
				foreach ( $graph->nodes as $node ) {
					// A selected conversation can discover insertion contracts only
					// beneath its exact inspected parent, never an unrelated container.
					if ( $selected && ! isset( $selected[ $canonical->encode( $node->locator ) ] ) ) {
						continue;
					}
					foreach ( $insertion_candidates as $candidate ) {
						if ( $this->relationships->supports_relationship( 'contains', $node->provider_id, $node->native_id, $candidate->provider_id, $candidate->native_id ) ) {
							$parents[] = $node->locator;
							break;
						}
					}
				}
				if ( $parents ) {
					return $this->describe( $catalog, $graph, $parents, $candidates, $server_completion, true, $control_paths, $insertion_only, $creation_controls, $insertion_schemas, $native_factory );
				}
			}
			return $this->narrow( $packet, 'authoring_contract_byte_bound_exceeded', $candidates );
		}
		return $packet;
	}

	/** Resolve inspection names only; the returned control path remains canonical. */
	private static function resolve_requested_controls( object $packet, array $requested ): void {
		$packet->requested_control_paths  = $requested;
		$packet->control_path_resolutions = array();
		$packet->ambiguous_control_paths  = array();
		$keep                             = array();
		foreach ( $requested as $path ) {
			$direct  = array();
			$members = array();
			foreach ( $packet->components as $component ) {
				foreach ( $component->controls as $control ) {
					if ( false !== ( $control->private ?? null ) ) {
						continue;
					}
					$match = (object) array( 'requested_path' => $path, 'provider_id' => $component->provider_id, 'native_id' => $component->native_id, 'control_path' => $control->path );
					if ( $path === $control->path ) {
						$direct[] = $match;
						continue;
					}
					$schema = $control->value_schema ?? null;
					// Only installed native families declare these grouped member names.
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Standard JSON Schema property.
					if ( preg_match( '/^native_(?:controls|style|query)\./', $control->path ) && $schema instanceof \stdClass && 'object' === ( $schema->type ?? null ) && false === ( $schema->additionalProperties ?? null ) && true !== ( $schema->{'readOnly'} ?? false ) && ! isset( $schema->{'$ref'} ) && ! isset( $schema->{'allOf'} ) && ! isset( $schema->{'oneOf'} ) && ! isset( $schema->{'anyOf'} ) && ( $schema->properties ?? null ) instanceof \stdClass && property_exists( $schema->properties, $path ) && self::inspection_member_public( $schema->properties->{$path}, $schema ) ) {
						$members[] = $match;
					}
				}
			}
			$matches = $direct ? $direct : ( 1 === count( $members ) ? $members : array() );
			if ( ! $direct && count( $members ) > 1 ) {
				$packet->ambiguous_control_paths[] = $path;
			}
			foreach ( $matches as $match ) {
				$keep[ $match->provider_id . '|' . $match->native_id ][ $match->control_path ] = true;
				$packet->control_path_resolutions[] = $match;
			}
		}
		foreach ( $packet->components as $component ) {
			$paths               = $keep[ $component->provider_id . '|' . $component->native_id ] ?? array();
			$component->controls = array_values( array_filter( $component->controls, static fn( object $control ): bool => isset( $paths[ $control->path ] ) ) );
		}
		self::check_requested_control_coverage( $packet );
	}

	/** Follow only bounded local native member references; annotations grant no writes. */
	private static function inspection_member_public( mixed $schema, object $root, array $seen = array(), int $depth = 0 ): bool {
		if ( ! $schema instanceof \stdClass || $depth > 8 || true === ( $schema->{'readOnly'} ?? false ) ) {
			return false;
		}
		$annotations = array( 'title', 'description', 'default', 'examples', 'readOnly' );
		if ( isset( $schema->{'$ref'} ) ) {
			$reference = $schema->{'$ref'};
			if ( array_diff( array_keys( get_object_vars( $schema ) ), array_merge( $annotations, array( '$ref' ) ) ) || ! is_string( $reference ) || ! preg_match( '/^#\/(definitions|\$defs)\/([A-Za-z_][A-Za-z0-9_-]{0,127})$/D', $reference, $match ) || isset( $seen[ $reference ] ) ) {
				return false;
			}
			$seen[ $reference ] = true;
			return self::inspection_member_public( $root->{$match[1]}->{$match[2]} ?? null, $root, $seen, $depth + 1 );
		}
		if ( isset( $schema->{'allOf'} ) ) {
			return ! array_diff( array_keys( get_object_vars( $schema ) ), array_merge( $annotations, array( 'allOf' ) ) ) && is_array( $schema->{'allOf'} ) && 1 === count( $schema->{'allOf'} ) && self::inspection_member_public( $schema->{'allOf'}[0], $root, $seen, $depth + 1 );
		}
		return ! isset( $schema->{'oneOf'} ) && ! isset( $schema->{'anyOf'} ) && ! isset( $schema->{'not'} ) && ! isset( $schema->{'if'} );
	}

	/** Recheck returned contracts after every saved-node availability filter. */
	private static function check_requested_control_coverage( object $packet ): void {
		if ( empty( $packet->requested_control_paths ) ) {
			return;
		}
		// A byte-bound response deliberately omits complete contracts and
		// resolution metadata. Preserve its smaller-read recovery instructions;
		// absence here does not establish that the requested controls are missing.
		if ( 'needs_narrowing' === ( $packet->state ?? null ) && false === ( $packet->can_propose_private_draft ?? null ) && array() === ( $packet->components ?? null ) && false === ( $packet->control_request_metadata_complete ?? null ) && in_array( 'authoring_contract_byte_bound_exceeded', array_column( $packet->diagnostics ?? array(), 'code' ), true ) ) {
			return;
		}
		$returned = array();
		foreach ( $packet->components ?? array() as $component ) {
			foreach ( $component->controls ?? array() as $control ) {
				if ( false === ( $control->private ?? null ) && ( $control->value_schema ?? null ) instanceof \stdClass ) {
					$returned[ $component->provider_id . '|' . $component->native_id ][ $control->path ] = true;
				}
			}
		}
		$covered = array();
		foreach ( $packet->control_path_resolutions ?? array() as $resolution ) {
			$present                                = isset( $returned[ $resolution->provider_id . '|' . $resolution->native_id ][ $resolution->control_path ] );
			$covered[ $resolution->requested_path ] = ( $covered[ $resolution->requested_path ] ?? true ) && $present;
		}
		$packet->missing_control_paths = array_values( array_filter( $packet->requested_control_paths, static fn( string $path ): bool => true !== ( $covered[ $path ] ?? false ) ) );
		if ( $packet->missing_control_paths ) {
			$packet->state                     = 'needs_narrowing';
			$packet->can_propose_private_draft = false;
			$packet->diagnostics               = array_values( array_filter( $packet->diagnostics ?? array(), static fn( object $item ): bool => 'requested_controls_unresolved' !== ( $item->code ?? null ) ) );
			$packet->diagnostics[]             = (object) array( 'code' => 'requested_controls_unresolved' );
			$packet->next_read                 = 'Some requested_control_paths have no complete returned public contract. Use missing_control_paths and the qualified canonical choices in control_index; request the exact intended component and canonical group path. Ambiguous member names require a canonical group choice. Do not repeat the unchanged unresolved read or propose a partial change. A control_path_resolutions entry resolves inspection only: set_control must use its canonical control_path and the entire closed group copied from the selected node, preserving every unchanged member. Unknown or unavailable controls require clarification.';
			if ( strlen( ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $packet ) ) > 24576 ) {
				self::narrow( $packet, 'authoring_contract_byte_bound_exceeded', array() );
			}
		}
	}

	private static function narrow( object $packet, string $code, array $candidates ): object {
		// Concrete smaller reads prevent repeating an identical oversized packet.
		// These identities are discovery hints, never editable contracts.
		$identities = array();
		foreach ( $candidates as $candidate ) {
			$identities[ $candidate->provider_id . '|' . $candidate->native_id ] = (object) array( 'provider_id' => $candidate->provider_id, 'native_id' => $candidate->native_id );
		}
		$width                             = max( 1, min( 6, intdiv( count( $identities ), 2 ) ) );
		$packet->retry_authoring_groups    = array_chunk( array_values( $identities ), $width );
		$packet->next_read                 = 'Inspect one retry_authoring_groups batch at a time. Retain complete contracts for the same graph/catalog/rules revision. If a batch still exceeds its bound, halve it again; never repeat the unchanged request. If a single candidate still exceeds the bound, use selection with the exact intended saved parent/region locator from the returned graph to omit unrelated source schemas. When control_index is present, it lists public native control paths, never values or write authority: inspect the requested component with control_paths containing only the needed exact paths. If that filtered read still exceeds its bound, request one control path per read and retain complementary contracts for the same revision. A grouped control requires its complete closed value schema before proposing. These hints do not authorize a proposal.';
		$packet->state                     = 'needs_narrowing';
		$packet->components                = array();
		$packet->relationships             = array();
		$packet->diagnostics               = array( (object) array( 'code' => $code ) );
		$packet->can_propose_private_draft = false;
		// Discovery metadata may be omitted; native schemas and values are never
		// shortened into a supposedly complete authoring contract.
		if ( isset( $packet->requested_control_paths ) ) {
			$packet->requested_control_path_count      = count( $packet->requested_control_paths );
			$packet->control_request_metadata_complete = false;
			unset( $packet->control_path_resolutions, $packet->missing_control_paths, $packet->ambiguous_control_paths );
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		if ( strlen( $canonical->encode( $packet ) ) > 24576 ) {
			unset( $packet->requested_control_paths );
		}
		while ( strlen( $canonical->encode( $packet ) ) > 24576 && $packet->retry_authoring_groups ) {
			array_pop( $packet->retry_authoring_groups );
			$packet->retry_authoring_groups_complete = false;
		}
		if ( strlen( $canonical->encode( $packet ) ) > 24576 ) {
			$packet->control_index          = array();
			$packet->control_index_complete = false;
		}
		return $packet;
	}
}
