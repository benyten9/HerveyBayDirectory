<?php
/**
 * Model-only initial Elementor control reads under one exact native authority.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

// phpcs:disable WordPress.Files.FileName -- PSR-4 class filename follows the installed runtime.

use DirectoristAIPro\Builder\BuilderAuthoringContract;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** No runtime source, control schema, checkpoint or proposal is changed. */
final class BuilderInitialControlPrompt {
	public const REVISION = 'elementor-initial-control-prompt-v2-starter';

	/**
	 * Attach resolved new-component reads to their exact closed creation receipt.
	 *
	 * The caller must select initial-layout, unselected Elementor creation. Any
	 * unsupported or mixed authority leaves the entire input untouched.
	 *
	 * @param array $entries Expanded trusted Gateway receipts, never raw model input.
	 * @return array Detached model view, or the unchanged input when ineligible.
	 */
	public static function compact( array $entries ): array {
		$merged = self::control_reads( $entries );
		return $merged === $entries ? self::starter_view( $entries ) : $merged;
	}

	/**
	 * Keep the existing resolved-read projection independent of the starter view.
	 *
	 * @param array $entries Current trusted receipts.
	 * @return array Original or detached merged reads.
	 */
	private static function control_reads( array $entries ): array {
		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof \stdClass ) {
				return $entries; }
		}
		$canonical = new JsonCanonicalizer();
		$anchors   = array();
		foreach ( $entries as $index => $entry ) {
			if ( self::anchor( $entry, $canonical ) ) {
				$anchors[] = $index; }
		}
		if ( 1 !== count( $anchors ) ) {
			return $entries; }
		$anchor_index  = $anchors[0];
		$anchor        = $entries[ $anchor_index ];
		$identity      = self::authority( $anchor, $canonical );
		$relationships = self::starter_relationships( $anchor->response->data->authoring->relationships ?? null );
		if ( null === $relationships ) {
			return $entries; }
		$reads    = array();
		$remove   = array();
		$call_ids = array();
		foreach ( $entries as $index => $entry ) {
			if ( $index === $anchor_index || 'builder.inspect' !== ( $entry->response->operation ?? null ) ) {
				continue; }
			$input = self::input( $entry );
			if ( empty( $input->control_paths ) ) {
				continue; }
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare two current server-owned authority encodings.
			if ( $identity !== self::authority( $entry, $canonical ) || ! self::resolved( $entry, $anchor, $canonical ) ) {
				return $entries; }
			if ( isset( $call_ids[ $entry->call_id ] ) ) {
				return $entries; }
			$call_ids[ $entry->call_id ] = true;
			$authoring                   = $entry->response->data->authoring;
			$components                  = array();
			foreach ( $authoring->components as $component ) {
				$closed = new \stdClass();
				foreach ( array( 'provider_id', 'native_id', 'policy_revision', 'availability', 'saved_instance_available', 'authoring_role', 'controls', 'initial_control_values' ) as $field ) {
					if ( property_exists( $component, $field ) ) {
						$closed->{$field} = $component->{$field}; }
				}
				$components[] = $closed;
			}
			$read = (object) array(
				'call_id'                   => $entry->call_id,
				'state'                     => $authoring->state,
				'can_propose_private_draft' => $authoring->can_propose_private_draft,
				'diagnostics'               => $authoring->diagnostics ?? array(),
				'components'                => $components,
			);
			// Keep every exact path, resolution, preimage, native readiness condition
			// and next-read hint. Only duplicate global authoring/source metadata is
			// replaced by the explicit anchor authority and closed creation recipe.
			foreach ( array( 'requested_control_paths', 'missing_control_paths', 'ambiguous_control_paths', 'draft_readiness', 'next_read', 'retry_authoring_groups', 'unavailable_requested_components', 'suggested_authoring_components', 'candidate_relationship_completion', 'selected_area', 'control_effectiveness' ) as $field ) {
				if ( property_exists( $authoring, $field ) ) {
					$read->{$field} = $authoring->{$field}; }
			}
			if ( isset( $read->next_read, $read->draft_readiness->next_read ) && $read->next_read === $read->draft_readiness->next_read ) {
				unset( $read->next_read );
				$read->next_read_reference = 'draft_readiness.next_read';
			}
			$reads[]          = $read;
			$remove[ $index ] = true;
		}
		if ( ! $reads || count( $reads ) > 12 ) {
			return $entries; }
		try {
			$copy  = json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
			$reads = json_decode( wp_json_encode( $reads, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return $entries; }
		$authoring                                  = $copy[ $anchor_index ]->response->data->authoring;
		$authoring->relationships                   = json_decode( wp_json_encode( $relationships ), false, 16, JSON_THROW_ON_ERROR );
		$authoring->relationships_note              = 'This model view retains complete native contains rules for the declared one-Container starter: document to Container, and Container to Heading/Text Editor/Loop. Other relationship rules remain in stored receipts and cold validation; use an ordinary inspection for a different composition.';
		$authoring->creation_control_reads          = $reads;
		$authoring->creation_control_reads_revision = self::REVISION;
		$authoring->creation_control_reads_note     = 'These exact public control schemas and initial values describe only new components inside this closed creation recipe. Requested canonical paths equal their returned control.path exactly; aliases remain ordinary reads. Each read retains its original false standalone-proposal status, diagnostics and native readiness. They share this complete current document, scope, source, graph, facts, catalog and rules authority. Use only the declared initial_controls/initial_component_controls carriers; this model-only view grants no permission and saved targets need their own fresh inspection.';
		return array_values( array_filter( $copy, static fn( $entry, int $index ): bool => ! isset( $remove[ $index ] ), ARRAY_FILTER_USE_BOTH ) );
	}

	/**
	 * A verified typed starter needs only its four native relationship contracts.
	 *
	 * Unresolved reads and failed feedback stay byte exact. Every successful
	 * builder inspection must still share the full current anchor authority.
	 *
	 * @param array $entries Current trusted receipts.
	 * @return array Detached starter model view, or original unknown authority.
	 */
	private static function starter_view( array $entries ): array {
		$canonical = new JsonCanonicalizer();
		$anchors   = array();
		foreach ( $entries as $index => $entry ) {
			if ( ! $entry instanceof \stdClass ) {
				return $entries;
			}
			if ( self::anchor( $entry, $canonical ) ) {
				$anchors[] = $index;
			}
		}
		if ( 1 !== count( $anchors ) ) {
			return $entries;
		}
		$index  = $anchors[0];
		$anchor = $entries[ $index ];
		$recipe = $anchor->response->data->authoring->draft->first_loop;
		if ( 'directorist_listings_loop' !== ( $recipe->carrier_native_id ?? null ) || ! ( $recipe->carrier_attributes_schema ?? null ) instanceof \stdClass || 'object' !== ( $recipe->carrier_attributes_schema->type ?? null ) || false !== ( $recipe->carrier_attributes_schema->additionalProperties ?? null ) || array( 'directory_grid', 'directory_list' ) !== ( $recipe->carrier_attributes_schema->properties->native_loop_recipe->enum ?? null ) || 'array' !== ( $recipe->carrier_attributes_schema->properties->initial_component_controls->type ?? null ) || ! is_array( $recipe->carrier_attributes_schema->properties->initial_component_controls->items->oneOf ?? null ) || ! $recipe->carrier_attributes_schema->properties->initial_component_controls->items->oneOf ) {
			return $entries;
		}
		$identity = self::authority( $anchor, $canonical );
		foreach ( $entries as $entry ) {
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare complete current server-owned authority encodings.
			if ( true === ( $entry->response->ok ?? null ) && 'builder.inspect' === ( $entry->response->operation ?? null ) && $identity !== self::authority( $entry, $canonical ) ) {
				return $entries;
			}
		}
		$relationships = self::starter_relationships( $anchor->response->data->authoring->relationships ?? null );
		if ( null === $relationships ) {
			return $entries;
		}
		try {
			$copy = json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return $entries;
		}
		$authoring = $copy[ $index ]->response->data->authoring;
		try {
			$authoring->relationships = json_decode( wp_json_encode( $relationships ), false, 16, JSON_THROW_ON_ERROR );
			if ( ! self::starter_schemas( $authoring ) ) {
				return $entries;
			}
		} catch ( \Throwable $error ) {
			return $entries;
		}
		$authoring->relationships_note = 'This model view retains complete native contains rules for the declared one-Container starter: document to Container, and Container to Heading/Text Editor/Loop. Other relationship rules remain in stored receipts and cold validation; use an ordinary inspection for a different composition.';
		return $copy;
	}

	/**
	 * Share byte-identical, self-contained JSON Schemas in this model view only.
	 *
	 * Schema positions are traversed explicitly; default/const/enum/annotation
	 * payloads stay literal. Relative refs and resource/base keywords are never
	 * relocated. Resources can share smaller literal schemas without changing
	 * the expanded value schema. No native receipt or validator uses this view.
	 *
	 * @param object $authoring Detached typed starter authoring packet.
	 * @return bool Whether every created resource is closed and bounded.
	 */
	private static function starter_schemas( object $authoring ): bool {
		if ( property_exists( $authoring, 'starter_schema_resources' ) ) {
			return false;
		}
		$roots = array();
		foreach ( $authoring->components as $index => $component ) {
			$roots[] = array( 'components', $index, 'insert_attributes_schema' );
		}
		$roots[]           = array( 'draft', 'first_loop', 'carrier_attributes_schema' );
		$existing_ids      = array();
		$closed_references = true;
		foreach ( $roots as $path ) {
			$schema = &self::schema_at( $authoring, $path );
			self::schema_positions(
				$schema,
				$path,
				static function ( object $node ) use ( &$existing_ids, &$closed_references ): void {
					foreach ( array( '$id', '$anchor', '$dynamicAnchor', '$recursiveAnchor', '$dynamicRef', '$recursiveRef', '$schema', '$vocabulary' ) as $keyword ) {
						if ( property_exists( $node, $keyword ) ) {
							$closed_references = false;
						}
					}
					if ( is_string( $node->{'$id'} ?? null ) ) {
						$existing_ids[ $node->{'$id'} ] = true;
					}
					if ( property_exists( $node, '$ref' ) && ( ! is_string( $node->{'$ref'} ) || ! preg_match( '~^#/(?:definitions|\$defs)/[A-Za-z0-9_-]+$|^urn:dai:(?:creation-paths|ce):[a-f0-9]{24}$~D', $node->{'$ref'} ) ) ) {
						// Moving a literal parent could otherwise dangle an incoming
						// interior pointer even when that parent has no references.
						$closed_references = false;
					}
				},
				false
			);
			unset( $schema );
		}
		if ( ! $closed_references ) {
			return false;
		}
		$resources = array();
		for ( $iteration = 0; $iteration < 16; ++$iteration ) {
			$sets  = array();
			$visit = static function ( object $schema, array $path ) use ( &$sets ): void {
				$encoded = wp_json_encode( $schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
				if ( strlen( $encoded ) < 75 || preg_match( '/"\$(?:ref|id|anchor|dynamicRef|recursiveRef|dynamicAnchor|recursiveAnchor|schema|vocabulary)"\s*:/', $encoded ) ) {
					return;
				}
				$sets[ $encoded ][] = $path;
			};
			foreach ( $roots as $path ) {
				$schema = &self::schema_at( $authoring, $path );
				self::schema_positions( $schema, $path, $visit );
				unset( $schema );
			}
			$best = null;
			foreach ( $sets as $encoded => $paths ) {
				if ( count( $paths ) < 2 ) {
					continue;
				}
				$uri = 'urn:dai:es:' . substr( hash( 'sha256', $encoded ), 0, 16 );
				if ( isset( $existing_ids[ $uri ] ) ) {
					return false;
				}
				$resource  = (object) array_merge( array( '$id' => $uri ), get_object_vars( json_decode( $encoded, false, 32, JSON_THROW_ON_ERROR ) ) );
				$reference = (object) array( '$ref' => $uri );
				$saving    = strlen( $encoded ) * count( $paths ) - strlen( wp_json_encode( $resource ) ) - strlen( wp_json_encode( $reference ) ) * count( $paths );
				if ( $saving > 64 && ( null === $best || $saving > $best['saving'] ) && strlen( wp_json_encode( array_merge( $resources, array( $resource ) ) ) ) <= 16384 ) {
					$best = compact( 'saving', 'paths', 'resource', 'reference', 'uri' );
				}
			}
			if ( null === $best ) {
				break;
			}
			foreach ( $resources as $existing ) {
				if ( $existing->{'$id'} === $best['uri'] ) {
					return false;
				}
			}
			foreach ( $best['paths'] as $path ) {
				$schema = &self::schema_at( $authoring, $path );
				$schema = clone $best['reference'];
				unset( $schema );
			}
			$resources[]                         = $best['resource'];
			$authoring->starter_schema_resources = $resources;
			$roots[]                             = array( 'starter_schema_resources', count( $resources ) - 1 );
			// Keep earlier resource objects shared with this detached model packet.
			$resources = $authoring->starter_schema_resources;
		}
		if ( $resources ) {
			$authoring->starter_schema_resources_note = 'Resolve these exact JSON Schema urn resources in this model view. Native schemas and cold validation are unchanged.';
		}
		return true;
	}

	/**
	 * Read or replace one explicitly enumerated schema position.
	 *
	 * @param object $root Detached authoring packet.
	 * @param array  $path Property/index path found by schema_positions.
	 * @return mixed Exact schema position by reference.
	 */
	private static function &schema_at( object $root, array $path ) {
		$node = &$root;
		foreach ( $path as $key ) {
			if ( is_array( $node ) ) {
				$node = &$node[ $key ];
			} else {
				$node = &$node->{$key};
			}
		}
		return $node;
	}

	/**
	 * Traverse only JSON Schema positions, never literal payload objects.
	 *
	 * @param mixed    $node Current schema.
	 * @param array    $path Exact position in detached authoring.
	 * @param callable $visit Collect a schema location.
	 * @param bool     $skip_initial Preserve initializer templates during factoring; false audits every reference.
	 * @return void
	 */
	private static function schema_positions( $node, array $path, callable $visit, bool $skip_initial = true ): void {
		if ( ! $node instanceof \stdClass ) {
			return;
		}
		$visit( $node, $path );
		$maps   = array( 'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas' );
		$lists  = array( 'allOf', 'oneOf', 'anyOf', 'prefixItems' );
		$single = array( 'items', 'additionalItems', 'additionalProperties', 'unevaluatedItems', 'unevaluatedProperties', 'contains', 'propertyNames', 'not', 'if', 'then', 'else', 'contentSchema' );
		foreach ( get_object_vars( $node ) as $keyword => $value ) {
			if ( in_array( $keyword, $maps, true ) && $value instanceof \stdClass ) {
				foreach ( get_object_vars( $value ) as $key => $schema ) {
					// The later creation projection shares this complete initializer template.
					// Preserve its original bytes so both model-only reductions compose.
					if ( $skip_initial && 'properties' === $keyword && 'initial_controls' === $key ) {
						continue;
					}
					self::schema_positions( $schema, array_merge( $path, array( $keyword, $key ) ), $visit, $skip_initial );
				}
			} elseif ( in_array( $keyword, $lists, true ) && is_array( $value ) ) {
				foreach ( $value as $key => $schema ) {
					self::schema_positions( $schema, array_merge( $path, array( $keyword, $key ) ), $visit, $skip_initial );
				}
			} elseif ( in_array( $keyword, $single, true ) ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $key => $schema ) {
						self::schema_positions( $schema, array_merge( $path, array( $keyword, $key ) ), $visit, $skip_initial );
					}
				} else {
					self::schema_positions( $value, array_merge( $path, array( $keyword ) ), $visit, $skip_initial );
				}
			}
		}
	}

	/**
	 * Exact complete native source authority plus its validated read input.
	 *
	 * @param object            $entry Expanded trusted Gateway receipt.
	 * @param JsonCanonicalizer $canonical Canonical native value encoder.
	 */
	private static function authority( object $entry, JsonCanonicalizer $canonical ): ?string {
		$data  = $entry->response->data ?? null;
		$graph = $data->graph ?? null;
		$input = self::input( $entry );
		if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $input instanceof \stdClass || true !== ( $input->include_authoring ?? null ) || null !== ( $input->editor_binding_id ?? null ) || ! property_exists( $input, 'editor_binding_id' ) || 'elementor' !== ( $graph->document->builder ?? null ) || 'page' !== ( $graph->document->native_kind ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || array() !== ( $graph->omissions ?? null ) || true !== ( $data->semantic_report->accepted ?? null ) || null !== ( $graph->version->editor_epoch ?? null ) || $canonical->encode( $input->document ?? null ) !== $canonical->encode( $graph->document ) || ! is_string( $data->document_key ?? null ) ) {
			return null; }
		$hashes = array( $data->graph_hash ?? null, $data->fact_revision ?? null, $data->authoring->catalog_revision ?? null, $data->authoring->semantic_rule_set_revision ?? null, $graph->version->source_hash ?? null );
		foreach ( $hashes as $hash ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				return null; }
		}
		return $canonical->encode( array( $data->document_key, $graph, $hashes, $input->document, $input->directory_id ?? null, $input->scope ?? null, $input->editor_binding_id ) );
	}

	/**
	 * Only the installed exact four-component ordinary-page creation packet.
	 *
	 * @param object            $entry Expanded trusted Gateway receipt.
	 * @param JsonCanonicalizer $canonical Canonical native value encoder.
	 */
	private static function anchor( object $entry, JsonCanonicalizer $canonical ): bool {
		if ( null === self::authority( $entry, $canonical ) ) {
			return false; }
		$a      = $entry->response->data->authoring;
		$input  = self::input( $entry );
		$recipe = $a->draft->first_loop ?? null;
		if ( property_exists( $a, 'creation_control_reads' ) || 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) || 'elementor_native_root_layout_v1' !== ( $a->draft->creation_mode ?? null ) || ! BuilderAuthoringContract::has_actionable_contract( $a ) || ! empty( $input->control_paths ) || ! is_array( $input->selection ?? null ) || count( $input->selection ) !== 1 || $canonical->encode( $recipe->parent ?? null ) !== $canonical->encode( $input->selection[0] ) || $canonical->encode( $recipe->scope ?? null ) !== $canonical->encode( $input->scope ?? null ) || null !== ( $recipe->scope->view ?? null ) || null !== ( $recipe->scope->breakpoint_id ?? null ) || 'normal' !== ( $recipe->scope->interaction ?? null ) || array( 'document' ) !== ( $recipe->parent->slot_path ?? null ) || ! is_array( $recipe->subtree->nodes ?? null ) || count( $recipe->subtree->nodes ) !== 4 ) {
			return false; }
		$roots = array();
		foreach ( $entry->response->data->graph->nodes ?? array() as $node ) {
			if ( in_array( $node->native_id ?? null, array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) ) {
				return false; }
			if ( 'directorist-ai' === ( $node->provider_id ?? null ) && 'elementor-document' === ( $node->native_id ?? null ) && null === ( $node->opaque_ref ?? null ) ) {
				$roots[] = $node; }
		}
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both locator and scope encodings are current server values.
		if ( 1 !== count( $roots ) || $canonical->encode( $roots[0]->locator ?? null ) !== $canonical->encode( $recipe->parent ) || $canonical->encode( $roots[0]->scope ?? null ) !== $canonical->encode( $recipe->scope ) ) {
			return false; }
		$children = array_filter( $entry->response->data->graph->edges ?? array(), static fn( object $edge ): bool => 'contains' === ( $edge->kind ?? null ) && ( $edge->source ?? null ) === $roots[0]->id && null === ( $edge->target_document_key ?? null ) );
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare the actual native child count with the trusted recipe.
		if ( count( $children ) !== ( $recipe->position ?? null ) ) {
			return false; }
		$expected = array( 'directorist-elementor|directorist_listings_loop', 'elementor/core|container', 'elementor/core|heading', 'elementor/core|text-editor' );
		$actual   = array();
		foreach ( $a->components ?? array() as $c ) {
			if ( ! ( $c->insert_attributes_schema ?? null ) instanceof \stdClass || true === ( $c->saved_instance_available ?? null ) || 'new_component_insertion' !== ( $c->authoring_role ?? null ) ) {
				return false; }
			$actual[] = $c->provider_id . '|' . $c->native_id;
		}
		sort( $actual );
		if ( $expected !== $actual ) {
			return false; }
		$ids   = array();
		$types = array();
		foreach ( $recipe->subtree->nodes as $node ) {
			if ( ! is_string( $node->client_id ?? null ) || isset( $ids[ $node->client_id ] ) || ! ( $node->attributes ?? null ) instanceof \stdClass ) {
				return false; }
			$ids[ $node->client_id ] = $node;
			$types[]                 = $node->provider_id . '|' . $node->native_id;
		}
		sort( $types );
		if ( $types !== $expected || ! isset( $ids[ $recipe->subtree->root_client_id ] ) || 'container' !== $ids[ $recipe->subtree->root_client_id ]->native_id || 3 !== count( $recipe->subtree->edges ?? array() ) ) {
			return false; }
		$positions = array();
		foreach ( $recipe->subtree->edges as $edge ) {
			if ( 'contains' !== ( $edge->kind ?? null ) || ( $edge->source_client_id ?? null ) !== $recipe->subtree->root_client_id || ! isset( $ids[ $edge->target_client_id ?? '' ] ) || ! is_int( $edge->order ?? null ) || isset( $positions[ $edge->order ] ) ) {
				return false; }
			$positions[ $edge->order ] = $ids[ $edge->target_client_id ];
		}
		ksort( $positions );
		return array_keys( $positions ) === array( 0, 1, 2 ) && 'directorist_listings_loop' === $positions[2]->native_id && array( 'native_loop_recipe' ) === array_keys( get_object_vars( $positions[2]->attributes ) ) && in_array( $positions[2]->attributes->native_loop_recipe ?? null, array( 'directory_grid', 'directory_list' ), true );
	}

	/**
	 * Exact already-returned public schemas, never standalone saved-instance edits.
	 *
	 * @param object            $entry Expanded trusted read receipt.
	 * @param object            $anchor Exact complete root creation receipt.
	 * @param JsonCanonicalizer $canonical Canonical native value encoder.
	 */
	private static function resolved( object $entry, object $anchor, JsonCanonicalizer $canonical ): bool {
		$a      = $entry->response->data->authoring ?? null;
		$input  = self::input( $entry );
		$parent = $anchor->response->data->authoring->draft->first_loop->parent;
		if ( ! $a instanceof \stdClass || 'needs_narrowing' !== ( $a->state ?? null ) || false !== ( $a->can_propose_private_draft ?? null ) || ! is_string( $entry->call_id ?? null ) || ! is_array( $input->selection ?? null ) || ( $input->selection && $canonical->encode( $input->selection ) !== $canonical->encode( array( $parent ) ) ) || ! is_array( $a->requested_control_paths ?? null ) || ! $a->requested_control_paths || $canonical->encode( $a->requested_control_paths ) !== $canonical->encode( $input->control_paths ) || ! empty( $a->missing_control_paths ) || ! empty( $a->ambiguous_control_paths ) ) {
			return false; }
		$allowed = array( 'directorist_listings_loop' );
		foreach ( $anchor->response->data->authoring->components as $component ) {
			if ( 'directorist_listings_loop' === $component->native_id ) {
				$allowed = array_merge( $allowed, $component->insert_attributes_schema->properties->initial_component_controls->items->properties->native_id->enum ?? array() ); }
		}
		$paths = array();
		foreach ( $a->components ?? array() as $component ) {
			if ( ! in_array( $component->availability ?? null, array( 'available', 'read_only' ), true ) || 'directorist-elementor' !== ( $component->provider_id ?? null ) || ! in_array( $component->native_id ?? null, $allowed, true ) || false !== ( $component->saved_instance_available ?? null ) || 'new_component_insertion' !== ( $component->authoring_role ?? null ) || null !== ( $component->insert_attributes_schema ?? null ) ) {
				return false; }
			foreach ( $component->controls ?? array() as $control ) {
				if ( false !== ( $control->private ?? null ) || ! empty( $control->field_types ) || ! ( $control->value_schema ?? null ) instanceof \stdClass || ! is_string( $control->path ?? null ) ) {
					return false; }
				$paths[] = $control->path;
			}
		}
		sort( $paths );
		$requested = $a->requested_control_paths;
		sort( $requested );
		return $paths === $requested && count( array_unique( $paths ) ) === count( $paths );
	}

	/**
	 * Preserve the four complete rules used by this exact declared starter.
	 *
	 * @param mixed $relationships Full original native relationship contracts.
	 * @return array|null Exact selected rules, or null for an unknown rule shape.
	 */
	private static function starter_relationships( $relationships ): ?array {
		$document  = 'directorist-ai|elementor-document';
		$container = 'elementor/core|container';
		$heading   = 'elementor/core|heading';
		$text      = 'elementor/core|text-editor';
		$loop      = 'directorist-elementor|directorist_listings_loop';
		$all       = array( $document . '>' . $loop, $document . '>' . $container, $document . '>' . $heading, $document . '>' . $text, $loop . '>' . $container, $container . '>' . $loop, $container . '>' . $container, $container . '>' . $heading, $container . '>' . $text );
		$keep      = array( $document . '>' . $container, $container . '>' . $heading, $container . '>' . $text, $container . '>' . $loop );
		if ( ! is_array( $relationships ) || 9 !== count( $relationships ) ) {
			return null; }
		$seen   = array();
		$result = array();
		foreach ( $relationships as $relationship ) {
			$key = ( $relationship->source->provider_id ?? '' ) . '|' . ( $relationship->source->native_id ?? '' ) . '>' . ( $relationship->target->provider_id ?? '' ) . '|' . ( $relationship->target->native_id ?? '' );
			if ( ! in_array( $key, $all, true ) || isset( $seen[ $key ] ) || 'contains' !== ( $relationship->rule->kind ?? null ) || ! is_string( $relationship->rule->id ?? null ) || ! is_string( $relationship->rule->revision ?? null ) ) {
				return null; }
			$seen[ $key ] = true;
			if ( in_array( $key, $keep, true ) ) {
				$result[] = $relationship; }
		}
		return 4 === count( $result ) ? $result : null;
	}

	/**
	 * Decode only the original validated read envelope.
	 *
	 * @param object $entry Expanded trusted Gateway receipt.
	 */
	private static function input( object $entry ): ?object {
		if ( ! is_string( $entry->input_json ?? null ) ) {
			return null; }
		$input = json_decode( $entry->input_json );
		return $input instanceof \stdClass ? $input : null;
	}
}
