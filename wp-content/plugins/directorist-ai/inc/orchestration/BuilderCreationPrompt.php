<?php
/**
 * Compact model descriptions for an explicitly requested new directory layout.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Presentation and narrower tool declarations never replace native validation. */
final class BuilderCreationPrompt {

	public const TEMPLATE_INSTRUCTION = ' Prepare the requested layout in the bound native template using the fresh first_loop contract. This is a template, never an ordinary page or permission to publish or assign it. The exact native parent, insertion position, scope, document, graph/version/catalog/rules and native readiness have already been read. Do not repeat that complete discovery or select a shared document root under a different view. Copy every locator member, including null values, literally. Submit builder.draft_propose with its declared input_json envelope, private_template_draft intent and one insert change with the exact first_loop parent, position and scope. When first_loop supplies a subtree, copy its one-node native marker starter and empty edges. Otherwise create one node with its exact provider_id/native_id, default attributes, one root_client_id and empty edges. An explicit first_loop.attributes_schema may add only its declared optional initial_card_fields for requested public fields. That list replaces the factory default card list, so retain still-requested default fields and include every requested available Price, Address or other field using its exact native identity. Empty default attributes alone do not satisfy a requested field absent from the factory defaults. Never hand-author default descendants or invent attributes on a closed marker. The native adapter supplies its declared default tree and rechecks the complete saved source, relationships, compiler and private review fences. Preserve header, footer, saved profile fields and unrelated content. Archive/category/location/tag/author templates inherit the native route, taxonomy, author and directory query; Home preserves its native global form and runtime directory selection. The editor design directory is not a hard runtime filter. Omit root directory_query for this default insertion and do not set static listing, author, term or directory IDs. Do not turn a semantic/consumer false flag into true: this first-loop insertion is only the separately declared native creation capability and all native server validation still applies. Defaults cover only unspecified details. Retain every supported requested field, introduction, style, responsive and sorting requirement. If the first_loop carrier schema already declares a requested creation value, use its exact typed schema. For genuinely missing controls or composition inspect only the exact needed native identity/path, at most six components per read. A native_id can include its provider namespace; never shorten it. Missing contracts do not grant permission or prove global unavailability. Respect explanation-only, ask-first and all-or-nothing requests; clarify only essential missing facts or unsupported core goals. Changed proposal: fresh request_id. Prose or a proposal proves no Apply, Save, publication or render. The browser may apply an unsaved candidate to the original open template; native Undo and Save remain authoritative.';

	/** Current complete native template starter; never inferred from request text. */
	public static function template_layout_contract( object $entry ): ?object {
		$data = $entry->response->data ?? null;
		$graph = $data->graph ?? null;
		$authoring = $data->authoring ?? null;
		$recipe = $authoring->draft->first_loop ?? null;
		$input = $entry->input ?? json_decode( $entry->input_json ?? 'null' );
		$kinds = array( 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' );
		if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $input instanceof \stdClass || true !== ( $input->include_authoring ?? null ) || null !== ( $input->editor_binding_id ?? null ) || ! property_exists( $input, 'editor_binding_id' ) || ! empty( $input->control_paths ) || ! in_array( $graph->document->builder ?? null, array( 'elementor', 'divi', 'gutenberg' ), true ) || ! in_array( $graph->document->native_kind ?? null, $kinds, true ) || 'complete' !== ( $graph->completeness ?? null ) || ! empty( $graph->omissions ) || 'complete_for_selection' !== ( $authoring->state ?? null ) || true !== ( $authoring->can_propose_private_draft ?? null ) || true !== ( $authoring->requires_full_server_validation ?? null ) || true !== ( $authoring->draft_readiness->ready ?? null ) || 'installed_adapter' !== ( $authoring->candidate_relationship_completion ?? null ) || ! $recipe instanceof \stdClass || ! ( $recipe->parent ?? null ) instanceof \stdClass || ! ( $recipe->scope ?? null ) instanceof \stdClass || ! is_int( $recipe->position ?? null ) || $recipe->position < 0 || ! \DirectoristAIPro\Builder\BuilderAuthoringContract::has_actionable_contract( $authoring ) ) {
			return null;
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		if ( $canonical->encode( $input->document ?? null ) !== $canonical->encode( $graph->document ) || $canonical->encode( $input->scope ?? null ) !== $canonical->encode( $recipe->scope ) || (string) ( $input->directory_id ?? '' ) !== ( $recipe->scope->directory_id ?? null ) || $canonical->encode( $recipe->parent->document ?? null ) !== $canonical->encode( $graph->document ) || ! is_array( $input->selection ?? null ) || ( $input->selection && $canonical->encode( $input->selection ) !== $canonical->encode( array( $recipe->parent ) ) ) ) {
			return null;
		}
		$parents = array_values( array_filter( $graph->nodes ?? array(), static fn( object $node ): bool => $canonical->encode( $node->locator ?? null ) === $canonical->encode( $recipe->parent ) ) );
		if ( 1 !== count( $parents ) ) {
			return null;
		}
		foreach ( array( $data->graph_hash ?? null, $data->opaque_hash ?? null, $data->fact_revision ?? null, $authoring->catalog_revision ?? null, $authoring->semantic_rule_set_revision ?? null ) as $hash ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				return null;
			}
		}
		try {
			$version_object = new \DirectoristAIPro\Builder\GraphVersion( (array) ( $graph->version ?? array() ) );
			if ( $version_object->editor_bound() ) {
				return null;
			}
			$version = $version_object->hash();
			if ( $canonical->digest( $graph ) !== $data->graph_hash || $version !== ( $data->semantic_report->graph_version_hash ?? null ) || $data->fact_revision !== ( $data->semantic_report->fact_revision ?? null ) || $authoring->semantic_rule_set_revision !== ( $data->semantic_report->rule_set_revision ?? null ) ) {
				return null;
			}
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			return null;
		}
		$builder = $graph->document->builder;
		$home = 'template:home_search' === $graph->document->native_kind;
		if ( 'elementor' === $builder ) {
			$identity = (object) array( 'provider_id' => 'directorist-elementor', 'native_id' => $home ? 'directorist_homepage_search_loop' : 'directorist_listings_loop' );
			$attributes_schema = $recipe->attributes_schema ?? null;
			$fields_schema = $attributes_schema->properties->initial_card_fields ?? null;
			if ( ! $attributes_schema instanceof \stdClass || 'object' !== ( $attributes_schema->type ?? null ) || false !== ( $attributes_schema->additionalProperties ?? null ) || ! ( $attributes_schema->properties ?? null ) instanceof \stdClass || array( 'initial_card_fields' ) !== array_keys( get_object_vars( $attributes_schema->properties ) ) || 'array' !== ( $fields_schema->type ?? null ) || 1 !== ( $fields_schema->minItems ?? null ) || ( ! is_int( $fields_schema->maxItems ?? null ) || $fields_schema->maxItems < 1 || $fields_schema->maxItems > 8 ) || true !== ( $fields_schema->uniqueItems ?? null ) || 'string' !== ( $fields_schema->items->type ?? null ) || ! is_array( $fields_schema->items->enum ?? null ) || ! $fields_schema->items->enum || count( $fields_schema->items->enum ) > 8 ) {
				return null;
			}
			$coverage = true === ( $data->semantic_report->accepted ?? null ) || ( 'partial' === ( $graph->consumer_coverage ?? null ) && ! empty( $data->semantic_report->diagnostics ) && ! array_filter( $data->semantic_report->diagnostics, static fn( object $item ): bool => 'consumer_coverage_incomplete' !== ( $item->code ?? null ) ) );
			if ( ! $coverage || $input->selection || 'insert' !== ( $recipe->operation ?? null ) || 'elementor_first_directory_loop_copy' !== ( $recipe->effect_scope ?? null ) || 1 !== ( $recipe->max_insert_nodes ?? null ) || ( $recipe->provider_id ?? null ) !== $identity->provider_id || ( $recipe->native_id ?? null ) !== $identity->native_id || ! ( $recipe->attributes ?? null ) instanceof \stdClass || get_object_vars( $recipe->attributes ) || 'directorist-ai' !== ( $parents[0]->provider_id ?? null ) || 'elementor-document' !== ( $parents[0]->native_id ?? null ) || null !== ( $parents[0]->scope->view ?? null ) || ( $parents[0]->scope->directory_id ?? null ) !== $recipe->scope->directory_id || ! in_array( $recipe->scope->view ?? null, array( 'grid', 'list' ), true ) ) {
				return null;
			}
		} else {
			$nodes = $recipe->subtree->nodes ?? null;
			$root = is_array( $nodes ) && 1 === count( $nodes ) ? $nodes[0] : null;
			$identity = (object) array( 'provider_id' => 'divi' === $builder ? 'divi/core' : 'wordpress/core', 'native_id' => 'divi' === $builder ? 'divi/section' : 'core/group' );
			$marker = 'divi' === $builder ? ( $home ? 'home_search_default' : 'archive_default' ) : ( $home ? 'template_home_search_default' : ( 'template:author_listing' === $graph->document->native_kind ? 'template_author_default' : 'template_archive_default' ) );
			if ( true !== ( $data->semantic_report->accepted ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || 1 !== ( $recipe->max_changes ?? null ) || 1 !== ( $recipe->max_insert_nodes ?? null ) || ! $root instanceof \stdClass || ( $recipe->subtree->root_client_id ?? null ) !== ( $root->client_id ?? null ) || ( $root->provider_id ?? null ) !== $identity->provider_id || ( $root->native_id ?? null ) !== $identity->native_id || ! ( $root->attributes ?? null ) instanceof \stdClass || array( 'native_loop_recipe' ) !== array_keys( get_object_vars( $root->attributes ) ) || $marker !== ( $root->attributes->native_loop_recipe ?? null ) || array() !== ( $recipe->subtree->edges ?? null ) || ! ( $recipe->carrier_attributes_schema ?? null ) instanceof \stdClass || 'object' !== ( $recipe->carrier_attributes_schema->type ?? null ) || false !== ( $recipe->carrier_attributes_schema->additionalProperties ?? null ) || $canonical->encode( $parents[0]->scope ?? null ) !== $canonical->encode( $recipe->scope ) ) {
				return null;
			}
			$declared = $recipe->carrier_attributes_schema->properties->native_loop_recipe ?? null;
			if ( $marker !== ( $declared->const ?? null ) && ! in_array( $marker, $declared->enum ?? array(), true ) ) {
				return null;
			}
			if ( 'divi' === $builder && count( array_filter( $graph->edges ?? array(), static fn( object $edge ): bool => 'contains' === ( $edge->kind ?? null ) && ( $edge->source ?? null ) === $parents[0]->id ) ) !== $recipe->position ) {
				return null;
			}
		}
		if ( $canonical->encode( $input->authoring_components ?? null ) !== $canonical->encode( array( $identity ) ) ) {
			return null;
		}
		return $recipe;
	}

	/** Compact only a proven native template insertion, retaining every native false flag. */
	public static function template_layout_view( object $entry ): ?object {
		$recipe = self::template_layout_contract( $entry );
		if ( null === $recipe ) {
			return null;
		}
		$data = $entry->response->data;
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$parents = array_values( array_filter( $data->graph->nodes, static fn( object $node ): bool => $canonical->encode( $node->locator ) === $canonical->encode( $recipe->parent ) ) );
		$parent = clone $parents[0];
		unset( $parent->attributes );
		$view = clone $entry;
		$view->response = clone $entry->response;
		$view->response->data = clone $data;
		$view->response->data->graph = clone $data->graph;
		$view->response->data->graph->nodes = array( $parent );
		$view->response->data->graph->edges = array();
		$view->response->data->graph->prompt_view = 'native_template_first_loop_parent';
		unset( $view->response->data->graph->slot_coverage, $view->response->data->context_trace, $view->response->data->directory_query, $view->response->data->directory_binding );
		$view->response->data->authoring = (object) array_intersect_key( get_object_vars( $data->authoring ), array_fill_keys( array( 'state', 'readiness_scope', 'catalog_revision', 'semantic_rule_set_revision', 'candidate_relationship_completion', 'diagnostics', 'unavailable_requested_components', 'requires_full_server_validation', 'draft_readiness', 'can_propose_private_draft' ), true ) );
		$view->response->data->authoring->draft = (object) array( 'revision' => $data->authoring->draft->revision, 'max_changes' => 1, 'operations' => array( 'insert' ), 'first_loop' => $recipe );
		$view->response->data->context_note = 'Exact current native template first-loop insertion only. Saved descendant values and unrelated control contracts are omitted from this model view, never defaulted or changed. Native semantic/consumer/readiness flags remain exactly as observed; an explicitly supported new Elementor view may have incomplete saved-view consumers while its separate native insertion proof is ready. Copy only the declared starter/parent/position/scope, preserving all saved content and inherited route/query context. Full source receipts and every native planning, source, compiler, relationship, permission and review fence remain authoritative. Inspect exact saved controls separately for scalar edits. No Apply, Save, publication, assignment or additional authority is granted.';
		return $view;
	}

	public const INSTRUCTION = ' Read fresh graph/include_authoring before creation. Every builder.inspect includes document, directory_id, scope, selection, editor_binding_id (null when unbound), include_authoring, plus only needed authoring_components/control_paths. Copy bound identities exactly. When carrier_control_index is supplied, copy its complete provider_id/native_id pair and exact paths into one-component filtered reads. A native_id may repeat its provider namespace; never shorten it. These are generated factory targets, not saved instances. Do not request broad child insertion schemas to style factory children. Keep the full supported goal: defaults never replace requested intro, fields, styles, responsiveness or ordering. Before proposing, check every explicit requested field, numeric limit, sort direction and style against the actual native values in that proposal. Include supported requested overrides now; only unrequested extras belong to later refinements. Inspect missing exact components/controls only (max6/batch); follow narrowing groups and halve oversized batches, never repeat unchanged failures. Unrequested is not unavailable. Combine reads only under identical source/catalog/rules revisions. Use complete declared creation value schemas directly. Inspect only missing schemas or grouped defaults required by the native creation envelope. A fully declared new scalar initializer needs no saved-target read. Preserve complete grouped defaults when required; use sparse groups only when explicitly declared. Never invent CSS, attributes, fields, bindings or listing facts. Preserve defaults, inheritance, unrelated settings and saved source. Copy exact parent/scope/append position, wrappers, child order and every locator member (including null IDs/editor_epoch and snapshot_hash); component_native_id is a type, not an instance. Each change needs its exact scope; stale_version needs reread. One insert has one root_client_id, unique nodes, edges only among inserted nodes; planner attaches root. installed_adapter plans emit contains edges only; other plans follow declared relationships. Honor native wrappers/limits: max_insert_nodes1 first_loop uses its exact marker. Only when carrier_attributes_schema is supplied may that marker include its declared optional creation envelopes. Otherwise custom designs need supported expanded trees. Root directory_query is supported only for an ordinary page or a Gutenberg Home template with current signed native query authority. For other templates, including Divi and Elementor Home, taxonomy and author templates, omit directory_query and preserve native route/query defaults. The general optional proposal schema does not grant that document query authority. Where that native authority is available, shared directories use one loop plus complete root directory_query (directory_ids/default_directory_id/active_directory_id/view/show_switcher); retain requested start, design scopes and runtime switching. If an unsupported query override is essential, explain the limitation without relabeling the destination or silently dropping the goal. Repairs cannot weaken goal/scope. Submit one insertion via builder.draft_propose using its declared direct structured object properties. Do not send input_json, stringify the proposal or nest an envelope. Copy every required native proposal member and each change scope. Other tools retain their own declared input_json envelopes. Changed proposal: new request_id; identical retry: same ID. Ask only for essential facts, real ambiguity or unsupported core goals. Follow bound current-editor destination below. Proposals/prose prove no application, Save, publication, assignment or visual rendering.';

	/**
	 * Narrow the declared proposal syntax to one insertion, retaining its constraints.
	 *
	 * The registered gateway schema is unchanged. Existing edits keep their original
	 * declaration. This is used only by a server-selected initial layout round.
	 *
	 * @param array  $envelope Native ability argument envelope.
	 * @param string $operation Exact model-selected operation restriction.
	 * @return array A detached narrower description, or the original unknown format.
	 */
	public static function declaration( array $envelope, string $operation = 'insert' ): array {
		if ( ! in_array( $operation, array( 'insert', 'configure_saved_variants', 'set_control' ), true ) ) {
			return $envelope; }
		$prefix = 'JSON encoded operation input. Authoritative schema: ';
		$text   = $envelope['properties']['input_json']['description'] ?? null;
		if ( ! is_string( $text ) || ! str_starts_with( $text, $prefix ) ) {
			return $envelope;
		}
		$schema   = json_decode( substr( $text, strlen( $prefix ) ) );
		$branches = $schema->properties->changes->items->oneOf ?? null;
		if ( ! $schema instanceof \stdClass || ! is_array( $branches ) ) {
			return $envelope;
		}
		$insert = array_values( array_filter( $branches, static fn( object $branch ): bool => ( $branch->properties->op->const ?? null ) === $operation ) );
		if ( 1 !== count( $insert ) ) {
			return $envelope;
		}
		$schema->properties->changes->items->oneOf = $insert;
		if ( 'set_control' !== $operation ) {
			$schema->properties->changes->maxItems = 1;
		} elseif ( ! in_array( 'directory_query', $schema->required ?? array(), true ) ) {
			// Style-only declarations cannot ask for an unrelated query change.
			unset( $schema->properties->directory_query );
		}
		$definitions                               = get_object_vars( $schema->definitions ?? new \stdClass() );
		unset( $schema->definitions );
		$needed = array();
		$valid  = true;
		$walk   = static function ( $node ) use ( &$walk, &$needed, &$valid, $definitions ): void {
			if ( is_array( $node ) ) {
				foreach ( $node as $value ) {
					$walk( $value ); }
				return;
			}
			if ( ! $node instanceof \stdClass ) {
				return; }
			if ( property_exists( $node, '$ref' ) ) {
				$reference = $node->{'$ref'};
				if ( ! is_string( $reference ) || ! preg_match( '~^#/definitions/([A-Za-z0-9_-]+)$~D', $reference, $match ) || ! isset( $definitions[ $match[1] ] ) ) {
					$valid = false;
					return; }
				if ( ! isset( $needed[ $match[1] ] ) ) {
					$needed[ $match[1] ] = true;
					$walk( $definitions[ $match[1] ] );
				}
			}
			foreach ( get_object_vars( $node ) as $value ) {
				$walk( $value ); }
		};
		$walk( $schema );
		$walk = null;
		if ( ! $valid ) {
			return $envelope; }
		if ( $needed ) {
			$schema->definitions = (object) array_intersect_key( $definitions, $needed ); }
		$envelope['properties']['input_json']['description'] = $prefix . wp_json_encode( $schema );
		return $envelope;
	}

	/**
	 * Supply identical draft metadata once across complementary current reads.
	 *
	 * @param array $entries Model-only views with full source revision identities.
	 * @return array Detached views with backward references to exact literal fields.
	 */
	public static function draft_fields( array $entries ): array {
		( new \DirectoristAIPro\Validation\JsonDecoder( 2097152, 48 ) )->check( $entries );
		$creation = false;
		foreach ( $entries as $entry ) {
			foreach ( $entry->response->data->authoring->components ?? array() as $component ) {
				if ( 'new_component_insertion' === ( $component->authoring_role ?? null ) ) {
					$creation = true; }
			}
		}
		if ( ! $creation ) {
			return $entries; }
		$copy  = json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		$known = array();
		foreach ( $copy as $index => $entry ) {
			$data      = $entry->response->data ?? null;
			$authoring = $data->authoring ?? null;
			if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! ( $authoring->draft ?? null ) instanceof \stdClass || ! is_string( $data->document_key ?? null ) ) {
				continue; }
			$revisions = array( $data->graph_hash ?? null, $data->fact_revision ?? null, $authoring->catalog_revision ?? null, $authoring->semantic_rule_set_revision ?? null );
			if ( array_filter( $revisions, static fn( $value ): bool => ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) ) {
				continue; }
			$key = $data->document_key . '|' . implode( '|', $revisions );
			foreach ( get_object_vars( $authoring->draft ) as $field => $value ) {
				$encoded = wp_json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
				if ( strlen( $encoded ) < 192 ) {
					continue; }
				$hash = hash( 'sha256', $encoded );
				if ( isset( $known[ $key ][ $field ][ $hash ] ) ) {
					$reference = $known[ $key ][ $field ][ $hash ];
					if ( $reference->bytes !== $encoded ) {
						continue; }
					$authoring->draft_field_references           = $authoring->draft_field_references ?? new \stdClass();
					$authoring->draft_field_references->{$field} = (object) array(
						'read_index' => $reference->read_index,
						'call_id'    => $reference->call_id,
						'field'      => $field,
						'sha256'     => $hash,
					);
					unset( $authoring->draft->{$field} );
				} else {
					$known[ $key ][ $field ][ $hash ] = (object) array(
						'read_index' => $index,
						'call_id'    => $entry->call_id ?? null,
						'bytes'      => $encoded,
					);
				}
			}
			if ( isset( $authoring->draft_field_references ) ) {
				$data->context_note = ( $data->context_note ?? '' ) . ' draft_field_references supplies identical draft fields from the listed preceding read_index/call_id and field, under these exact source/facts/catalog/rules revisions. Their literal values and hashes are unchanged. Retain those referenced constraints; these model-only descriptions grant no authority.';
			}
		}
		return $copy;
	}

	/**
	 * Keep source identities while omitting saved values unused by initial inserts.
	 *
	 * @param array $entries Model-only views for a server-selected creation round.
	 * @return array Detached source indexes with exact locators and schemas retained.
	 */
	public static function insertion_views( array $entries ): array {
		$copy   = json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		$graphs = array();
		foreach ( $copy as $index => $entry ) {
			$data = $entry->response->data ?? null;
			if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) ) {
				continue; }
			if ( isset( $data->graph->nodes ) ) {
				$revisions = array( $data->graph_hash ?? null, $data->fact_revision ?? null, $data->authoring->catalog_revision ?? null, $data->authoring->semantic_rule_set_revision ?? null );
				if ( is_string( $data->document_key ?? null ) && ! array_filter( $revisions, static fn( $value ): bool => ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) ) {
					$encoded = wp_json_encode( $data->graph, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
					$key     = $data->document_key . '|' . implode( '|', $revisions ) . '|' . hash( 'sha256', $encoded );
					if ( isset( $graphs[ $key ] ) && $graphs[ $key ]['bytes'] === $encoded ) {
						$data->graph_reference = $graphs[ $key ]['reference'];
						unset( $data->graph );
					} else {
						$graphs[ $key ] = array(
							'bytes'     => $encoded,
							'reference' => (object) array(
								'read_index'    => $index,
								'call_id'       => $entry->call_id ?? null,
								'document_key'  => $data->document_key,
								'graph_hash'    => $data->graph_hash,
								'fact_revision' => $data->fact_revision,
								'sha256'        => hash( 'sha256', $encoded ),
							),
						);
					}
				}
			}
			if ( isset( $data->graph->nodes ) ) {
				foreach ( $data->graph->nodes as $node ) {
					unset( $node->attributes ); }
				$data->context_note = ( $data->context_note ?? '' ) . ' Initial-insertion source index: saved values are omitted because this round creates a new tree. Every current source locator, scope, relationship and revision remains; preserve existing content. Existing-value edits require a fresh ordinary inspection. New-node schemas and initial_control_values remain exact.';
			}
			$authoring = $data->authoring ?? null;
			if ( ( $authoring->draft ?? null ) instanceof \stdClass ) {
				// This round declares insert only. Saved-control edits need an ordinary read.
				foreach ( array( 'set_control_batch', 'set_control_document_kinds', 'text_controls', 'selected_control_targets', 'selected_control_rule' ) as $field ) {
					unset( $authoring->draft->{$field} );
				}
			}
			$exact_controls = self::resolved_controls( $authoring );
			if ( ( 'complete_for_selection' === ( $authoring->state ?? null ) || $exact_controls ) && ! empty( $authoring->requested_control_paths ) && empty( $authoring->missing_control_paths ) && empty( $authoring->ambiguous_control_paths ) ) {
				unset( $authoring->control_index, $authoring->control_index_complete );
				$authoring->other_controls_require_inspection = true;
			}
		}
		$notes = array();
		foreach ( $copy as $index => $entry ) {
			$data = $entry->response->data ?? null;
			if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! is_string( $data->context_note ?? null ) || strlen( $data->context_note ) < 192 ) {
				continue;
			}
			$key = wp_json_encode( array( $data->document_key ?? null, $data->graph_hash ?? null, $data->fact_revision ?? null, $data->authoring->catalog_revision ?? null, $data->authoring->semantic_rule_set_revision ?? null, $data->context_note ) );
			if ( isset( $notes[ $key ] ) ) {
				$data->context_note_reference = $notes[ $key ];
				unset( $data->context_note );
			} else {
				$notes[ $key ] = (object) array(
					'read_index' => $index,
					'call_id'    => $entry->call_id ?? null,
					'field'      => 'context_note',
					'sha256'     => hash( 'sha256', $data->context_note ),
				);
			}
		}
		return $copy;
	}

	/**
	 * A single-template insertion needs no search-page recipes or saved edit values.
	 *
	 * @param array $entries Exact current model-only native inspection views.
	 * @return array Detached views retaining all insertion/control schemas and authority.
	 */
	public static function single_template_views( array $entries ): array {
		foreach ( $entries as $entry ) {
			$input = $entry->input ?? json_decode( $entry->input_json ?? 'null' );
			if ( true === ( $entry->response->ok ?? null ) && 'builder.inspect' === ( $entry->response->operation ?? null ) && 'template:single_listing' !== ( $input->document->native_kind ?? null ) ) {
				return $entries;
			}
		}
		$copy           = self::insertion_views( $entries );
		$note_reference = null;
		foreach ( $copy as $entry ) {
			$input = $entry->input ?? json_decode( $entry->input_json ?? 'null' );
			$draft = $entry->response->data->authoring->draft ?? null;
			if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || 'template:single_listing' !== ( $input->document->native_kind ?? null ) ) {
				continue;
			}
			if ( is_string( $entry->call_id ?? null ) ) {
				if ( null === $note_reference ) {
					$entry->response->data->context_note = 'New single-template subtree only. Exact locators, scopes, schemas and revisions remain; saved values are omitted and existing content must stay unchanged. Full native receipts remain server-side. initial_control_supplement supplies typed NEW-node UI command schemas under the same source: its native false readiness grants no proposal authority. Combine it with the complete insertion contracts. Saved edits require their own inspection; native validation, Undo, Save and assignment guards remain.';
					$note_reference                      = (object) array( 'call_id' => $entry->call_id );
				} else {
					unset( $entry->response->data->context_note );
					$entry->response->data->context_note_reference = $note_reference;
				}
			}
			if ( ! $draft instanceof \stdClass ) {
				continue; }
			foreach ( array( 'new_layout_presentation', 'listing_layout_views', 'listing_grid_recipe', 'search_recipe', 'home_search_card_recipe', 'home_search_canvas_apply', 'new_loop_responsive_rule', 'card_wrapper_style_rule' ) as $field ) {
				unset( $draft->{$field} );
			}
		}
		return $copy;
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Exact JSON Schema keywords.
	/**
	 * Resolve only declared target identities in trusted closed carrier schemas.
	 *
	 * @param object $schema Exact public native carrier schema.
	 * @return array Declared identities, or none when the shape is unsupported.
	 */
	private static function carrier_targets( object $schema ): array {
		if ( 'object' !== ( $schema->type ?? null ) || false !== ( $schema->additionalProperties ?? null ) || true === ( $schema->readOnly ?? false ) || array_intersect( array_keys( get_object_vars( $schema ) ), array( '$ref', '$id', 'anyOf', 'oneOf', 'not' ) ) ) {
			return array(); }
		$resolve = static function ( object $node ) use ( $schema ): ?object {
			$seen = array();
			for ( $depth = 0; isset( $node->{'$ref'} ) && $depth < 16; ++$depth ) {
				$ref = $node->{'$ref'};
				if ( count( get_object_vars( $node ) ) !== 1 || ! is_string( $ref ) || ! preg_match( '~^#/definitions/([A-Za-z0-9_-]+)$~D', $ref, $match ) || isset( $seen[ $ref ] ) || ! ( $schema->definitions->{$match[1]} ?? null ) instanceof \stdClass ) {
					return null;
				}
				$seen[ $ref ] = true;
				$node         = $schema->definitions->{$match[1]};
			}
			return isset( $node->{'$ref'} ) ? null : $node;
		};
		$array   = $schema->properties->initial_component_controls ?? null;
		$array   = $array instanceof \stdClass ? $resolve( $array ) : null;
		$item    = ( $array->items ?? null ) instanceof \stdClass ? $resolve( $array->items ) : null;
		if ( null === $array || 'array' !== ( $array->type ?? null ) || true === ( $array->readOnly ?? false ) || isset( $array->{'$id'} ) || null === $item || true === ( $item->readOnly ?? false ) || isset( $item->{'$id'} ) ) {
			return array();
		}
		if ( array_intersect( array_keys( get_object_vars( $array ) ), array( 'allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else', 'contains' ) ) || array_intersect( array_keys( get_object_vars( $item ) ), array( 'allOf', 'anyOf', 'not', 'if', 'then', 'else' ) ) || ( isset( $item->oneOf ) && array_diff( array_keys( get_object_vars( $item ) ), array( 'oneOf', 'description', 'title', 'default', 'examples', 'readOnly', '$comment' ) ) ) ) {
			return array(); }
		$branches = $item->oneOf ?? array( $item );
		if ( ! is_array( $branches ) || ! $branches || count( $branches ) > 32 ) {
			return array();
		}
		$targets = array();
		foreach ( $branches as $branch ) {
			$branch = $branch instanceof \stdClass ? $resolve( $branch ) : null;
			if ( null === $branch || 'object' !== ( $branch->type ?? null ) || false !== ( $branch->additionalProperties ?? null ) || true === ( $branch->readOnly ?? false ) || ! in_array( 'native_id', $branch->required ?? array(), true ) || ! in_array( 'controls', $branch->required ?? array(), true ) ) {
				return array();
			}
			$name_schema = $branch->properties->native_id ?? null;
			if ( ! $name_schema instanceof \stdClass || array_intersect( array_keys( get_object_vars( $name_schema ) ), array( '$ref', '$id', 'allOf', 'anyOf', 'oneOf', 'not' ) ) || isset( $branch->{'$id'} ) || isset( $branch->allOf ) || isset( $branch->anyOf ) || isset( $branch->oneOf ) ) {
				return array(); }
			$controls = ( $branch->properties->controls ?? null ) instanceof \stdClass ? $resolve( $branch->properties->controls ) : null;
			if ( null === $controls || 'array' !== ( $controls->type ?? null ) || true === ( $controls->readOnly ?? false ) || isset( $controls->{'$id'} ) || array_intersect( array_keys( get_object_vars( $controls ) ), array( 'allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else', 'contains' ) ) || ( isset( $name_schema->type ) && 'string' !== $name_schema->type ) || ( isset( $name_schema->enum ) && isset( $name_schema->const ) ) ) {
				return array(); }
			$names = $branch->properties->native_id->enum ?? ( isset( $branch->properties->native_id->const ) ? array( $branch->properties->native_id->const ) : array() );
			if ( ! is_array( $names ) || ! $names || count( $names ) > 32 ) {
				return array();
			}
			foreach ( $names as $name ) {
				if ( ! is_string( $name ) || ! preg_match( '~^[A-Za-z0-9_-]+/[A-Za-z0-9_/-]{1,190}$~D', $name ) || isset( $targets[ $name ] ) ) {
					return array();
				}
				$targets[ $name ] = true;
			}
		}
		return self::carrier_narrowing_rules( $schema, array_keys( $targets ) ) ? array_keys( $targets ) : array();
	}


	/**
	 * Recognize validation-only generated-target conditions without erasing them.
	 *
	 * @param object $schema Complete trusted closed native carrier schema.
	 * @param array  $targets Base discovery union, never write authority.
	 * @return bool Whether every added condition is a supported narrowing.
	 */
	private static function carrier_narrowing_rules( object $schema, array $targets ): bool {
		if ( ! property_exists( $schema, 'allOf' ) ) {
			return true;
		}
		if ( ! is_array( $schema->allOf ) || ! array_is_list( $schema->allOf ) || ! $schema->allOf || count( $schema->allOf ) > 32 ) {
			return false;
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		foreach ( $schema->allOf as $rule ) {
			if ( $rule instanceof \stdClass && self::composition_intro_condition( $schema, $rule ) ) {
				continue;
			}
			if ( ! $rule instanceof \stdClass || count( get_object_vars( $rule ) ) !== 2 || ! isset( $rule->if, $rule->then ) || ! self::carrier_predicate( $rule->if, 0 ) || ! $rule->then instanceof \stdClass ) {
				return false;
			}
			$forbid = (object) array( 'not' => (object) array( 'required' => array( 'initial_card_fields' ) ) );
			if ( $canonical->digest( $forbid ) === $canonical->digest( $rule->then ) ) {
				continue;
			}
			$enum = $rule->then->properties->initial_component_controls->items->properties->native_id->enum ?? null;
			if ( ! is_array( $enum ) || ! array_is_list( $enum ) || ! $enum || count( $enum ) > 128 || count( $enum ) !== count( array_unique( $enum, SORT_REGULAR ) ) ) {
				return false;
			}
			foreach ( $enum as $name ) {
				if ( ! is_string( $name ) || ! in_array( $name, $targets, true ) ) {
					return false;
				}
			}
			$expected = (object) array( 'properties' => (object) array( 'initial_component_controls' => (object) array( 'items' => (object) array( 'properties' => (object) array( 'native_id' => (object) array( 'enum' => $enum ) ) ) ) ) );
			if ( $canonical->digest( $expected ) !== $canonical->digest( $rule->then ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Recognize only the declared optional intro's presence constraint.
	 *
	 * @param object $schema Declared native initializer schema.
	 * @param object $rule Candidate conditional validation rule.
	 * @return bool Whether the rule exactly matches the optional intro constraint.
	 */
	private static function composition_intro_condition( object $schema, object $rule ): bool {
		$composition = $schema->properties->initial_page_composition ?? null;
		if ( ! ( $schema->properties->initial_intro ?? null ) instanceof \stdClass || ! $composition instanceof \stdClass || 'object' !== ( $composition->type ?? null ) || 'array' !== ( $composition->properties->nodes->type ?? null ) ) {
			return false;
		}
		$variants = $composition->properties->nodes->items->oneOf ?? null;
		if ( ! is_array( $variants ) || ! array_is_list( $variants ) || count( $variants ) > 32 ) {
			return false;
		}
		$declared = array_filter( $variants, static fn( $variant ): bool => $variant instanceof \stdClass && 'intro' === ( $variant->properties->reference->const ?? null ) );
		if ( ! $declared ) {
			return false;
		}
		$intro     = (object) array(
			'contains' => (object) array(
				'required'   => array( 'reference' ),
				'properties' => (object) array( 'reference' => (object) array( 'const' => 'intro' ) ),
			),
		);
		$expected  = (object) array(
			'if'   => (object) array( 'required' => array( 'initial_intro' ) ),
			'then' => (object) array( 'properties' => (object) array( 'initial_page_composition' => (object) array( 'properties' => (object) array( 'nodes' => $intro ) ) ) ),
			'else' => (object) array( 'properties' => (object) array( 'initial_page_composition' => (object) array( 'properties' => (object) array( 'nodes' => (object) array( 'not' => $intro ) ) ) ) ),
		);
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		return $canonical->digest( $expected ) === $canonical->digest( $rule );
	}

	/**
	 * Admit only pure bounded condition predicates, never schema authority flags.
	 *
	 * @param mixed $node Native schema condition.
	 * @param int   $depth Current predicate nesting.
	 * @return bool Whether the condition has the supported validation grammar.
	 */
	private static function carrier_predicate( $node, int $depth ): bool {
		if ( ! $node instanceof \stdClass || $depth > 12 || ! get_object_vars( $node ) ) {
			return false;
		}
		foreach ( get_object_vars( $node ) as $key => $value ) {
			if ( 'properties' === $key && $value instanceof \stdClass ) {
				foreach ( get_object_vars( $value ) as $name => $child ) {
					if ( ! in_array( $name, array( 'native_loop_recipe', 'initial_intro', 'initial_card_fields' ), true ) || ! self::carrier_predicate( $child, $depth + 1 ) ) {
						return false;
					}
				}
			} elseif ( 'required' === $key && is_array( $value ) && array_is_list( $value ) && $value ) {
				foreach ( $value as $name ) {
					if ( ! in_array( $name, array( 'native_loop_recipe', 'initial_intro', 'initial_card_fields' ), true ) ) {
						return false;
					}
				}
			} elseif ( 'allOf' === $key && is_array( $value ) && array_is_list( $value ) && $value && count( $value ) <= 32 ) {
				foreach ( $value as $child ) {
					if ( ! self::carrier_predicate( $child, $depth + 1 ) ) {
						return false;
					}
				}
			} elseif ( in_array( $key, array( 'not', 'contains' ), true ) ) {
				if ( ! self::carrier_predicate( $value, $depth + 1 ) ) {
					return false;
				}
			} elseif ( 'const' !== $key || ! is_string( $value ) || strlen( $value ) > 256 ) {
				return false;
			}
		}
		return true;
	}

	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Reference only byte-identical factory guidance; retain every component schema.
	 *
	 * @param array $entries Current trusted builder receipts.
	 * @return array Detached model views, or original receipts when sharing is unsafe.
	 */
	private static function duplicate_factory_guidance( array $entries ): array {
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$groups    = array();
		$calls     = array();
		foreach ( $entries as $index => $entry ) {
			if ( ! is_string( $entry->call_id ?? null ) || isset( $calls[ $entry->call_id ] ) ) {
				return $entries;
			}
			$calls[ $entry->call_id ] = true;
			$data                     = $entry->response->data ?? null;
			$graph                    = $data->graph ?? null;
			$a                        = $data->authoring ?? null;
			$recipe                   = $a->draft->first_loop ?? null;
			$input                    = json_decode( $entry->input_json ?? 'null' );
			if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $a instanceof \stdClass ) {
				continue; }
			if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $input instanceof \stdClass || true !== ( $input->include_authoring ?? null ) || ! property_exists( $input, 'editor_binding_id' ) || null !== $input->editor_binding_id || ! in_array( $graph->document->builder ?? null, array( 'gutenberg', 'divi' ), true ) || 'page' !== ( $graph->document->native_kind ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || true !== ( $data->semantic_report->accepted ?? null ) || null !== ( $graph->version->editor_epoch ?? null ) || $canonical->encode( $input->document ?? null ) !== $canonical->encode( $graph->document ) || ! is_string( $data->document_key ?? null ) || ! ( $recipe->carrier_attributes_schema ?? null ) instanceof \stdClass || 1 !== ( $recipe->max_changes ?? null ) || 1 !== ( $recipe->max_insert_nodes ?? null ) || ! is_int( $recipe->position ?? null ) || $recipe->position < 0 || count( $recipe->subtree->nodes ?? array() ) !== 1 || array() !== ( $recipe->subtree->edges ?? null ) || $canonical->encode( $recipe->scope ?? null ) !== $canonical->encode( $input->scope ?? null ) || ! is_array( $input->selection ?? null ) || ( $input->selection && $canonical->encode( $input->selection ) !== $canonical->encode( array( $recipe->parent ) ) ) ) {
				return $entries;
			}
			$parents = array_values( array_filter( $graph->nodes ?? array(), static fn( $node ): bool => 'directorist-ai' === ( $node->provider_id ?? null ) && ( $graph->document->builder . '-document' ) === ( $node->native_id ?? null ) && null === ( $node->opaque_ref ?? null ) && array( 'content' ) === ( $node->locator->slot_path ?? null ) && $canonical->encode( $node->locator ?? null ) === $canonical->encode( $recipe->parent ?? null ) && $canonical->encode( $node->scope ?? null ) === $canonical->encode( $recipe->scope ?? null ) ) );
			if ( count( $parents ) !== 1 ) {
				return $entries;
			}
			$children = array_filter( $graph->edges ?? array(), static fn( $edge ): bool => 'contains' === ( $edge->kind ?? null ) && ( $edge->source ?? null ) === $parents[0]->id && null === ( $edge->target_document_key ?? null ) );
			if ( count( $children ) !== $recipe->position ) {
				return $entries;
			}
			$hashes = array( $data->graph_hash ?? null, $data->fact_revision ?? null, $a->catalog_revision ?? null, $a->semantic_rule_set_revision ?? null, $graph->version->source_hash ?? null );
			if ( array_filter( $hashes, static fn( $hash ): bool => ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) ) {
				return $entries;
			}
			$identity                        = $canonical->encode( array( $data->document_key, $graph, $hashes, $input->document, $input->directory_id ?? null, $input->scope, $input->editor_binding_id, $data->environment_hash ?? null, $data->owner_ref ?? null, $entry->owner_ref ?? null, $data->site_id ?? null, $entry->run_id ?? null, $entry->conversation_id ?? null ) );
			$bytes                           = wp_json_encode( $recipe, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
			$groups[ $identity ][ $bytes ][] = $index;
		}
		if ( count( $groups ) !== 1 ) {
			return $entries; }
		$copy = null;
		foreach ( $groups as $recipes ) {
			foreach ( $recipes as $bytes => $indices ) {
				if ( count( $indices ) < 2 ) {
					continue;
				}
				$anchor       = null;
				$prefetch     = false;
				$candidates   = array();
				$all_complete = array();
				foreach ( $indices as $index ) {
					$a     = $entries[ $index ]->response->data->authoring;
					$input = json_decode( $entries[ $index ]->input_json );
					if ( 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) || ! empty( $input->selection ) ) {
						continue;
					}
					$prefetch = $prefetch || ( empty( $input->control_paths ) && 'server-prefetch-builder.inspect' === $entries[ $index ]->call_id );
					$coverage = array();
					foreach ( $a->components ?? array() as $component ) {
						$key        = $component->provider_id . '|' . $component->native_id;
						$coverage[] = $key . ':component';
						if ( null !== ( $component->insert_attributes_schema ?? null ) ) {
							$coverage[] = $key . ':insert'; }
						foreach ( $component->controls ?? array() as $control ) {
							$coverage[] = $key . ':control:' . $control->path; }
					}
					$all_complete[ $index ] = array_values( array_unique( $coverage ) );
					if ( empty( $input->control_paths ) ) {
						$candidates[ $index ] = $all_complete[ $index ]; }
				}
				if ( ! $prefetch ) {
					continue; }
				// The existing orchestrator keeps a later equal/superset contract.
				// Choose only a factory read whose actual contracts cover all others.
				foreach ( $candidates as $index => $coverage ) {
					$complete = true;
					foreach ( $all_complete as $other ) {
						$complete = $complete && ! array_diff( $other, $coverage ); }
					if ( $complete ) {
						$anchor = $index; }
				}
				if ( null === $anchor ) {
					continue; }
				$copy = $copy ?? json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
				foreach ( $indices as $index ) {
					if ( $index === $anchor ) {
						continue;
					}
					$a = $copy[ $index ]->response->data->authoring;
					unset( $a->draft->first_loop );
					$a->factory_guidance_reference = (object) array(
						'call_id' => $entries[ $anchor ]->call_id,
						'field'   => 'draft.first_loop',
						'sha256'  => hash( 'sha256', $bytes ),
						'note'    => 'Byte-identical factory guidance is supplied once by this call under the same full native authority snapshot. All requested component schemas, flags, facts and diagnostics in this read remain unchanged. This model-only reference grants no new capability.',
					);
				}
			}
		}
		return $copy ?? $entries;
	}

	/**
	 * Keep passive saved container metadata without treating it as a new target.
	 *
	 * @param object $component Original current native component metadata.
	 * @param object $graph Complete current graph under the same authority pins.
	 * @param object $recipe Current closed factory recipe.
	 * @return bool Whether this is an exact passive source-container ancestor.
	 */
	private static function passive_saved_container( object $component, object $graph, object $recipe ): bool {
		if ( 'divi' !== ( $graph->document->builder ?? null ) || 'divi/core' !== ( $component->provider_id ?? null ) || ! in_array( $component->native_id ?? null, array( 'divi/section', 'divi/row', 'divi/column' ), true ) || true === ( $component->private ?? false ) || true !== ( $component->saved_instance_available ?? null ) || 'saved_component' !== ( $component->authoring_role ?? null ) || ! property_exists( $component, 'controls' ) || array() !== $component->controls || ! property_exists( $component, 'insert_attributes_schema' ) || null !== $component->insert_attributes_schema ) {
			return false;
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$nodes     = array();
		$matches   = array();
		$columns   = array();
		foreach ( $graph->nodes ?? array() as $node ) {
			if ( ! is_string( $node->id ?? null ) || isset( $nodes[ $node->id ] ) ) {
				return false; }
			$nodes[ $node->id ] = $node;
			if ( 'divi/core' === ( $node->provider_id ?? null ) && ( $node->native_id ?? null ) === $component->native_id ) {
				$matches[] = $node->id; }
			if ( 'divi/core' === ( $node->provider_id ?? null ) && 'divi/column' === ( $node->native_id ?? null ) ) {
				$columns[] = $node->id; }
		}
		if ( count( $matches ) !== 1 || count( $columns ) !== 1 ) {
			return false; }
		$current = $columns[0];
		$seen    = array();
		$matched = false;
		for ( $depth = 0; $depth < 16; ++$depth ) {
			if ( isset( $seen[ $current ] ) || ! isset( $nodes[ $current ] ) ) {
				return false; }
			$seen[ $current ] = true;
			$node             = $nodes[ $current ];
			$matched          = $matched || $current === $matches[0];
			if ( true === ( $node->private ?? false ) ) {
				return false; }
			if ( $canonical->encode( $node->locator ?? null ) === $canonical->encode( $recipe->parent ?? null ) ) {
				return $matched && 'directorist-ai' === ( $node->provider_id ?? null ) && 'divi-document' === ( $node->native_id ?? null ); }
			if ( 'divi/core' !== ( $node->provider_id ?? null ) || ! in_array( $node->native_id ?? null, array( 'divi/section', 'divi/row', 'divi/column' ), true ) ) {
				return false; }
			$parents = array_values( array_filter( $graph->edges ?? array(), static fn( $edge ): bool => 'contains' === ( $edge->kind ?? null ) && ( $edge->target ?? null ) === $current && null === ( $edge->target_document_key ?? null ) ) );
			if ( count( $parents ) !== 1 || ! is_string( $parents[0]->source ?? null ) ) {
				return false; }
			$current = $parents[0]->source;
		}
		return false;
	}

	/**
	 * Encode the existing complete native factory authority without caching it.
	 *
	 * @param mixed $entry Current trusted inspection receipt.
	 * @return string|null Full authority encoding, or none for an ineligible read.
	 */
	private static function factory_authority( $entry ): ?string {
		if ( ! $entry instanceof \stdClass ) {
			return null;
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$data      = $entry->response->data ?? null;
		$graph     = $data->graph ?? null;
		$input     = json_decode( $entry->input_json ?? 'null' );
		if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $input instanceof \stdClass || true !== ( $input->include_authoring ?? null ) || ! property_exists( $input, 'editor_binding_id' ) || null !== $input->editor_binding_id || ! in_array( $graph->document->builder ?? null, array( 'gutenberg', 'divi' ), true ) || 'page' !== ( $graph->document->native_kind ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || true !== ( $data->semantic_report->accepted ?? null ) || null !== ( $graph->version->editor_epoch ?? null ) || $canonical->encode( $input->document ?? null ) !== $canonical->encode( $graph->document ) || ! is_string( $data->document_key ?? null ) ) {
			return null; }
		$hashes = array( $data->graph_hash ?? null, $data->fact_revision ?? null, $data->authoring->catalog_revision ?? null, $data->authoring->semantic_rule_set_revision ?? null, $graph->version->source_hash ?? null );
		foreach ( $hashes as $hash ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				return null; }
		}
		return $canonical->encode( array( $data->document_key, $graph, $hashes, $input->document, $input->directory_id ?? null, $input->scope ?? null, $input->editor_binding_id ) );
	}

	/**
	 * Reuse the existing exact one-factory/current-parent checks.
	 *
	 * @param array $entries Current trusted inspection receipts.
	 * @return int|null Exact anchor index, or none for ambiguous/unready context.
	 */
	private static function factory_anchor( array $entries ): ?int {
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$anchors   = array();
		foreach ( $entries as $index => $entry ) {
			if ( ! $entry instanceof \stdClass ) {
				return null;
			}
			$a      = $entry->response->data->authoring ?? null;
			$recipe = $a->draft->first_loop ?? null;
			$input  = json_decode( $entry->input_json ?? 'null' );
			if ( null !== self::factory_authority( $entry ) && 'complete_for_selection' === ( $a->state ?? null ) && true === ( $a->can_propose_private_draft ?? null ) && ! isset( $a->creation_control_reads ) && empty( $input->control_paths ) && empty( $input->selection ) && ( $recipe->carrier_attributes_schema ?? null ) instanceof \stdClass && 1 === ( $recipe->max_changes ?? null ) && 1 === ( $recipe->max_insert_nodes ?? null ) && is_int( $recipe->position ?? null ) && $recipe->position >= 0 && count( $recipe->subtree->nodes ?? array() ) === 1 && array() === ( $recipe->subtree->edges ?? null ) && $canonical->encode( $recipe->scope ?? null ) === $canonical->encode( $input->scope ?? null ) ) {
				$anchors[] = $index;
			}
		}
		if ( count( $anchors ) !== 1 ) {
			return null; }
		$index   = $anchors[0];
		$anchor  = $entries[ $index ];
		$recipe  = $anchor->response->data->authoring->draft->first_loop;
		$graph   = $anchor->response->data->graph;
		$parents = array_values( array_filter( $graph->nodes ?? array(), static fn( $node ): bool => 'directorist-ai' === ( $node->provider_id ?? null ) && ( $graph->document->builder . '-document' ) === ( $node->native_id ?? null ) && null === ( $node->opaque_ref ?? null ) && array( 'content' ) === ( $node->locator->slot_path ?? null ) && $canonical->encode( $node->locator ?? null ) === $canonical->encode( $recipe->parent ?? null ) && $canonical->encode( $node->scope ?? null ) === $canonical->encode( $recipe->scope ?? null ) ) );
		if ( count( $parents ) !== 1 || ! is_string( $anchor->call_id ?? null ) ) {
			return null; }
		$children = array_values( array_filter( $graph->edges ?? array(), static fn( $edge ): bool => 'contains' === ( $edge->kind ?? null ) && ( $edge->source ?? null ) === $parents[0]->id && null === ( $edge->target_document_key ?? null ) ) );
		if ( count( $children ) !== $recipe->position ) {
			return null; }

		return $index;
	}

	/**
	 * Whether one current unselected ordinary-page factory is explicitly ready.
	 *
	 * @param array $entries Fresh server-prefetched or expanded native receipts.
	 * @return bool Presentation capability only; never a whole-goal or write grant.
	 */
	public static function has_initial_factory( array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof \stdClass ) {
				return false; }
		}
		$index = self::factory_anchor( $entries );
		if ( null === $index ) {
			return false; }
		$a = $entries[ $index ]->response->data->authoring;
		$f = $a->draft->first_loop;
		return null === ( $f->scope->view ?? null ) && null === ( $f->scope->breakpoint_id ?? null ) && 'normal' === ( $f->scope->interaction ?? null ) && true === ( $a->draft_readiness->ready ?? null ) && \DirectoristAIPro\Builder\BuilderAuthoringContract::has_actionable_contract( $a ) && ! empty( self::carrier_targets( $f->carrier_attributes_schema ) );
	}

	/**
	 * Distinguish declared factory use from an ordinary child insertion warning.
	 *
	 * Native false flags, schemas, diagnostics and all stored receipts stay exact.
	 * Only literal target/field identities declared by the same closed schema are
	 * identified; unlisted targets and uninspected controls remain unverified.
	 *
	 * @param array $entries Detached initial-layout model receipts.
	 * @return array Original or bounded detached factory guidance.
	 */
	public static function initial_factory_routes( array $entries ): array {
		$original = $entries;
		$entries  = self::duplicate_factory_guidance( $entries );
		if ( ! self::has_initial_factory( $entries ) ) {
			return $original; }
		$index    = self::factory_anchor( $entries );
		$anchor   = $entries[ $index ];
		$identity = self::factory_authority( $anchor );
		$a        = $anchor->response->data->authoring;
		$f        = $a->draft->first_loop;
		$latest   = null;
		foreach ( $entries as $i => $entry ) {
			if ( 'builder.inspect' !== ( $entry->response->operation ?? null ) || true !== ( $entry->response->ok ?? null ) ) {
				continue; }
			if ( self::factory_authority( $entry ) !== $identity ) {
				return $original; }
			foreach ( array( 'owner_ref', 'site_id', 'environment_hash' ) as $field ) {
				if ( ( $entry->response->data->{$field} ?? null ) !== ( $anchor->response->data->{$field} ?? null ) ) {
					return $original; }
			}
			if ( $i !== $index ) {
				$latest = $entry; }
		}
		$route = (object) array(
			'factory_call_id' => $anchor->call_id,
			'recipe_field'    => 'draft.first_loop',
			'recipe_sha256'   => hash( 'sha256', wp_json_encode( $f, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ),
			'status'          => 'available_for_declared_initial_factory',
			'guidance'        => 'Use this complete current first_loop and its explicitly declared typed NEW control branches and initial_card_fields directly. Inspect only exact missing control paths. Ordinary standalone child insertion readiness does not invalidate this separate factory. A document-root sentinel is not a generated child. This is not proof that every requested goal or unlisted target is supported; preserve unknown/unsupported essentials and all native validation.',
		);
		if ( null !== $latest ) {
			$read        = $latest->response->data->authoring ?? null;
			$input       = json_decode( $latest->input_json ?? 'null' );
			$diagnostics = $read->draft_readiness->diagnostics ?? null;
			$declared    = self::carrier_targets( $f->carrier_attributes_schema );
			$fields      = $f->carrier_attributes_schema->properties->initial_card_fields->items->enum ?? array();
			if ( is_array( $fields ) && count( $fields ) <= 32 ) {
				foreach ( $fields as $field ) {
					if ( is_string( $field ) && preg_match( '~^[A-Za-z0-9_-]+/[A-Za-z0-9_/-]{1,190}$~D', $field ) ) {
						$declared[] = $field; }
				}
			}
			$requested = $input->authoring_components ?? null;
			$eligible  = $read instanceof \stdClass && 'needs_narrowing' === ( $read->state ?? null ) && false === ( $read->can_propose_private_draft ?? null ) && false === ( $read->draft_readiness->ready ?? null ) && array() === ( $input->selection ?? null ) && empty( $input->control_paths ) && is_array( $requested ) && count( $requested ) > 0 && count( $requested ) <= 6 && is_array( $diagnostics ) && count( $diagnostics ) === 1 && 'native_listing_context_required' === ( $diagnostics[0]->code ?? null ) && empty( $read->unavailable_requested_components );
			foreach ( $read->diagnostics ?? array() as $diagnostic ) {
				if ( 'native_listing_context_required' === ( $diagnostic->code ?? null ) ) {
					continue; }
				if ( 'component_authoring_unavailable' !== ( $diagnostic->code ?? null ) || 'directorist-ai' !== ( $diagnostic->provider_id ?? null ) || ( $anchor->response->data->graph->document->builder . '-document' ) !== ( $diagnostic->native_id ?? null ) ) {
					$eligible = false; }
			}
			if ( $eligible ) {
				$covered    = array();
				$unverified = array();
				foreach ( $requested as $component ) {
					if ( ! $component instanceof \stdClass || ! is_string( $component->native_id ?? null ) || ! is_string( $component->provider_id ?? null ) ) {
						return $original; }
					$provider = str_starts_with( $component->native_id, 'core/' ) ? 'wordpress/core' : ( str_starts_with( $component->native_id, 'divi/' ) ? 'divi/core' : ( 'gutenberg' === $anchor->response->data->graph->document->builder ? 'directorist-gb-integration' : 'directorist-divi-integration' ) );
					if ( $provider === $component->provider_id && in_array( $component->native_id, $declared, true ) ) {
						$covered[] = $component;
					} else {
						$unverified[] = $component; }
				}
				$route->standalone_read = (object) array(
					'code'                         => 'native_listing_context_required',
					'readiness_scope'              => 'requested_children_standalone_insertion',
					'can_propose_private_draft'    => false,
					'ready'                        => false,
					'declared_factory_targets'     => $covered,
					'unverified_requested_targets' => $unverified,
					'guidance'                     => 'The original false readiness applies to inserting those children alone. Only declared_factory_targets have a route through this current factory; unverified targets still require exact native evidence. This does not grant standalone insertion, saved editing, new fields or missing control schemas.',
				);
			}
		}
		if ( strlen( wp_json_encode( $route ) ) > 3072 ) {
			return $original; }
		$copy = json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		$copy[ $index ]->response->data->authoring->initial_factory_route = $route;
		return $copy;
	}

	/**
	 * Share fully resolved new controls with one fresh Gutenberg/Divi factory receipt.
	 *
	 * @param array $entries Expanded trusted receipts for initial unselected creation.
	 * @return array Detached model view; all native checkpoints and validators stay exact.
	 */
	public static function preset_controls( array $entries ): array {
		$entries   = self::duplicate_factory_guidance( $entries );
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$authority = static fn( object $entry ): ?string => self::factory_authority( $entry );
		$index     = self::factory_anchor( $entries );
		if ( null === $index ) {
			return $entries; }
		$anchor   = $entries[ $index ];
		$identity = self::factory_authority( $anchor );
		$recipe   = $anchor->response->data->authoring->draft->first_loop;
		$graph    = $anchor->response->data->graph;

		$allowed = self::carrier_targets( $recipe->carrier_attributes_schema );
		if ( ! is_array( $allowed ) || ! $allowed ) {
			return $entries; }
		$reads  = array();
		$remove = array();
		$calls  = array( $anchor->call_id => true );
		foreach ( $entries as $read_index => $entry ) {
			if ( $read_index === $index || 'builder.inspect' !== ( $entry->response->operation ?? null ) ) {
				continue; }
			$input = json_decode( $entry->input_json ?? 'null' );
			$a     = $entry->response->data->authoring ?? null;
			if ( empty( $input->control_paths ) || ! self::resolved_controls( $a ) ) {
				continue; }
			if ( $identity !== $authority( $entry ) || ! is_string( $entry->call_id ?? null ) || isset( $calls[ $entry->call_id ] ) || ! is_array( $input->selection ?? null ) || ( $input->selection && $canonical->encode( $input->selection ) !== $canonical->encode( array( $recipe->parent ) ) ) || $canonical->encode( $input->control_paths ) !== $canonical->encode( $a->requested_control_paths ) ) {
				return $entries; }
			$components   = array();
			$paths        = array();
			$control_keys = array();
			foreach ( $a->components ?? array() as $component ) {
				if ( 'dependency' === ( $component->provider_id ?? null ) && empty( $component->controls ) && null === ( $component->insert_attributes_schema ?? null ) && false === ( $component->saved_instance_available ?? null ) ) {
					continue; }
				if ( self::passive_saved_container( $component, $graph, $recipe ) ) {
					$components[] = $component;
					continue;
				}
				if ( ! in_array( $component->native_id ?? null, $allowed, true ) || false !== ( $component->saved_instance_available ?? null ) || 'new_component_insertion' !== ( $component->authoring_role ?? null ) || ! in_array( $component->availability ?? null, array( 'available', 'read_only', 'planning_only' ), true ) ) {
					return $entries; }
				$provider = str_starts_with( $component->native_id, 'core/' ) ? 'wordpress/core' : ( str_starts_with( $component->native_id, 'divi/' ) ? 'divi/core' : ( 'gutenberg' === $anchor->response->data->graph->document->builder ? 'directorist-gb-integration' : 'directorist-divi-integration' ) );
				if ( $provider !== $component->provider_id ) {
					return $entries; }
				foreach ( $component->controls ?? array() as $control ) {
					if ( false !== ( $control->private ?? null ) || ! empty( $control->field_types ) || ! ( $control->value_schema ?? null ) instanceof \stdClass ) {
						return $entries; }
					$control_key = $canonical->encode( array( $provider, $component->native_id, $control->path ) );
					if ( isset( $control_keys[ $control_key ] ) ) {
						return $entries; }
					$control_keys[ $control_key ] = true;
					$paths[]                      = $control->path;
				}
				$value = new \stdClass();
				foreach ( array( 'provider_id', 'native_id', 'policy_revision', 'availability', 'saved_instance_available', 'authoring_role', 'controls', 'initial_control_values' ) as $field ) {
					if ( property_exists( $component, $field ) ) {
						$value->{$field} = $component->{$field}; }
				}
				$components[] = $value;
			}
			$paths = array_values( array_unique( $paths ) );
			sort( $paths );
			$requested = $a->requested_control_paths;
			sort( $requested );
			if ( $paths !== $requested || count( array_unique( $requested ) ) !== count( $requested ) ) {
				return $entries; }
			$read = (object) array(
				'call_id'    => $entry->call_id,
				'components' => $components,
			);
			foreach ( array( 'state', 'can_propose_private_draft', 'diagnostics', 'draft_readiness', 'requested_control_paths', 'missing_control_paths', 'ambiguous_control_paths', 'next_read', 'retry_authoring_groups', 'unavailable_requested_components', 'suggested_authoring_components', 'candidate_relationship_completion', 'selected_area', 'control_effectiveness' ) as $field ) {
				if ( property_exists( $a, $field ) ) {
					$read->{$field} = $a->{$field}; }
			}
			if ( isset( $read->next_read, $read->draft_readiness->next_read ) && $read->next_read === $read->draft_readiness->next_read ) {
				unset( $read->next_read );
				$read->next_read_reference = 'draft_readiness.next_read'; }
			$reads[]                  = $read;
			$remove[ $read_index ]    = true;
			$calls[ $entry->call_id ] = true;
		}
		if ( ! $reads || count( $reads ) > 12 ) {
			return $entries; }
		$copy                           = json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		$a                              = $copy[ $index ]->response->data->authoring;
		$a->creation_control_reads      = json_decode( wp_json_encode( $reads, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		$a->creation_control_reads_note = 'These exact schemas and initial values share this complete current source, graph, directory facts, catalog and rules. Original per-read readiness/diagnostics are retained; standalone unavailability is unchanged. Use only the declared new factory component controls. Unrequested insertion catalogs and duplicate generic draft guidance are omitted from this model view. Full receipts and every native validator remain unchanged; saved edits need a separate current inspection.';
		return array_values( array_filter( $copy, static fn( $entry, int $key ): bool => ! isset( $remove[ $key ] ), ARRAY_FILTER_USE_BOTH ) );
	}

	/**
	 * Show only native preset availability for an initial factory model prompt.
	 *
	 * @param array $entries Server-selected initial-layout tool views.
	 * @return array Detached partial directory views, or original entries.
	 */
	public static function directory_availability( array $entries ): array {
		$factory = false;
		foreach ( $entries as $entry ) {
			$draft = $entry->response->data->authoring->draft ?? null;
			if ( true === ( $entry->response->ok ?? null ) && 'builder.inspect' === ( $entry->response->operation ?? null ) && ( 'elementor_native_root_layout_v1' === ( $draft->creation_mode ?? null ) || ( $draft->first_loop->carrier_attributes_schema ?? null ) instanceof \stdClass ) ) {
				$factory = true;
			}
		}
		if ( ! $factory ) {
			return $entries; }
		$copy = json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		foreach ( $copy as $entry ) {
			$data = $entry->response->data ?? null;
			if ( true !== ( $entry->response->ok ?? null ) || 'directory.describe' !== ( $entry->response->operation ?? null ) || 'public_search' !== ( $data->purpose ?? null ) || ! ( $data->directory ?? null ) instanceof \stdClass || ! is_int( $data->directory->id ?? null ) || ! is_string( $data->schema_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data->schema_hash ) || ! is_array( $data->fields ?? null ) ) {
				continue; }
			$fields = array();
			foreach ( $data->fields as $field ) {
				if ( false === ( $field->admin_only ?? null ) && in_array( $field->widget ?? null, array( 'title', 'image', 'images', 'preview_image', 'image_upload', 'excerpt', 'pricing', 'price', 'category', 'location', 'tag' ), true ) ) {
					$fields[] = (object) array(
						'field_id' => $field->field_id,
						'widget'   => $field->widget,
						'type'     => $field->type,
						'label'    => $field->label,
					);
				}
			}
			$entry->response->data = (object) array(
				'contract'             => $data->contract,
				'directory'            => $data->directory,
				'purpose'              => $data->purpose,
				'schema_hash'          => $data->schema_hash,
				'environment_hash'     => $data->environment_hash,
				'complete'             => false,
				'native_preset_fields' => $fields,
				'prompt_view'          => 'initial_native_factory_availability',
				'note'                 => 'Directory identity and public preset availability only. Submission settings, custom fields and option values are omitted because this factory has no custom binding or submission operation. Use only declared factory card fields. Full descriptions remain stored; this partial view grants no binding, query, field or write authority.',
			);
		}
		return $copy;
	}

	/**
	 * A filtered read can resolve public controls without authorizing standalone insertion.
	 *
	 * @param mixed $authoring Original native authoring response.
	 * @return bool Whether every exact requested path has its public native schema.
	 */
	public static function resolved_controls( $authoring ): bool {
		if ( ! $authoring instanceof \stdClass || empty( $authoring->requested_control_paths ) || ! is_array( $authoring->requested_control_paths ) || ! empty( $authoring->missing_control_paths ) || ! empty( $authoring->ambiguous_control_paths ) ) {
			return false;
		}
		$resolved = array();
		foreach ( $authoring->components ?? array() as $component ) {
			foreach ( $component->controls ?? array() as $control ) {
				if ( false === ( $control->private ?? null ) && is_string( $control->path ?? null ) && ( $control->value_schema ?? null ) instanceof \stdClass ) {
					$resolved[ $control->path ] = true;
				}
			}
		}
		return ! array_filter( $authoring->requested_control_paths, static fn( $path ): bool => ! is_string( $path ) || ! isset( $resolved[ $path ] ) );
	}

	/**
	 * Share repeated creation-control enum values as explicit JSON Schema resources.
	 *
	 * Only the model view changes. Every public path, value constraint, annotation,
	 * native default and full stored receipt remains present and unchanged.
	 *
	 * @param array $entries Detached model-only tool views.
	 * @return array Smaller equivalent views, or the original when sharing is unhelpful.
	 */
	public static function schemas( array $entries ): array {
		( new \DirectoristAIPro\Validation\JsonDecoder( 2097152, 48 ) )->check( $entries );
		$sets       = array();
		$locations  = array();
		$membership = array();
		foreach ( $entries as $index => $entry ) {
			if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) ) {
				continue; }
			foreach ( $entry->response->data->authoring->components ?? array() as $component_index => $component ) {
				$schema = $component->insert_attributes_schema->properties->initial_controls->items->properties->control_path ?? null;
				if ( false !== ( $component->saved_instance_available ?? null ) || 'new_component_insertion' !== ( $component->authoring_role ?? null ) || ! $schema instanceof \stdClass || ! is_array( $schema->enum ?? null ) || ! $schema->enum || array_filter( $schema->enum, static fn( $value ): bool => ! is_string( $value ) ) || count( $schema->enum ) !== count( array_unique( $schema->enum ) ) || array_intersect( array_keys( get_object_vars( $schema ) ), array( '$ref', '$id', 'anyOf', 'oneOf', 'allOf', 'not', 'const' ) ) ) {
					continue; }
				$id               = count( $sets );
				$sets[ $id ]      = $schema->enum;
				$locations[ $id ] = array( $index, $component_index );
				foreach ( $schema->enum as $path ) {
					$membership[ $path ][] = $id; }
			}
		}
		if ( count( $sets ) < 2 ) {
			return $entries; }
		$groups = array();
		foreach ( $membership as $path => $owners ) {
			if ( count( $owners ) > 1 ) {
				$groups[ implode( ',', $owners ) ][] = $path; }
		}
		$resources  = array();
		$references = array();
		$covered    = array();
		foreach ( $groups as $owners_key => $paths ) {
			$owners   = array_map( 'intval', explode( ',', $owners_key ) );
			$uri      = 'urn:dai:creation-paths:' . substr( hash( 'sha256', wp_json_encode( $paths ) ), 0, 24 );
			$resource = (object) array(
				'$id'  => $uri,
				'enum' => $paths,
			);
			if ( strlen( wp_json_encode( $paths ) ) * ( count( $owners ) - 1 ) <= strlen( wp_json_encode( $resource ) ) + count( $owners ) * ( strlen( $uri ) + 12 ) ) {
				continue; }
			if ( isset( $resources[ $uri ] ) && wp_json_encode( $resources[ $uri ] ) !== wp_json_encode( $resource ) ) {
				return $entries; }
			$resources[ $uri ] = $resource;
			foreach ( $owners as $id ) {
				$references[ $id ][] = (object) array( '$ref' => $uri );
				foreach ( $paths as $path ) {
					$covered[ $id ][ $path ] = true; }
			}
		}
		if ( count( $resources ) > 64 ) {
			return $entries; }
		$copy = json_decode( wp_json_encode( $entries, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		foreach ( $references as $id => $parts ) {
			list( $entry_index, $component_index ) = $locations[ $id ];
			$schema                                = $copy[ $entry_index ]->response->data->authoring->components[ $component_index ]->insert_attributes_schema->properties->initial_controls->items->properties->control_path;
			$remaining                             = array_values( array_filter( $sets[ $id ], static fn( string $path ): bool => ! isset( $covered[ $id ][ $path ] ) ) );
			if ( $remaining ) {
				$parts[] = (object) array( 'enum' => $remaining ); }
			unset( $schema->enum );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Exact JSON Schema keyword.
			$schema->anyOf = $parts;
		}
		$templates = array();
		foreach ( $locations as $location ) {
			list( $entry_index, $component_index )     = $location;
			$initial                                   = $copy[ $entry_index ]->response->data->authoring->components[ $component_index ]->insert_attributes_schema->properties->initial_controls;
			$template                                  = json_decode( wp_json_encode( $initial ) );
			$template->items->properties->control_path = (object) array( 'type' => 'string' );
			$encoded                                   = wp_json_encode( $template );
			if ( preg_match( '/"\$(?:id|anchor|dynamicRef|recursiveRef)"\s*:|"\$ref"\s*:\s*"(?!urn:dai:creation-paths:)/', $encoded ) ) {
				continue; }
			$templates[ $encoded ][] = $location;
		}
		foreach ( $templates as $encoded => $members ) {
			if ( count( $members ) < 2 || strlen( $encoded ) < 256 ) {
				continue; }
			$uri               = 'urn:dai:ce:' . substr( hash( 'sha256', $encoded ), 0, 24 );
			$template          = json_decode( $encoded );
			$template->{'$id'} = $uri;
			if ( isset( $resources[ $uri ] ) ) {
				return $entries; }
			$resources[ $uri ] = $template;
			foreach ( $members as $location ) {
				list( $entry_index, $component_index ) = $location;
				$component                             = $copy[ $entry_index ]->response->data->authoring->components[ $component_index ];
				$path                                  = $component->insert_attributes_schema->properties->initial_controls->items->properties->control_path;
				$component->insert_attributes_schema->properties->initial_controls = (object) array(
					'allOf' => array(
						(object) array( '$ref' => $uri ),
						(object) array( 'items' => (object) array( 'properties' => (object) array( 'control_path' => $path ) ) ),
					),
				);
			}
		}
		if ( ! $resources || count( $resources ) > 64 ) {
			return $entries; }
		$first                                    = reset( $locations );
		$data                                     = $copy[ $first[0] ]->response->data;
		$data->authoring->prompt_schema_resources = array_values( $resources );
		$data->context_note                       = ( $data->context_note ?? '' ) . ' prompt_schema_resources supplies the exact external JSON Schema resources referenced by creation-control path enums. Resolve their urn IDs to these supplied resources. Full native schemas and validation are unchanged; these model-only descriptions grant no authority.';
		return strlen( wp_json_encode( $copy ) ) < strlen( wp_json_encode( $entries ) ) ? $copy : $entries;
	}
}
