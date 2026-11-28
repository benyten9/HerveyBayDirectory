<?php
/**
 * Filtered native control values for model-only builder inspection context.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Full native receipts and all application validation remain authoritative. */
final class BuilderControlPrompt {

	/**
	 * A filtered multi-target read needs its exact controls, not every saved style.
	 *
	 * Keep all graph identities, relationships, bindings and ordinary attributes.
	 * Only bulky adapter control families outside the returned public contracts
	 * are omitted from this model view. Their absence is explicitly unknown.
	 *
	 * @param array $entries Server-projected builder tool receipts.
	 * @return array Detached views where a filtered source allows narrowing.
	 */
	public static function project( array $entries ): array {
		$rejected = array();
		$evidence = array();
		$complete = array();
		$color_contexts = array();
		foreach ( $entries as $index => $entry ) {
			$a = $entry->response->data->authoring ?? null;
			$complete_input = $entry->input ?? ( is_string( $entry->input_json ?? null ) ? json_decode( $entry->input_json ) : null );
			$identity = self::read_identity( $entry );
			$revisions = isset( $entry->response->data ) ? self::read_revisions( $entry->response->data ) : null;
			if ( null === $identity || null === $revisions || ! isset( $revisions['catalog_revision'], $revisions['semantic_rule_set_revision'] ) || true !== ( $complete_input->include_authoring ?? null ) || ! is_array( $complete_input->control_paths ?? null ) || ! $complete_input->control_paths || 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) || ! in_array( 'set_control', $a->draft->operations ?? array(), true ) || empty( $a->requested_control_paths ) || ! empty( $a->missing_control_paths ) || ! empty( $a->ambiguous_control_paths ) || ! empty( $a->unavailable_requested_components ) || ! is_string( $entry->call_id ?? null ) || '' === $entry->call_id ) { continue; }
			if ( self::complete_control_values( $entry, $complete_input ) ) { $complete[ $identity ][] = array( 'index' => $index, 'revisions' => $revisions, 'call_id' => $entry->call_id ); }
		}
		foreach ( $entries as $index => $entry ) {
			$data = $entry->response->data ?? null;
			$graph = $data->graph ?? null;
			$authoring = $data->authoring ?? null;
			$input = $entry->input ?? ( is_string( $entry->input_json ?? null ) ? json_decode( $entry->input_json ) : null );
			if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $input instanceof \stdClass || ! $graph instanceof \stdClass || ! is_array( $graph->nodes ?? null ) || ! in_array( $graph->document->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) ) {
				continue;
			}
			$identity = self::read_identity( $entry );
			$revisions = self::read_revisions( $data );
			$matching = null !== $revisions;
			foreach ( $revisions ?? array() as $field => $revision ) { if ( null !== $identity && isset( $rejected[ $identity ][ $field ] ) && $revision !== $rejected[ $identity ][ $field ] ) { $matching = false; } }
			$current_rejection = self::rejected_discovery( $authoring );
			if ( $current_rejection && ! $matching ) { continue; }
			if ( null !== $identity && $matching && true === ( $input->include_authoring ?? null ) && $current_rejection ) { $rejected[ $identity ] = array_merge( $rejected[ $identity ] ?? array(), $revisions ); }
			$recovery = null !== $identity && $matching && isset( $rejected[ $identity ] ) && ( ( true === ( $input->include_authoring ?? null ) && $current_rejection ) || ( false === ( $input->include_authoring ?? null ) && null === $authoring ) );
			$filtered = true === ( $input->include_authoring ?? null ) && is_array( $input->control_paths ?? null ) && $input->control_paths && $authoring instanceof \stdClass && is_array( $authoring->requested_control_paths ?? null ) && $authoring->requested_control_paths;
			if ( ! $recovery && ! $filtered ) { continue; }
			// A malformed/partial request is not a basis for changing its model view.
			if ( ( isset( $input->control_paths ) && ( ! is_array( $input->control_paths ) || array_filter( $input->control_paths, static fn( $path ): bool => ! is_string( $path ) || '' === $path ) ) ) || ( isset( $authoring->requested_control_paths ) && ( ! is_array( $authoring->requested_control_paths ) || array_filter( $authoring->requested_control_paths, static fn( $path ): bool => ! is_string( $path ) || '' === $path ) ) ) ) { continue; }
			if ( array_filter( $graph->nodes, static fn( $node ): bool => ! $node instanceof \stdClass ) ) { continue; }
			$paths = array();
			foreach ( $authoring->components ?? array() as $component ) {
				if ( ! is_string( $component->provider_id ?? null ) || ! is_string( $component->native_id ?? null ) ) { continue; }
				$key = $component->provider_id . '|' . $component->native_id;
				foreach ( $component->controls ?? array() as $control ) {
					if ( false === ( $control->private ?? null ) && is_string( $control->path ?? null ) && ( $control->value_schema ?? null ) instanceof \stdClass ) {
						$paths[ $key ][] = $control->path;
					}
				}
			}
			$view = clone $entry;
			$view->response = clone $entry->response;
			$view->response->data = clone $data;
			$view->response->data->graph = clone $graph;
			$nodes = array();
			$changed = false;
			if ( $recovery && $current_rejection ) {
				$view->response->data->authoring = clone $authoring;
				if ( isset( $authoring->draft->first_loop ) ) {
					$view->response->data->authoring->draft = clone $authoring->draft;
					unset( $view->response->data->authoring->draft->first_loop );
				}
				unset( $view->response->data->authoring->design_context, $view->response->data->design_context );
				$view->response->data->context_note = ( $data->context_note ?? '' ) . ' Rejected byte-bound discovery: unusable first_loop/design_context hints remain on the server. No authoring authority. Follow the unchanged control_index/next_read for a complete native contract.';
				$changed = true;
			}
			foreach ( $graph->nodes as $node ) {
				$copy = clone $node;
				if ( ! ( $node->attributes ?? null ) instanceof \stdClass ) { $nodes[] = $copy; continue; }
				$attributes = clone $node->attributes;
				$key = ( $node->provider_id ?? '' ) . '|' . ( $node->native_id ?? $node->component_native_id ?? '' );
				$omitted = array();
				foreach ( array( 'native_style', 'native_controls', 'native_query' ) as $family ) {
					if ( ! property_exists( $attributes, $family ) ) { continue; }
					$selected = array_values( array_filter( $paths[ $key ] ?? array(), static fn( string $path ): bool => $path === $family || str_starts_with( $path, $family . '.' ) ) );
					if ( in_array( $family, $selected, true ) ) { continue; }
					$kept = new \stdClass();
					foreach ( $selected as $path ) { self::copy_path( $node->attributes, $kept, explode( '.', $path ) ); }
					if ( property_exists( $kept, $family ) ) { $attributes->{$family} = $kept->{$family}; } else { unset( $attributes->{$family} ); }
					if ( json_encode( $node->attributes->{$family}, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) !== json_encode( $attributes->{$family} ?? null, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) { $omitted[] = $family; }
				}
				if ( $omitted ) {
					$copy->attributes = $attributes;
					$copy->unrequested_native_control_families = $omitted;
					$changed = true;
				}
				$nodes[] = $copy;
			}
			if ( $changed ) {
				$view->response->data->graph->nodes = $nodes;
				$view->response->data->context_note = ( $view->response->data->context_note ?? '' ) . ' Bounded model view: returned public schemas/values and included locators, relationships, bindings and capability flags are exact. unrequested_native_control_families are UNKNOWN, never defaults/unset. Full receipt stays on the server. Inspect the exact needed control before editing; no authority is granted.';
				$entries[ $index ] = $view;
			}
			$entries[ $index ] = self::selected_regions( $entries[ $index ], $input );
			if ( $filtered ) { $entries[ $index ] = self::complete_control_hints( $entries[ $index ], $input ); }
			if ( $recovery && ! $current_rejection ) { $entries[ $index ] = self::recovery_region( $entries[ $index ], $input ); }
			if ( $recovery ) { $entries[ $index ] = self::reuse_recovery_facts( $entries[ $index ], $identity, $revisions, $current_rejection, $evidence ); }
			if ( $recovery && is_string( $entry->call_id ?? null ) && '' !== $entry->call_id && isset( $rejected[ $identity ]['catalog_revision'], $rejected[ $identity ]['semantic_rule_set_revision'] ) ) {
				foreach ( $complete[ $identity ] ?? array() as $covered ) {
					if ( $covered['index'] > $index && ! array_diff_assoc( $rejected[ $identity ], $covered['revisions'] ) ) { $entries[ $index ] = self::covered_recovery( $entries[ $index ], $covered['call_id'] ); break; }
				}
			}
			if ( $filtered ) { $entries[ $index ] = self::related_color_view( $entries[ $index ], $entry, $input, $color_contexts ); }
		}
		return $entries;
	}

	/** One exact source/owner-pinned copy of optional readonly native colour facts. */
	private static function related_color_view( object $view, object $source, object $input, array &$seen ): object {
		$context = $view->response->data->authoring->related_color_context ?? null;
		if ( null === $context ) { return $view; }
		$identity = self::read_identity( $source );
		$revisions = self::read_revisions( $source->response->data );
		$area = $source->response->data->authoring->selected_area ?? null;
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$valid = $context instanceof \stdClass && 'native-related-colours-v1' === ( $context->revision ?? null ) && true === ( $context->read_only ?? null ) && null !== $identity && isset( $revisions['catalog_revision'], $revisions['semantic_rule_set_revision'] ) && self::complete_control_values( $source, $input ) && ( $context->provider_id ?? null ) === ( $area->provider_id ?? null ) && ( $context->native_id ?? null ) === ( $area->native_id ?? null ) && $canonical->encode( $context->locator ?? null ) === $canonical->encode( $area->locator ?? null ) && $canonical->encode( $context->scope ?? null ) === $canonical->encode( $input->scope ?? null ) && strlen( $canonical->encode( $context ) ) <= 4096 && is_string( $source->call_id ?? null ) && '' !== $source->call_id;
		if ( ! $valid ) {
			$result = clone $view; $result->response = clone $view->response; $result->response->data = clone $view->response->data; $result->response->data->authoring = clone $view->response->data->authoring;
			unset( $result->response->data->authoring->related_color_context );
			return $result;
		}
		$digest = $canonical->digest( $context );
		$key = $canonical->encode( array( $identity, $revisions, $context->provider_id, $context->native_id, $context->locator, $context->scope, $digest ) );
		if ( ! isset( $seen[ $key ] ) ) { $seen[ $key ] = (object) array( 'call_id' => $source->call_id, 'path' => 'response.data.authoring.related_color_context', 'sha256' => $digest ); return $view; }
		if ( $seen[ $key ]->call_id === $source->call_id ) { return $view; }
		$result = clone $view; $result->response = clone $view->response; $result->response->data = clone $view->response->data; $result->response->data->authoring = clone $view->response->data->authoring;
		$result->response->data->authoring->related_color_context_reference = $seen[ $key ];
		unset( $result->response->data->authoring->related_color_context );
		$result->response->data->context_note = ( $result->response->data->context_note ?? '' ) . ' related_color_context_reference reuses the exact earlier readonly colours for this same native owner/source/scope/revisions. It supplies no schemas or permission.';
		return $result;
	}

	/** Exact selected schemas/values need no read-only index of unrequested paths. */
	private static function complete_control_values( object $entry, object $input ): bool {
		$data = $entry->response->data;
		$a = $data->authoring;
		if ( 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) || ! empty( $a->missing_control_paths ) || ! empty( $a->ambiguous_control_paths ) || ! empty( $a->unavailable_requested_components ) || ! is_array( $a->requested_control_paths ?? null ) || ! $a->requested_control_paths || ! is_array( $data->graph->nodes ?? null ) || array_filter( $data->graph->nodes, static fn( $node ): bool => ! $node instanceof \stdClass ) || ! ( $a->selected_area->locator ?? null ) instanceof \stdClass || ! is_array( $input->selection ?? null ) || 1 !== count( $input->selection ) ) { return false; }
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		if ( $canonical->encode( $input->selection[0] ) !== $canonical->encode( $a->selected_area->locator ) ) { return false; }
		$selected = array_values( array_filter( $data->graph->nodes, static fn( object $node ): bool => $canonical->encode( $node->locator ?? null ) === $canonical->encode( $input->selection[0] ) ) );
		if ( 1 !== count( $selected ) || ! ( $selected[0]->attributes ?? null ) instanceof \stdClass ) { return false; }
		$node = $selected[0];
		foreach ( $a->requested_control_paths as $path ) {
			$covered = false;
			foreach ( $a->components ?? array() as $component ) {
				if ( ( $component->provider_id ?? null ) !== ( $node->provider_id ?? null ) || ( $component->native_id ?? null ) !== ( $node->native_id ?? $node->component_native_id ?? null ) ) { continue; }
				foreach ( $component->controls ?? array() as $control ) { $covered = $covered || ( $path === ( $control->path ?? null ) && false === ( $control->private ?? null ) && ! ( $control->read_only ?? false ) && ( $control->value_schema ?? null ) instanceof \stdClass ); }
			}
			$value = $node->attributes;
			foreach ( explode( '.', $path ) as $part ) { if ( ! $value instanceof \stdClass || ! property_exists( $value, $part ) ) { return false; } $value = $value->{$part}; }
			if ( ! $covered ) { return false; }
		}
		return true;
	}

	/** Omit only an unnecessary discovery index, preserving every successful contract. */
	private static function complete_control_hints( object $entry, object $input ): object {
		$data = $entry->response->data;
		$a = $data->authoring;
		if ( ! isset( $a->control_index ) || ! self::complete_control_values( $entry, $input ) ) { return $entry; }
		$view = clone $entry;
		$view->response = clone $entry->response;
		$view->response->data = clone $data;
		$view->response->data->authoring = clone $a;
		unset( $view->response->data->authoring->control_index, $view->response->data->authoring->control_index_complete );
		$view->response->data->context_note = ( $data->context_note ?? '' ) . ' Every requested exact selected path has its public schema and current value. Only the read-only index of unrequested paths is omitted; those paths need fresh inspection. Returned contracts/values/readiness are unchanged; no added authority.';
		return $view;
	}

	/** A current complete same-source control read replaces earlier failed discovery bulk. */
	private static function covered_recovery( object $entry, string $complete_call ): object {
		$data = $entry->response->data;
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$authoring = $data->authoring ?? null;
		if ( $authoring instanceof \stdClass ) {
			$summary = (object) array();
			foreach ( array( 'state', 'can_propose_private_draft', 'catalog_revision', 'semantic_rule_set_revision', 'diagnostics', 'requested_control_paths', 'missing_control_paths', 'ambiguous_control_paths', 'unavailable_requested_components', 'requires_full_server_validation' ) as $field ) { if ( property_exists( $authoring, $field ) ) { $summary->{$field} = $authoring->{$field}; } }
			foreach ( array( 'control_index', 'next_read' ) as $field ) { if ( property_exists( $authoring, $field ) ) { $summary->{ $field . '_server_reference' } = (object) array( 'call_id' => $entry->call_id, 'path' => 'response.data.authoring.' . $field, 'sha256' => $canonical->digest( $authoring->{$field} ) ); } }
			$authoring = $summary;
		}
		$view = clone $entry;
		$view->response = clone $entry->response;
		$view->response->data = (object) array(
			'document_key' => $data->document_key,
			'graph_hash' => $data->graph_hash,
			'fact_revision' => $data->fact_revision,
			'authoring' => $authoring,
			'projection' => $data->projection ?? null,
			'source_readiness' => (object) array( 'completeness' => $data->graph->completeness, 'consumer_coverage' => $data->graph->consumer_coverage ),
			'covered_by_current_control_read' => $complete_call,
			'full_server_receipt_reference' => (object) array( 'call_id' => $entry->call_id, 'document_key' => $data->document_key, 'graph_hash' => $data->graph_hash, 'fact_revision' => $data->fact_revision ),
			'context_note' => 'Earlier discovery only: failure/readiness flags remain exact. Its bulky graph, indexes and guidance remain in the referenced full server receipt. The later complete control read has the identical native source/scope/catalog/rules. Use its exact declared controls now; other targets/controls still require fresh inspection. This summary grants no authority.',
		);
		return $view;
	}

	/** Reference only canonically identical facts already fully present in this packet. */
	private static function reuse_recovery_facts( object $entry, string $identity, array $revisions, bool $rejected, array &$seen ): object {
		if ( ! is_string( $entry->call_id ?? null ) || '' === $entry->call_id ) { return $entry; }
		$view = clone $entry;
		$view->response = clone $entry->response;
		$view->response->data = clone $entry->response->data;
		$data = $view->response->data;
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$reused = array();
		$fields = array( 'directory_query', 'directory_binding' );
		if ( $rejected && isset( $revisions['catalog_revision'], $revisions['semantic_rule_set_revision'] ) ) { $fields[] = 'control_index'; }
		foreach ( $fields as $field ) {
			$source = 'control_index' === $field ? ( $data->authoring ?? null ) : $data;
			if ( ! $source instanceof \stdClass || ! property_exists( $source, $field ) || null === $source->{$field} ) { continue; }
			$hash = $canonical->digest( $source->{$field} );
			$key = $canonical->encode( array( $identity, $field, $hash, 'control_index' === $field ? $revisions : null ) );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = (object) array( 'call_id' => $entry->call_id, 'path' => 'response.data.' . ( 'control_index' === $field ? 'authoring.' : '' ) . $field, 'sha256' => $hash );
				continue;
			}
			if ( $seen[ $key ]->call_id === $entry->call_id || strlen( $canonical->encode( $seen[ $key ] ) ) >= strlen( $canonical->encode( $source->{$field} ) ) ) { continue; }
			if ( 'control_index' === $field ) {
				$data->authoring = clone $data->authoring;
				$source = $data->authoring;
			}
			$source->{ $field . '_reference' } = $seen[ $key ];
			unset( $source->{$field} );
			$reused[] = $field;
		}
		if ( $reused ) { $data->context_note = ( $data->context_note ?? '' ) . ' ' . implode( '/', $reused ) . '_reference points to the exact earlier call/path/SHA256 fact under unchanged source revisions. Full receipts stay on the server; no inferred authority.'; }
		return $view;
	}

	/** Walk one exact rejected-discovery container without emitting descendant values. */
	private static function recovery_region( object $entry, object $input ): object {
		$data = $entry->response->data;
		$graph = $data->graph;
		if ( ! is_array( $input->selection ?? null ) || 1 !== count( $input->selection ) || ! $input->selection[0] instanceof \stdClass || isset( $graph->discovery_index ) || ! is_array( $graph->edges ?? null ) || true !== ( $data->semantic_report->accepted ?? null ) ) { return $entry; }
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$nodes = array();
		$selected = array();
		foreach ( $graph->nodes as $node ) {
			if ( ! is_string( $node->id ?? null ) || '' === $node->id || isset( $nodes[ $node->id ] ) || ! ( $node->locator ?? null ) instanceof \stdClass ) { return $entry; }
			$nodes[ $node->id ] = $node;
			if ( $canonical->encode( $node->locator ) === $canonical->encode( $input->selection[0] ) ) { $selected[] = $node; }
		}
		if ( 1 !== count( $selected ) || null !== ( $selected[0]->opaque_ref ?? null ) ) { return $entry; }
		$owner = $selected[0]->id;
		$keep = array( $owner => true );
		$children = array();
		foreach ( $graph->edges as $edge ) {
			if ( ! $edge instanceof \stdClass || ! is_string( $edge->source ?? null ) || ! is_string( $edge->target ?? null ) ) { return $entry; }
			if ( 'contains' === ( $edge->kind ?? null ) && null === ( $edge->target_document_key ?? null ) && $owner === $edge->source ) {
				if ( ! isset( $nodes[ $edge->target ] ) ) { return $entry; }
				$children[ $edge->target ] = true;
			}
		}
		if ( ! $children ) { return $entry; }
		// Ancestors only: the direct children do not pull their descendants in.
		for ( $pass = 0; $pass < count( $nodes ); ++$pass ) {
			$before = count( $keep );
			foreach ( $graph->edges as $edge ) {
				if ( 'contains' === ( $edge->kind ?? null ) && null === ( $edge->target_document_key ?? null ) && isset( $keep[ $edge->target ] ) ) {
					if ( ! isset( $nodes[ $edge->source ] ) ) { return $entry; }
					$keep[ $edge->source ] = true;
				}
			}
			if ( count( $keep ) === $before ) { break; }
		}
		$keep += $children;
		$edges = array_values( array_filter( $graph->edges, static fn( object $edge ): bool => 'contains' === ( $edge->kind ?? null ) && null === ( $edge->target_document_key ?? null ) && isset( $keep[ $edge->source ], $keep[ $edge->target ] ) ) );
		if ( count( $keep ) === count( $nodes ) && count( $edges ) === count( $graph->edges ) ) { return $entry; }
		$view = clone $entry;
		$view->response = clone $entry->response;
		$view->response->data = clone $data;
		$view->response->data->graph = clone $graph;
		$view->response->data->graph->nodes = array_values( array_filter( $graph->nodes, static fn( object $node ): bool => isset( $keep[ $node->id ] ) ) );
		$view->response->data->graph->edges = $edges;
		unset( $view->response->data->graph->slot_coverage );
		$view->response->data->graph->discovery_index = (object) array(
			'kind' => 'selected_region_direct_children',
			'editable_values' => false,
			'full_graph_included' => false,
			'omitted_nodes' => count( $nodes ) - count( $keep ),
			'omitted_edges' => count( $graph->edges ) - count( $edges ),
			'next_read' => 'Inspect the exact indexed child with include_authoring true and necessary component/control_paths from control_index. For deeper regions inspect the exact indexed parent first. ALL direct children are present; omitted descendants/bindings/values are UNKNOWN. A complete contract is required before proposing.',
		);
		$view->response->data->graph_reference = (object) array( 'document' => $graph->document, 'document_key' => $data->document_key, 'graph_hash' => $data->graph_hash, 'fact_revision' => $data->fact_revision );
		$view->response->data->context_note = ( $data->context_note ?? '' ) . ' Model-only recovery index: the selected container, ALL ancestors/direct children retain exact IDs/locators/scopes/containment. Omitted descendants, noncontainment bindings and values are UNKNOWN; inspect before editing. Flags describe the complete server receipt in graph_reference. Full graph/native validation are unchanged; no write-scope authority.';
		return $view;
	}

	/** A byte-bound rejection supplies recovery instructions, never usable controls. */
	private static function rejected_discovery( mixed $authoring ): bool {
		return $authoring instanceof \stdClass && 'needs_narrowing' === ( $authoring->state ?? null ) && false === ( $authoring->can_propose_private_draft ?? null ) && array() === ( $authoring->components ?? null ) && is_array( $authoring->diagnostics ?? null ) && ! array_filter( $authoring->diagnostics, static fn( $item ): bool => ! $item instanceof \stdClass ) && (bool) array_filter( $authoring->diagnostics, static fn( object $item ): bool => 'authoring_contract_byte_bound_exceeded' === ( $item->code ?? null ) );
	}

	/** Bind graph-only recovery to an exact rejected native observation in this packet. */
	private static function read_identity( object $entry ): ?string {
		$data = $entry->response->data ?? null;
		$input = $entry->input ?? ( is_string( $entry->input_json ?? null ) ? json_decode( $entry->input_json ) : null );
		$graph = $data->graph ?? null;
		if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $input instanceof \stdClass || ! ( $input->document ?? null ) instanceof \stdClass || ! $graph instanceof \stdClass || ! ( $graph->document ?? null ) instanceof \stdClass || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || ! is_string( $data->document_key ?? null ) || '' === $data->document_key || ! is_string( $data->graph_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data->graph_hash ) || ! is_string( $data->fact_revision ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data->fact_revision ) ) { return null; }
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		if ( $canonical->encode( $graph->document ) !== $canonical->encode( $input->document ) ) { return null; }
		return $canonical->encode( array( $data->document_key, $data->graph_hash, $data->fact_revision, $graph->document, $graph->version ?? null, $input->document, $input->directory_id ?? null, $input->scope ?? null, $input->editor_binding_id ?? null ) );
	}

	/** Compare populated native revisions; an absent graph-only revision is unknown. */
	private static function read_revisions( object $data ): ?array {
		$revisions = array();
		foreach ( array( 'catalog_revision', 'semantic_rule_set_revision' ) as $field ) {
			foreach ( array( $data->authoring ?? null, $data, $data->graph ?? null ) as $source ) {
				if ( ! $source instanceof \stdClass || ! property_exists( $source, $field ) ) { continue; }
				$value = $source->{$field};
				if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) || ( isset( $revisions[ $field ] ) && $revisions[ $field ] !== $value ) ) { return null; }
				$revisions[ $field ] = $value;
			}
		}
		return $revisions;
	}

	/** Keep every exact requested owner and required relationship/ancestor context. */
	private static function selected_regions( object $entry, object $input ): object {
		$data = $entry->response->data;
		$graph = $data->graph;
		if ( ! is_array( $input->selection ?? null ) || count( $input->selection ) < 2 || ! is_array( $graph->edges ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || isset( $graph->discovery_index ) ) { return $entry; }
		if ( array_filter( $graph->edges, static fn( $edge ): bool => ! $edge instanceof \stdClass || ! is_string( $edge->source ?? null ) || ! is_string( $edge->target ?? null ) ) ) { return $entry; }
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$selected = array();
		$nodes = array();
		foreach ( $graph->nodes as $node ) {
			if ( ! is_string( $node->id ?? null ) || isset( $nodes[ $node->id ] ) || ! ( $node->locator ?? null ) instanceof \stdClass ) { return $entry; }
			$nodes[ $node->id ] = $node;
		}
		foreach ( $input->selection as $locator ) {
			if ( ! $locator instanceof \stdClass ) { return $entry; }
			$matches = array();
			foreach ( $nodes as $id => $node ) { if ( $canonical->encode( $node->locator ) === $canonical->encode( $locator ) ) { $matches[] = $id; } }
			if ( 1 !== count( $matches ) ) { return $entry; }
			$selected[ $matches[0] ] = true;
		}
		$keep = $selected;
		$native_completion = 'installed_adapter' === ( $data->authoring->candidate_relationship_completion ?? null );
		// The installed native compiler supplies dependency context. Other
		// adapters keep all returned non-containment relationships of the targets.
		if ( ! $native_completion ) {
			foreach ( $graph->edges as $edge ) {
				if ( 'contains' !== ( $edge->kind ?? null ) && null === ( $edge->target_document_key ?? null ) && ( isset( $selected[ $edge->source ] ) || isset( $selected[ $edge->target ] ) ) ) { $keep[ $edge->source ] = true; $keep[ $edge->target ] = true; }
			}
		}
		for ( $pass = 0; $pass < count( $nodes ); ++$pass ) {
			$before = count( $keep );
			foreach ( $graph->edges as $edge ) {
				if ( 'contains' === ( $edge->kind ?? null ) && null === ( $edge->target_document_key ?? null ) && isset( $keep[ $edge->target ] ) ) { $keep[ $edge->source ] = true; }
			}
			if ( $before === count( $keep ) ) { break; }
		}
		if ( count( $keep ) >= count( $nodes ) ) { return $entry; }
		$view = clone $entry;
		$view->response = clone $entry->response;
		$view->response->data = clone $data;
		$view->response->data->graph = clone $graph;
		$view->response->data->graph->nodes = array_values( array_filter( $graph->nodes, static fn( object $node ): bool => isset( $keep[ $node->id ] ) ) );
		$view->response->data->graph->edges = array_values( array_filter( $graph->edges, static fn( object $edge ): bool => null === ( $edge->target_document_key ?? null ) && isset( $keep[ $edge->source ], $keep[ $edge->target ] ) ) );
		$view->response->data->graph->prompt_view = 'filtered_native_control_targets';
		$view->response->data->graph_reference = (object) array( 'document_key' => $data->document_key ?? null, 'graph_hash' => $data->graph_hash ?? null, 'fact_revision' => $data->fact_revision ?? null, 'catalog_revision' => $data->authoring->catalog_revision ?? null, 'semantic_rule_set_revision' => $data->authoring->semantic_rule_set_revision ?? null );
		$view->response->data->context_note = ( $data->context_note ?? '' ) . ' This model-only graph view includes EVERY explicitly selected target plus its ancestors and required native dependencies. Unselected regions and their bindings remain exact in the full server receipt identified by graph_reference; they are not new editable targets or missing source data. Native source completeness/readiness flags are unchanged. Preserve those unselected regions; another change needs its own exact selected inspection.';
		return $view;
	}

	/** Copy an exact present nested control, preserving false, zero and null. */
	private static function copy_path( object $source, object $target, array $parts ): void {
		$value = $source;
		foreach ( $parts as $part ) {
			if ( ! $value instanceof \stdClass || ! property_exists( $value, $part ) ) { return; }
			$value = $value->{$part};
		}
		$last = array_pop( $parts );
		$cursor = $target;
		foreach ( $parts as $part ) {
			if ( ! property_exists( $cursor, $part ) ) { $cursor->{$part} = new \stdClass(); }
			$cursor = $cursor->{$part};
		}
		$cursor->{$last} = $value;
	}
}
