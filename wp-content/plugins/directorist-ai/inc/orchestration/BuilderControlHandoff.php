<?php
/** Exact completed native controls, independent of model interpretation. */
namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Builder\{BuilderEditorSelection,GraphVersion};
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** Read-only proof for removing redundant discovery; it grants no native action. */
final class BuilderControlHandoff {

	/** Return current public paths only when their complete values reach the model. */
	public static function visible( object $entry, array $projected, ?object $selection = null ): ?object {
		$proof = self::proof( $entry, $selection );
		if ( null === $proof ) { return null; }
		$c = new JsonCanonicalizer();
		foreach ( $projected as $view ) {
			$d = $view->response->data ?? null; $a = $d->authoring ?? null;
			// Deduplication can retain an earlier superset. Its exact source,
			// schemas and values must still cover every requested current path.
			if ( true !== ( $view->response->ok ?? null ) || 'builder.inspect' !== ( $view->response->operation ?? null ) || ! $a instanceof \stdClass || 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) ) { continue; }
			foreach ( array( 'document_key','graph_hash','fact_revision' ) as $key ) { if ( ( $d->$key ?? null ) !== $proof->source->$key ) { continue 2; } }
			foreach ( array( 'catalog_revision','semantic_rule_set_revision' ) as $key ) { if ( ( $a->$key ?? null ) !== $proof->source->$key ) { continue 2; } }
			if ( $c->encode( $a->selected_area->locator ?? null ) !== $c->encode( $proof->locator ) || $c->encode( $a->selected_area->scope ?? $d->graph->default_scope ?? null ) !== $c->encode( $proof->scope ) ) { continue; }
			$controls = array();
			foreach ( is_array( $a->components ?? null ) ? $a->components : array() as $component ) {
				if ( ! self::public_entity( $component ) || ! is_array( $component->controls ?? null ) || ( $component->provider_id ?? null ) !== $proof->provider_id || ( $component->native_id ?? null ) !== $proof->native_id ) { continue; }
				foreach ( $component->controls ?? array() as $control ) {
					if ( self::public_control( $control ) ) { $controls[ $control->path ] = $control->value_schema; }
				}
			}
			$node = null;
			foreach ( $d->graph->nodes ?? array() as $candidate ) {
				if ( $c->encode( $candidate->locator ?? null ) === $c->encode( $proof->locator ) ) { $node = $candidate; break; }
			}
			if ( ! self::public_entity( $node ) || ! self::public_entity( $node->attributes ?? null ) ) { continue; }
			foreach ( $proof->paths as $path ) {
				$expected = ( new BuilderPromptSchema() )->compact( $proof->schemas->$path );
				if ( ! isset( $controls[ $path ] ) || $c->encode( $expected ) !== $c->encode( $controls[ $path ] ) ) { continue 2; }
				list( $present, $value ) = self::value( $node->attributes ?? null, $path );
				if ( $present !== $proof->present->$path || $c->encode( $value ) !== $c->encode( $proof->values->$path ) ) {
					continue 2; }
				if ( ! $present && 'elementor' === ( $d->graph->document->builder ?? null ) && ! self::elementor_unset_control( $d->graph, $node, $path, $proof->schemas->$path, $a->selected_area ) ) {
					continue 2; }
			}
			return $proof;
		}
		return null;
	}

	/** A new group, area, schema or value never counts as an unchanged reread. */
	public static function repeated( array $entries, array $projected, ?object $selection = null ): bool {
		if ( count( $entries ) < 2 ) { return false; }
		$latest = end( $entries );
		if ( ! $latest instanceof \stdClass ) { return false; }
		$current = self::visible( $latest, $projected, $selection );
		if ( null === $current ) { return false; }
		$c = new JsonCanonicalizer();
		foreach ( array_reverse( array_slice( $entries, 0, -1 ) ) as $entry ) {
			if ( ! $entry instanceof \stdClass || str_starts_with( $entry->call_id ?? '', 'server-prefetch-' ) || ( $entry->call_id ?? null ) === ( $latest->call_id ?? null ) ) { continue; }
			$prior = self::proof( $entry, $selection );
			if ( null === $prior || $c->encode( $prior->source ) !== $c->encode( $current->source ) || $c->encode( $prior->locator ) !== $c->encode( $current->locator ) || $c->encode( $prior->scope ) !== $c->encode( $current->scope ) ) { continue; }
			if ( array_diff( $current->paths, $prior->paths ) ) { continue; }
			foreach ( $current->paths as $path ) {
				if ( $prior->present->$path !== $current->present->$path || $c->encode( $prior->schemas->$path ) !== $c->encode( $current->schemas->$path ) || $c->encode( $prior->values->$path ) !== $c->encode( $current->values->$path ) ) {
					continue 2; }
			}
			return true;
		}
		return false;
	}

	/** Validate the actual native receipt, never an index or model-owned assertion. */
	private static function proof( object $entry, ?object $selection ): ?object {
		$d = $entry->response->data ?? null; $a = $d->authoring ?? null; $g = $d->graph ?? null;
		$i = is_string( $entry->input_json ?? null ) ? json_decode( $entry->input_json ) : ( $entry->input ?? null );
		if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $i instanceof \stdClass || true !== ( $i->include_authoring ?? null ) || ! $a instanceof \stdClass || ! $g instanceof \stdClass || 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) || true !== ( $a->draft_readiness->ready ?? null ) || ! in_array( 'set_control', $a->draft->operations ?? array(), true ) || ! empty( $a->missing_control_paths ) || ! empty( $a->ambiguous_control_paths ) || ! empty( $a->unavailable_requested_components ) || 'complete' !== ( $g->completeness ?? null ) || 'complete' !== ( $g->consumer_coverage ?? null ) || array() !== ( $g->omissions ?? null ) || true !== ( $d->semantic_report->accepted ?? null ) || 'current' !== ( $d->projection->status ?? null ) || ! is_array( $a->requested_control_paths ?? null ) || ! $a->requested_control_paths || count( $a->requested_control_paths ) > 200 || ! ( $a->selected_area->locator ?? null ) instanceof \stdClass || ! ( $a->selected_area->scope ?? null ) instanceof \stdClass ) { return null; }
		$c = new JsonCanonicalizer();
		try {
			if ( $c->digest( $g ) !== ( $d->graph_hash ?? null ) || ( new GraphVersion( (array) $g->version ) )->hash() !== ( $d->semantic_report->graph_version_hash ?? null ) || ( $d->fact_revision ?? null ) !== ( $d->semantic_report->fact_revision ?? null ) || ( $a->semantic_rule_set_revision ?? null ) !== ( $d->semantic_report->rule_set_revision ?? null ) || $c->encode( $i->document ?? null ) !== $c->encode( $g->document ) || $c->encode( $i->scope ?? null ) !== $c->encode( $a->selected_area->scope ) ) { return null; }
			if ( null !== $selection ) {
				// A descendant needs its OWN exact current read and the unchanged
				// native containment fence, never a sibling or foreign document.
				$target_id = null;
				foreach ( $g->nodes as $candidate ) { if ( $c->encode( $candidate->locator ?? null ) === $c->encode( $a->selected_area->locator ) ) { $target_id = $candidate->id; break; } }
				if ( null === $target_id ) { return null; }
				BuilderEditorSelection::assert_changes( json_encode( (object) array( 'builder_selection' => $selection ), JSON_THROW_ON_ERROR ), $g, array( (object) array( 'target_id' => $target_id ) ) );
			}
		} catch ( \Throwable $error ) { return null; }
		$node = null;
		foreach ( $g->nodes ?? array() as $candidate ) {
			if ( $c->encode( $candidate->locator ?? null ) === $c->encode( $a->selected_area->locator ) ) { $node = $candidate; break; }
		}
		if ( ! self::public_entity( $node ) || ! self::public_entity( $node->attributes ?? null ) || null !== ( $node->opaque_ref ?? null ) || $c->encode( $node->scope ?? null ) !== $c->encode( $a->selected_area->scope ) || ( $node->provider_id ?? null ) !== ( $a->selected_area->provider_id ?? null ) || ( $node->native_id ?? null ) !== ( $a->selected_area->native_id ?? null ) || ! is_array( $a->components ?? null ) ) { return null; }
		$ancestors = array( $node->id => true );
		for ( $pass = 0; $pass < count( $g->nodes ); ++$pass ) {
			$changed = false;
			foreach ( $g->edges ?? array() as $edge ) {
				if ( 'contains' === ( $edge->kind ?? null ) && null === ( $edge->target_document_key ?? null ) && isset( $ancestors[ $edge->target ?? '' ] ) && ! isset( $ancestors[ $edge->source ?? '' ] ) ) { $ancestors[ $edge->source ] = true; $changed = true; }
			}
			if ( ! $changed ) { break; }
		}
		foreach ( $g->nodes as $ancestor ) {
			if ( isset( $ancestors[ $ancestor->id ?? '' ] ) && ( ! self::public_entity( $ancestor ) || ! self::public_entity( $ancestor->attributes ?? null ) || null !== ( $ancestor->opaque_ref ?? null ) || 'native/opaque' === ( $ancestor->provider_id ?? null ) ) ) {
				return null; }
		}
		$schemas  = new \stdClass();
		$values   = new \stdClass();
		$presence = new \stdClass();
		$paths    = array_values( $a->requested_control_paths );
		if ( count( $paths ) !== count( array_unique( $paths, SORT_REGULAR ) ) ) {
			return null; }
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) ) { return null; }
			$matches = array();
			foreach ( $a->components as $component ) {
				if ( ! self::public_entity( $component ) || ! is_array( $component->controls ?? null ) || ( $component->provider_id ?? null ) !== $node->provider_id || ( $component->native_id ?? null ) !== $node->native_id ) { continue; }
				foreach ( $component->controls ?? array() as $control ) { if ( ( $control->path ?? null ) === $path && self::public_control( $control ) ) { $matches[] = $control; } }
			}
			list( $present, $value ) = self::value( $node->attributes ?? null, $path );
			if ( 1 !== count( $matches ) || ( ! $present && ! self::gutenberg_unset_style( $g, $node, $path, $matches[0]->value_schema ) && ! self::elementor_unset_control( $g, $node, $path, $matches[0]->value_schema, $a->selected_area ) ) ) {
				return null; }
			$schemas->$path  = $matches[0]->value_schema;
			$values->$path   = $value;
			$presence->$path = $present;
		}
		$source = (object) array();
		foreach ( array( 'document_key', 'graph_hash', 'opaque_hash', 'fact_revision' ) as $key ) {
			$source->$key = $d->$key ?? null; }
		$source->document                   = $g->document;
		$source->catalog_revision           = $a->catalog_revision;
		$source->semantic_rule_set_revision = $a->semantic_rule_set_revision;
		return (object) array(
			'source'      => $source,
			'locator'     => $node->locator,
			'scope'       => $node->scope,
			'provider_id' => $node->provider_id,
			'native_id'   => $node->native_id,
			'node_id'     => $node->id,
			'paths'       => $paths,
			'schemas'     => $schemas,
			'values'      => $values,
			'present'     => $presence,
		);
	}

	/**
	 * Only the native reader can witness an absent public scalar, never a filtered value.
	 *
	 * @param object $graph  Current native graph.
	 * @param object $node   Selected native node.
	 * @param string $path   Requested public control path.
	 * @param object $schema Admitted control schema.
	 * @param object $area   Fresh native selected-area proof.
	 * @return bool Whether the absence is witnessed.
	 */
	private static function elementor_unset_control( object $graph, object $node, string $path, object $schema, object $area ): bool {
		$unset = $area->native_unset_controls ?? null;
		return 'elementor' === ( $graph->document->builder ?? null )
			&& in_array( $schema->type ?? null, array( 'string', 'number', 'integer', 'boolean' ), true )
			&& is_array( $unset ) && array_is_list( $unset ) && count( $unset ) <= 200
			&& in_array( $path, $unset, true )
			&& ! in_array( $path, $node->attributes->native_bound_controls ?? array(), true );
	}

	/**
	 * The native style projection can explicitly verify an unset scalar override.
	 *
	 * @param object $graph  Current native graph.
	 * @param object $node   Selected native node.
	 * @param string $path   Requested public control path.
	 * @param object $schema Admitted control schema.
	 * @return bool Whether the override is unset.
	 */
	private static function gutenberg_unset_style( object $graph, object $node, string $path, object $schema ): bool {
		if ( 'gutenberg' !== ( $graph->document->builder ?? null ) || ! in_array( $schema->type ?? null, array( 'string', 'number', 'integer', 'boolean' ), true )
			|| ! preg_match( '/^(responsive_support_styles|design_elements)\.([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)$/D', $path, $parts ) ) {
			return false;
		}
		$family = 'responsive_support_styles' === $parts[1] ? 'support' : 'design';
		$groups = $node->attributes->native_style->{$family}->{$parts[2]} ?? null;
		$found  = array();
		foreach ( (array) $groups as $group ) {
			if ( isset( $group->{$parts[3]}->base ) ) {
				$found[] = $group->{$parts[3]}->base;
			}
		}
		return 1 === count( $found ) && false === ( $found[0]->present ?? null ) && property_exists( $found[0], 'value' ) && null === $found[0]->value;
	}

	private static function public_control( $control ): bool {
		return self::public_entity( $control ) && is_string( $control->path ?? null ) && false === ( $control->private ?? null ) && ( $control->value_schema ?? null ) instanceof \stdClass;
	}

	/** Explicit denial or malformed access metadata always overrides an index. */
	private static function public_entity( $value ): bool {
		if ( ! $value instanceof \stdClass ) { return false; }
		foreach ( array( 'private', 'read_only', 'writable' ) as $flag ) {
			if ( property_exists( $value, $flag ) && ( ! is_bool( $value->$flag ) || ( 'writable' === $flag ? ! $value->$flag : $value->$flag ) ) ) { return false; }
		}
		return true;
	}

	private static function value( $value, string $path ): array {
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! $value instanceof \stdClass || ! property_exists( $value, $part ) ) { return array( false,null ); }
			$value = $value->$part;
		}
		return array( true,$value );
	}
}
