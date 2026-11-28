<?php
/**
 * Read-only semantic orientation for a large current native Divi document.
 *
 * @package DirectoristAIPro
 */
namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Validation\JsonCanonicalizer;

/** Distinguish indexed containers without granting controls or selecting a target. */
final class BuilderDocumentDiscoveryPrompt {
	private const MAX_BYTES = 6144;

	/** Summarize only complete, same-document native containment and public text. */
	public static function from_graph( object $graph, array $indexed_ids, object $scope, array $native_ids = array( 'divi/section' ) ): ?object {
		if ( 'divi' !== ( $graph->document->builder ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || array() !== ( $graph->omissions ?? null ) || ! is_array( $graph->nodes ?? null ) || ! is_array( $graph->edges ?? null ) || count( $graph->nodes ) > 1024 || count( $graph->edges ) > 4096 ) { return null; }
		if ( ! array_is_list( $indexed_ids ) || array_filter( $indexed_ids, static fn( $id ): bool => ! is_string( $id ) ) || count( array_unique( $indexed_ids ) ) !== count( $indexed_ids ) ) { return null; }
		$canonical = new JsonCanonicalizer();
		$nodes = array(); $children = array(); $parents = array();
		foreach ( $graph->nodes as $node ) {
			if ( ! $node instanceof \stdClass || ! is_string( $node->id ?? null ) || '' === $node->id || strlen( $node->id ) > 256 || isset( $nodes[ $node->id ] ) || ! ( $node->locator ?? null ) instanceof \stdClass || ! ( $node->scope ?? null ) instanceof \stdClass || $canonical->encode( $node->locator->document ?? null ) !== $canonical->encode( $graph->document ) ) { return null; }
			$nodes[ $node->id ] = $node;
		}
		foreach ( $graph->edges as $edge ) {
			if ( ! $edge instanceof \stdClass || ! is_string( $edge->kind ?? null ) ) { return null; }
			if ( 'contains' !== $edge->kind || null !== ( $edge->target_document_key ?? null ) ) { continue; }
			if ( ! is_string( $edge->source ?? null ) || ! is_string( $edge->target ?? null ) || ! isset( $nodes[ $edge->source ], $nodes[ $edge->target ] ) || ( isset( $parents[ $edge->target ] ) && $parents[ $edge->target ] !== $edge->source ) ) { return null; }
			$parents[ $edge->target ] = $edge->source;
			$children[ $edge->source ][ $edge->target ] = true;
		}
		$out = (object) array(
			'kind' => 'current_document_container_orientation',
			'partial' => true,
			'editable_values' => false,
			'full_graph_included' => false,
			'source_indexed_children' => count( $indexed_ids ),
			'returned_children' => 0,
			'truncated' => false,
			'containers' => array(),
			'note' => 'Read-only orientation from the same complete native graph. Node IDs reference the exact indexed locators and scopes; they do not select a target or grant any control. Counts describe same-document native containment only, not visual emptiness or defaults. Text samples identify saved public content only. Missing text or omitted descendants are unknown. Identify the intended region, then inspect its exact matching-scope locator for current control schemas and values before any proposal. Preserve unrelated regions.',
		);
		foreach ( $indexed_ids as $id ) {
			if ( ! is_string( $id ) || ! isset( $nodes[ $id ] ) ) { return null; }
			$node = $nodes[ $id ];
			if ( 'divi/core' !== ( $node->provider_id ?? null ) || ! in_array( $node->native_id ?? null, $native_ids, true ) || self::private_node( $node ) || $canonical->encode( $node->scope ) !== $canonical->encode( $scope ) ) { continue; }
			$seen = array( $id => true ); $text_blocked = array( $id => false ); $queue = array_keys( $children[ $id ] ?? array() ); $descendants = array();
			for ( $at = 0; $at < count( $queue ); ++$at ) {
				$child = $queue[ $at ];
				if ( isset( $seen[ $child ] ) ) { return null; }
				$seen[ $child ] = true; $descendants[] = $child;
				$text_blocked[ $child ] = ! empty( $text_blocked[ $parents[ $child ] ] ) || self::private_node( $nodes[ $child ] ) || $canonical->encode( $nodes[ $child ]->scope ) !== $canonical->encode( $scope ) || ! empty( $nodes[ $child ]->bindings );
				foreach ( array_keys( $children[ $child ] ?? array() ) as $next ) { $queue[] = $next; }
			}
			$summary = (object) array( 'node_id' => $id, 'direct_native_children' => count( $children[ $id ] ?? array() ), 'descendant_nodes' => count( $descendants ), 'has_native_descendants' => ! empty( $descendants ), 'text_roles' => (object) array( 'public_text_modules' => 0, 'headings' => 0, 'body_text_runs' => 0 ), 'heading_samples' => array(), 'text_summary_partial' => false );
			foreach ( $descendants as $child_id ) {
				$child = $nodes[ $child_id ];
				if ( ! empty( $text_blocked[ $child_id ] ) ) { $summary->text_summary_partial = true; continue; }
				if ( 'divi/core' !== ( $child->provider_id ?? null ) || 'divi/text' !== ( $child->native_id ?? null ) ) { continue; }
				if ( self::private_node( $child ) || $canonical->encode( $child->scope ) !== $canonical->encode( $scope ) || ! empty( $child->bindings ) ) { $summary->text_summary_partial = true; continue; }
				$html = $child->attributes->content->innerContent->desktop->value ?? null;
				$text = self::text_roles( $html );
				if ( null === $text ) { $summary->text_summary_partial = true; continue; }
				++$summary->text_roles->public_text_modules;
				$summary->text_roles->headings += $text->headings;
				$summary->text_roles->body_text_runs += $text->body_text_runs;
				foreach ( $text->heading_samples as $sample ) { if ( count( $summary->heading_samples ) < 3 ) { $summary->heading_samples[] = $sample; } else { $summary->text_summary_partial = true; } }
			}
			$out->containers[] = $summary;
			if ( count( $out->containers ) > 12 || strlen( json_encode( $out, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) > self::MAX_BYTES - 128 ) { array_pop( $out->containers ); $out->truncated = true; break; }
		}
		$out->returned_children = count( $out->containers );
		$out->truncated = $out->truncated || $out->returned_children < $out->source_indexed_children;
		return $out->containers && strlen( json_encode( $out, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) <= self::MAX_BYTES ? $out : null;
	}

	/** Private/opaque native nodes never contribute text or a misleading empty label. */
	private static function private_node( object $node ): bool {
		return true === ( $node->private ?? null ) || true === ( $node->attributes->private ?? null ) || null !== ( $node->opaque_ref ?? null ) || 'native/opaque' === ( $node->provider_id ?? null );
	}

	/** Parse only visible static text roles; copy no attributes, URLs or raw HTML. */
	private static function text_roles( $html ): ?object {
		if ( ! is_string( $html ) || '' === $html || strlen( $html ) > 16384 || ! class_exists( \DOMDocument::class ) || str_contains( $html, '[et_pb_' ) || str_contains( $html, '$variable(' ) ) { return null; }
		$dom = new \DOMDocument();
		if ( ! $dom->loadHTML( '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) { return null; }
		$xpath = new \DOMXPath( $dom );
		$hidden = $xpath->query( '//script|//style|//template|//form|//input|//textarea|//select|//*[@hidden]|//*[@aria-hidden="true"]' );
		for ( $i = $hidden->length - 1; $i >= 0; --$i ) { $item = $hidden->item( $i ); if ( $item->parentNode ) { $item->parentNode->removeChild( $item ); } }
		$out = (object) array( 'headings' => 0, 'body_text_runs' => 0, 'heading_samples' => array() );
		foreach ( $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $heading ) {
			$text = preg_replace( '/\s+/u', ' ', trim( $heading->textContent ) );
			if ( ! is_string( $text ) || '' === $text ) { continue; }
			++$out->headings;
			if ( count( $out->heading_samples ) < 3 ) { $out->heading_samples[] = (object) array( 'level' => (int) substr( $heading->nodeName, 1 ), 'text' => mb_strcut( $text, 0, 120, 'UTF-8' ) ); }
		}
		$body = $xpath->query( '//body//text()[normalize-space() != "" and not(ancestor::h1 or ancestor::h2 or ancestor::h3 or ancestor::h4 or ancestor::h5 or ancestor::h6)]' );
		$out->body_text_runs = $body->length;
		return $out;
	}
}
