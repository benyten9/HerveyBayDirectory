<?php
/**
 * Exact four-slot native Elementor factory binding for model presentation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Builder\BuilderAuthoringContract;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** This descriptor grants no native authority and never executes a proposal. */
final class BuilderElementorFactoryBinding {
	public const REVISION = 'elementor-four-node-factory-binding-v1';

	/**
	 * Describe one complete current native factory, with immutable structure.
	 *
	 * @param array $fresh_prefetched Fresh server-owned Gateway receipts only.
	 * @return object|null Closed descriptor, or the ordinary native-flow fallback.
	 */
	public static function describe( array $fresh_prefetched ): ?object {
		try {
			( new JsonDecoder( 131072, 48 ) )->check( $fresh_prefetched );
			$canonical = new JsonCanonicalizer();
			$anchors   = array();
			foreach ( $fresh_prefetched as $entry ) {
				if ( ! $entry instanceof \stdClass ) {
					return null; }
				if ( self::anchor( $entry, $canonical ) ) {
					$anchors[] = $entry; }
			}
			if ( 1 !== count( $anchors ) ) {
				return null; }
			$entry    = $anchors[0];
			$data     = $entry->response->data;
			$a        = $data->authoring;
			$f        = $a->draft->first_loop;
			$input    = json_decode( $entry->input_json );
			$identity = self::authority( $entry, $canonical );
			foreach ( $fresh_prefetched as $read ) {
				// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare two exact current server-owned authority encodings.
				if ( true === ( $read->response->ok ?? null ) && 'builder.inspect' === ( $read->response->operation ?? null ) && $identity !== self::authority( $read, $canonical ) ) {
					return null; }
			}
			$components = array();
			foreach ( $a->components as $component ) {
				$components[ $component->provider_id . '|' . $component->native_id ] = $component;
			}
			$slots = array();
			foreach ( $f->subtree->nodes as $node ) {
				$component = $components[ $node->provider_id . '|' . $node->native_id ];
				$schema    = 'directorist_listings_loop' === $node->native_id ? $f->carrier_attributes_schema : $component->insert_attributes_schema;
				if ( ! self::closed_schema( $schema ) ) {
					return null; }
				$slots[] = (object) array(
					'key'               => $node->client_id,
					'client_id'         => $node->client_id,
					'attributes_schema' => $schema,
				);
			}
			$authority = (object) array(
				'document_key'               => $data->document_key,
				'graph_hash'                 => $data->graph_hash,
				'fact_revision'              => $data->fact_revision,
				'catalog_revision'           => $a->catalog_revision,
				'semantic_rule_set_revision' => $a->semantic_rule_set_revision,
				'source_hash'                => $data->graph->version->source_hash,
				'graph_version'              => $data->graph->version,
				'first_loop_sha256'          => hash( 'sha256', $canonical->encode( $f ) ),
				'owner_ref'                  => $data->owner_ref ?? null,
				'site_id'                    => $data->site_id ?? null,
				'capture_id'                 => $data->capture_id ?? null,
				'root'                       => $f->parent,
			);
			$result    = (object) array(
				'template'  => (object) array(
					'document'     => $input->document,
					'directory_id' => $input->directory_id,
					'scope'        => $input->scope,
					'intent'       => 'private_page_draft',
					'first_loop'   => (object) array(
						'parent'   => $f->parent,
						'scope'    => $f->scope,
						'position' => $f->position,
						'subtree'  => $f->subtree,
					),
				),
				'slots'     => $slots,
				'authority' => $authority,
			);
			if ( property_exists( $f, 'defaults' ) ) {
				$result->defaults = $f->defaults; }
			( new JsonDecoder( 131072, 48 ) )->check( $result );
			return json_decode( json_encode( $result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return null; }
	}

	/**
	 * Exact current native identity; no model data can establish it.
	 *
	 * @param object            $entry Current server-owned native receipt.
	 * @param JsonCanonicalizer $canonical Exact native encoder.
	 * @return string|null Full current authority encoding.
	 */
	private static function authority( object $entry, JsonCanonicalizer $canonical ): ?string {
		$data = $entry->response->data ?? null;
		$g    = $data->graph ?? null;
		$i    = is_string( $entry->input_json ?? null ) ? json_decode( $entry->input_json ) : null;
		if ( true !== ( $entry->response->ok ?? null ) || 'builder.inspect' !== ( $entry->response->operation ?? null ) || ! $i instanceof \stdClass || true !== ( $i->include_authoring ?? null ) || ! property_exists( $i, 'editor_binding_id' ) || null !== $i->editor_binding_id || 'elementor' !== ( $g->document->builder ?? null ) || 'page' !== ( $g->document->native_kind ?? null ) || 'complete' !== ( $g->completeness ?? null ) || 'complete' !== ( $g->consumer_coverage ?? null ) || array() !== ( $g->omissions ?? null ) || true !== ( $data->semantic_report->accepted ?? null ) || null !== ( $g->version->editor_epoch ?? null ) || $canonical->encode( $i->document ?? null ) !== $canonical->encode( $g->document ) || ! is_string( $data->document_key ?? null ) || ! $data->document_key ) {
			return null; }
		if ( ! $g->version instanceof \stdClass ) {
			return null; }
		foreach ( array( 'adapter_revision', 'directory_schema_hash', 'editor_epoch', 'editor_revision', 'environment_hash', 'normalization_revision', 'source_hash' ) as $field ) {
			if ( ! property_exists( $g->version, $field ) ) {
				return null; }
		}
		$hashes = array( $data->graph_hash ?? null, $data->fact_revision ?? null, $data->authoring->catalog_revision ?? null, $data->authoring->semantic_rule_set_revision ?? null, $g->version->source_hash ?? null );
		foreach ( $hashes as $hash ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				return null; }
		}
		return $canonical->encode( array( $data->document_key, $g, $hashes, $i->document, $i->directory_id ?? null, $i->scope ?? null, $i->editor_binding_id, $data->owner_ref ?? null, $data->site_id ?? null, $data->capture_id ?? null ) );
	}

	/**
	 * Only the current declared four-node root-append ordinary-page recipe.
	 *
	 * @param object            $entry Current server-owned native receipt.
	 * @param JsonCanonicalizer $canonical Exact native encoder.
	 * @return bool Whether this exact native factory is ready.
	 */
	private static function anchor( object $entry, JsonCanonicalizer $canonical ): bool {
		if ( null === self::authority( $entry, $canonical ) ) {
			return false; }
		$a = $entry->response->data->authoring;
		$g = $entry->response->data->graph;
		$i = json_decode( $entry->input_json );
		$f = $a->draft->first_loop ?? null;
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Canonical native values are compared exactly, without rewriting identity or scope.
		if ( 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) || true !== ( $a->draft_readiness->ready ?? null ) || 'elementor_native_root_layout_v1' !== ( $a->draft->creation_mode ?? null ) || array( 'insert' ) !== ( $a->draft->operations ?? null ) || ! empty( $a->unavailable_requested_components ) || ! BuilderAuthoringContract::has_actionable_contract( $a ) || ! empty( $i->control_paths ) || ! is_array( $i->selection ?? null ) || 1 !== count( $i->selection ) || ! is_int( $i->directory_id ?? null ) || $i->directory_id < 1 || (string) $i->directory_id !== ( $i->scope->directory_id ?? null ) || null !== ( $i->scope->view ?? null ) || null !== ( $i->scope->breakpoint_id ?? null ) || 'normal' !== ( $i->scope->interaction ?? null ) || 1 !== ( $f->max_changes ?? null ) || 16 !== ( $f->max_insert_nodes ?? null ) || 'directorist_listings_loop' !== ( $f->carrier_native_id ?? null ) || ! is_int( $f->position ?? null ) || $f->position < 0 || $canonical->encode( $f->parent ?? null ) !== $canonical->encode( $i->selection[0] ) || $canonical->encode( $f->scope ?? null ) !== $canonical->encode( $i->scope ) || array( 'document' ) !== ( $f->parent->slot_path ?? null ) || ! is_array( $f->subtree->nodes ?? null ) || 4 !== count( $f->subtree->nodes ) || ! is_array( $f->subtree->edges ?? null ) || 3 !== count( $f->subtree->edges ) || ! ( $f->carrier_attributes_schema ?? null ) instanceof \stdClass ) {
			return false; }
		if ( isset( $a->draft->intent ) && 'private_page_draft' !== $a->draft->intent ) {
			return false; }
		$roots = array();
		foreach ( $g->nodes ?? array() as $node ) {
			if ( in_array( $node->native_id ?? null, array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) ) {
				return false; }
			if ( 'directorist-ai' === ( $node->provider_id ?? null ) && 'elementor-document' === ( $node->native_id ?? null ) && null === ( $node->opaque_ref ?? null ) ) {
				$roots[] = $node; }
		}
		if ( 1 !== count( $roots ) || $canonical->encode( $roots[0]->locator ?? null ) !== $canonical->encode( $f->parent ) || $canonical->encode( $roots[0]->scope ?? null ) !== $canonical->encode( $f->scope ) ) {
			return false; }
		$children = array_filter( $g->edges ?? array(), static fn( object $e ): bool => 'contains' === ( $e->kind ?? null ) && ( $e->source ?? null ) === $roots[0]->id && null === ( $e->target_document_key ?? null ) );
		if ( count( $children ) !== $f->position ) {
			return false; }
		$expected   = array( 'directorist-elementor|directorist_listings_loop', 'elementor/core|container', 'elementor/core|heading', 'elementor/core|text-editor' );
		$components = array();
		foreach ( $a->components ?? array() as $c ) {
			$key = ( $c->provider_id ?? '' ) . '|' . ( $c->native_id ?? '' );
			if ( isset( $components[ $key ] ) || ! in_array( $key, $expected, true ) || false !== ( $c->saved_instance_available ?? null ) || ! in_array( $c->availability ?? null, array( 'available', 'read_only' ), true ) || 'new_component_insertion' !== ( $c->authoring_role ?? null ) || ! ( $c->insert_attributes_schema ?? null ) instanceof \stdClass ) {
				return false; }
			$components[ $key ] = true;
		}
		$keys = array_keys( $components );
		sort( $keys );
		if ( $keys !== $expected ) {
			return false; }
		$ids   = array();
		$types = array();
		foreach ( $f->subtree->nodes as $node ) {
			if ( ! $node instanceof \stdClass || ! is_string( $node->client_id ?? null ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $node->client_id ) || isset( $ids[ $node->client_id ] ) || ! ( $node->attributes ?? null ) instanceof \stdClass ) {
				return false; }
			$ids[ $node->client_id ] = $node;
			$types[]                 = ( $node->provider_id ?? '' ) . '|' . ( $node->native_id ?? '' );
		}
		sort( $types );
		if ( $types !== $expected || ! isset( $ids[ $f->subtree->root_client_id ?? '' ] ) || 'container' !== $ids[ $f->subtree->root_client_id ]->native_id ) {
			return false; }
		$positions = array();
		foreach ( $f->subtree->edges as $e ) {
			if ( 'contains' !== ( $e->kind ?? null ) || ( $e->source_client_id ?? null ) !== $f->subtree->root_client_id || ! isset( $ids[ $e->target_client_id ?? '' ] ) || ! is_int( $e->order ?? null ) || isset( $positions[ $e->order ] ) ) {
				return false; }
			$positions[ $e->order ] = $ids[ $e->target_client_id ];
		}
		ksort( $positions );
		if ( array_keys( $positions ) !== array( 0, 1, 2 ) || 'heading' !== $positions[0]->native_id || 'text-editor' !== $positions[1]->native_id || 'directorist_listings_loop' !== $positions[2]->native_id ) {
			return false; }
		$attrs = $positions[2]->attributes;
		return array( 'native_loop_recipe' ) === array_keys( get_object_vars( $attrs ) ) && in_array( $attrs->native_loop_recipe ?? null, array( 'directory_grid', 'directory_list' ), true );
	}

	/**
	 * Standalone native schemas only; shared transport embeds them safely.
	 *
	 * @param object $schema Complete native attribute schema.
	 * @return bool Whether its exact standalone refs can be embedded safely.
	 */
	private static function closed_schema( object $schema ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Exact JSON Schema keyword.
		if ( 'object' !== ( $schema->type ?? null ) || false !== ( $schema->additionalProperties ?? null ) || ! ( $schema->properties ?? null ) instanceof \stdClass ) {
			return false; }
		( new JsonDecoder( 65536, 32 ) )->check( $schema );
		return null !== BuilderOneNodeFactoryBinding::embed_schema( $schema, 'elementor_guard' );
	}
}
