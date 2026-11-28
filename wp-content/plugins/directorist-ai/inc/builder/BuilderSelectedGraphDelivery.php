<?php
/**
 * Bounded selected-area delivery of an already verified private native graph.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable WordPress.Files.FileName -- Native PSR-4 class name.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** A wire projection never replaces the complete private graph or its authority. */
final class BuilderSelectedGraphDelivery {

	/**
	 * Omit only unrelated derived style preimages on an oversized delivery copy.
	 *
	 * The caller must resolve selection from its server-owned conversation and run
	 * all native, semantic and authoring checks against the original full graph.
	 * Invoke only for responses exceeding the existing delivery byte bound; small
	 * partial/private-copy reads retain their ordinary response and bypass this
	 * stricter complete-graph projection boundary.
	 * Complete selected descendants, ancestors and context providers retain values.
	 * No source/graph authority, node, edge, locator or saved attribute is changed.
	 *
	 * @param object $response Complete server-produced inspect response.
	 * @param object $selected Exact server-resolved node in the private graph.
	 * @return object Detached delivery response under the existing transport bound.
	 * @throws ContractException Invalid private source or an oversized kept area.
	 */
	public static function project( object $response, object $selected ): object {
		$canonical = new JsonCanonicalizer();
		$graph     = $response->graph ?? null;
		if ( ! $response instanceof \stdClass || ! $graph instanceof \stdClass || ! $selected instanceof \stdClass || ! is_array( $graph->nodes ?? null ) || ! is_array( $graph->edges ?? null ) || count( $graph->nodes ) > 2000 || count( $graph->edges ) > 5000 || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || true !== ( $response->semantic_report->accepted ?? null ) || ! ( $response->projection ?? null ) instanceof \stdClass || isset( $response->projection->selected_graph_delivery ) ) {
			self::source_failure();
		}
		// Same internal bounds as BuilderGraphResult and GraphSnapshotStore.
		( new JsonDecoder( 5242880, 32 ) )->check( $graph );
		$envelope = clone $response;
		unset( $envelope->graph );
		( new JsonDecoder() )->check( $envelope );
		$private_hash = $response->graph_hash ?? null;
		if ( ! is_string( $private_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $private_hash ) || ! hash_equals( $private_hash, $canonical->digest( $graph ) ) ) {
			self::source_failure();
		}
		$nodes = array();
		foreach ( $graph->nodes as $node ) {
			if ( ! $node instanceof \stdClass || ! is_string( $node->id ?? null ) || isset( $nodes[ $node->id ] ) || ! ( $node->attributes ?? null ) instanceof \stdClass ) {
				self::source_failure();
			}
			$nodes[ $node->id ] = $node;
		}
		$id = $selected->id ?? null;
		if ( ! is_string( $id ) || ! isset( $nodes[ $id ] ) || $canonical->encode( $selected ) !== $canonical->encode( $nodes[ $id ] ) ) {
			self::source_failure();
		}
		$copy        = self::copy( $envelope );
		$copy->graph = self::copy( $graph );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Same pure JSON byte accounting as JsonDecoder, without WordPress coercion.
		if ( strlen( json_encode( $copy, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) <= 1048576 ) {
			return ( new JsonDecoder() )->check( $copy );
		}
		$children  = array();
		$parents   = array();
		$providers = array();
		foreach ( $graph->edges as $edge ) {
			if ( ! $edge instanceof \stdClass || ! is_string( $edge->source ?? null ) || ! is_string( $edge->target ?? null ) ) {
				self::source_failure();
			}
			if ( null !== ( $edge->target_document_key ?? null ) ) {
				continue;
			}
			if ( 'contains' === ( $edge->kind ?? null ) || 'context' === ( $edge->kind ?? null ) ) {
				if ( ! isset( $nodes[ $edge->source ], $nodes[ $edge->target ] ) ) {
					self::source_failure();
				}
				if ( 'contains' === $edge->kind ) {
					$children[ $edge->source ][] = $edge->target;
					$parents[ $edge->target ][]  = $edge->source;
				} else {
					$providers[ $edge->target ][] = $edge->source;
				}
			}
		}
		$keep    = array( $id => true );
		$pending = array( $id );
		while ( $pending ) {
			$current = array_pop( $pending );
			foreach ( $children[ $current ] ?? array() as $child ) {
				if ( ! isset( $keep[ $child ] ) ) {
					$keep[ $child ] = true;
					$pending[]      = $child;
				}
			}
		}
		$pending = array_keys( $keep );
		while ( $pending ) {
			$current = array_pop( $pending );
			foreach ( array_merge( $parents[ $current ] ?? array(), $providers[ $current ] ?? array() ) as $ancestor ) {
				if ( ! isset( $keep[ $ancestor ] ) ) {
					$keep[ $ancestor ] = true;
					$pending[]         = $ancestor;
				}
			}
		}
		$omitted = array();
		foreach ( $copy->graph->nodes as $node ) {
			if ( ! isset( $keep[ $node->id ] ) && property_exists( $node->attributes, 'native_style' ) ) {
				unset( $node->attributes->native_style );
				$omitted[] = $node->id;
			}
		}
		$retained = array_keys( $keep );
		sort( $retained, SORT_STRING );
		sort( $omitted, SORT_STRING );
		$copy->projection->selected_graph_delivery = (object) array(
			'revision'               => 'selected-derived-style-delivery-v1',
			'selected_node_id'       => $id,
			'private_graph_hash'     => $private_hash,
			'wire_graph_hash'        => $canonical->digest( $copy->graph ),
			'retained_node_ids'      => $retained,
			'omitted_style_node_ids' => $omitted,
			'note'                   => 'Only derived native_style values outside the selected closure, its ancestors and context providers are omitted for delivery. Every physical/scoped identity, locator, edge and other attribute remains. Private graph/source hashes stay authoritative; the wire digest is presentation only. Missing values grant no write permission. Native planning and cold compilation re-read the complete private source.',
		);
		try {
			return ( new JsonDecoder() )->check( $copy );
		} catch ( ContractException $error ) {
			throw new ContractException( 'invalid_output', 'The selected area is too large for a complete bounded inspection. Select a smaller editable area.', array( 'selected_graph_delivery_limit' ) );
		}
	}

	/**
	 * Detach verified plain JSON metadata, preserving object/list identity.
	 *
	 * @param object $value Already bounded plain server metadata.
	 * @return object Detached metadata without references into private authority.
	 */
	private static function copy( object $value ): object {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Already verified plain JSON; preserve object/list identity without WordPress bootstrap.
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 33, JSON_THROW_ON_ERROR );
	}

	/**
	 * A projection cannot promote an unknown or modified graph into authority.
	 *
	 * @return never
	 * @throws ContractException Invalid trusted source for selected delivery.
	 */
	private static function source_failure(): void {
		throw new ContractException( 'evidence_stale', 'The complete private graph could not be verified for selected-area delivery.', array( 'selected_graph_delivery_source' ) );
	}
}
