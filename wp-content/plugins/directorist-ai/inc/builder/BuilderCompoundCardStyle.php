<?php
/**
 * Read preferences and completeness checks; native write authority stays in the adapters.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact internal native contract helpers.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\{ContractException, JsonCanonicalizer};

/** Native target discovery is separate from user intent and native write authorization. */
final class BuilderCompoundCardStyle {
	/** Conservative optional fast path. Unrecognized language/goals retain ordinary model discovery. */
	public static function requested( string $text ): bool {
		$text    = BuilderStyleIntent::user_text( $text );
		$clauses = preg_split( '/[.!?;\n]+|(?:\band|\bbut|,)\s+(?=(?:keep|preserve|retain|leave)\b)/i', $text );
		// Discard preservation clauses only when they contain no separate action.
		// "Keep fields, bind Price, exactly as it is" is still a binding request.
		$text = implode( ' ', array_filter( $clauses, static fn( string $clause ): bool => ! preg_match( '/^\s*(?:keep|preserve|retain|leave)\b/i', $clause ) || preg_match( '/\b(?:add|insert|delete|remove|reorder|duplicate|bind|rebind|connect|map|replace|change|update)\b/i', $clause ) ) );
		if ( preg_match( '/\b(?:add|insert|delete|remove|reorder|duplicate|bind|rebind|connect|replace|fields?|quer(?:y|ies)|search|filters?|sorting|pagination|links?|urls?|paragraphs?|headings?|fonts?|typography|sizes?|padding|margins?|borders?|radius|corners?|shadows?|responsive|mobile|tablet|directories|variants?|views?|grid|list|map)\b/i', $text ) ) {
			return false; }
		$title_color = false;
		foreach ( preg_split( '/[.!?;\n,]+|\band\b|\bbut\b/i', $text ) as $clause ) {
			if ( ! preg_match( '/\b(?:unchanged|backgrounds?)\b/i', $clause ) && preg_match( '/\btitles?\b/i', $clause ) && preg_match( '/\b(?:colou?rs?|accent|burgundy|red|blue|green|navy|wine|teal|purple|black|white)\b/i', $clause ) ) {
				$title_color = true; }
		}
		return $title_color && (bool) preg_match( '/\b(?:cards?\b[^.!?;]{0,45}\bbackgrounds?|backgrounds?\b[^.!?;]{0,45}\bcards?)\b/i', $text );
	}

	/** One physical card and its actual titles; no encoded/inactive designs or search-field titles. */
	public static function targets( object $graph ): array {
		$builder     = $graph->document->builder ?? null;
		$definitions = array(
			'gutenberg' => array( 'directorist-gb-integration/listing-card-template', 'directorist-gb-integration/listing-card-title', array( 'design_elements.cardWrapper.backgroundColor' ), array( 'responsive_support_styles.support.textColor' ) ),
			'divi'      => array( 'directorist/listings-card', 'directorist/listings-title', array( 'listings_style.decoration.background.desktop.value.color', 'featured_listings_style.decoration.background.desktop.value.color' ), array( 'title_font.decoration.font.font.desktop.value.color' ) ),
		);
		if ( ! isset( $definitions[ $builder ] ) || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) ) {
			return array(); }
		list( $card, $title, $surface_paths, $title_paths ) = $definitions[ $builder ];
		$physical = static fn( $node ): bool => null === ( $node->opaque_ref ?? null ) && (bool) preg_match( '#^content/i(?:0|[1-9][0-9]*)(?:/innerBlocks/i(?:0|[1-9][0-9]*))*$#D', implode( '/', $node->locator->slot_path ?? array() ) );
		$cards    = array_values( array_filter( $graph->nodes ?? array(), static fn( $n ): bool => $n->native_id === $card && $physical( $n ) ) );
		if ( count( $cards ) !== 1 ) {
			return array(); }
		$root    = $cards[0];
		$path    = $root->locator->slot_path;
		$targets = array(
			array(
				'kind'  => 'card_surface',
				'node'  => $root,
				'paths' => $surface_paths,
			),
		);
		foreach ( $graph->nodes as $node ) {
			if ( $node->native_id === $title && $physical( $node ) && count( $node->locator->slot_path ) > count( $path ) && array_slice( $node->locator->slot_path, 0, count( $path ) ) === $path && $node->scope->directory_id === $root->scope->directory_id && ! ( $node->attributes->directorist_search_context ?? false ) ) {
				$targets[] = array(
					'kind'  => 'card_title',
					'node'  => $node,
					'paths' => $title_paths,
				);
			}
		}
		return count( $targets ) > 1 && array_sum( array_map( static fn( $t ): int => count( $t['paths'] ), $targets ) ) <= 8 ? $targets : array();
	}

	/** Native authoring receipts must contain every exact requested public schema, at one source version. */
	public static function ready( array $entries, string $prefix = 'server-prefetch-builder.compound-' ): bool {
		$reads = array_values( array_filter( $entries, static fn( $e ): bool => str_starts_with( $e->call_id ?? '', $prefix ) ) );
		if ( count( $reads ) < 2 || count( $reads ) !== ( $reads[0]->compound_expected ?? null ) ) {
			return false; }
		$canonical = new JsonCanonicalizer();
		$version   = null;
		foreach ( $reads as $read ) {
			$d = $read->response->data ?? null;
			$a = $d->authoring ?? null;
			$i = json_decode( $read->input_json ?? '{}' );
			if ( true !== ( $read->response->ok ?? null ) || true !== ( $d->semantic_report->accepted ?? null ) || 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) || ! empty( $a->missing_control_paths ) || ! empty( $a->ambiguous_control_paths ) || empty( $i->control_paths ) ) {
				return false; }
			$v = $canonical->encode( array( $d->graph->document ?? null, $d->graph->version ?? null, $a->catalog_revision ?? null, $a->semantic_rule_set_revision ?? null ) );
			if ( null !== $version && $version !== $v ) {
				return false;
			} $version = $v;
			$paths     = array();
			foreach ( $a->components ?? array() as $component ) {
				foreach ( $component->controls ?? array() as $control ) {
					if ( false === ( $control->private ?? null ) && empty( $control->read_only ) && ( $control->value_schema ?? null ) instanceof \stdClass ) {
						$paths[] = $control->path; }
				}
			}
			if ( array_diff( $i->control_paths, $paths ) ) {
				return false; }
		}
		return true;
	}

	/** A physical active title can narrow a page's null view, never its directory/device/state. */
	public static function narrow_control_scope( object $graph, object $target, object $scope, object $requested_scope, ?string $path = null ): bool {
		$canonical = new JsonCanonicalizer();
		if ( null !== ( $requested_scope->view ?? null ) || ! in_array( $scope->view ?? null, array( 'grid', 'list', 'map' ), true ) ) {
			return false; }
		$base       = clone $scope;
		$base->view = null;
		if ( $canonical->encode( $base ) !== $canonical->encode( $requested_scope ) ) {
			return false; }
		foreach ( self::targets( $graph ) as $entry ) {
			if ( 'card_title' === $entry['kind'] && $canonical->encode( $target ) === $canonical->encode( $entry['node']->locator ) && $canonical->encode( $scope ) === $canonical->encode( $entry['node']->scope ) && ( null === $path || in_array( $path, $entry['paths'], true ) ) ) {
				return true; }
		}
		return false;
	}

	/** Read-only authoring narrows an unselected page to its physical active title. Binding never changes. */
	public static function narrow_read_scope( object $binding, object $graph, object $scope, array $selection ): bool {
		return ! isset( $binding->builder_selection ) && ( $binding->builder_scope ?? null ) instanceof \stdClass && count( $selection ) === 1 && $selection[0] instanceof \stdClass && self::narrow_control_scope( $graph, $selection[0], $scope, $binding->builder_scope );
	}

	/** This can only reject an incomplete proposal; it cannot authorize any native control. */
	public static function assert_changes( object $graph, array $changes, string $text ): void {
		if ( ! self::requested( $text ) ) {
			return; }
		$targets = self::targets( $graph );
		if ( ! $targets ) {
			return; }
		$canonical = new JsonCanonicalizer();
		foreach ( $targets as $target ) {
			$covered = array();
			foreach ( $changes as $change ) {
				if ( 'set_control' === ( $change->op ?? null ) && ( ( $change->target_id ?? null ) === $target['node']->id || ( isset( $change->target ) && $canonical->encode( $change->target ) === $canonical->encode( $target['node']->locator ) ) ) ) {
					$covered[] = $change->control_path ?? ''; }
			}
			if ( array_diff( $target['paths'], $covered ) ) {
				throw new ContractException( 'invalid_input', 'The request asks for card backgrounds AND listing title colors. Include every supplied compound target/control in one set_control proposal; do not submit background-only saved variants. No partial change was prepared.', array( 'complete_requested_card_styles' ) ); }
		}
	}

	public static function assert_run( Database $db, Context $context, object $graph, object $input ): void {
		if ( 'builder' !== $context->profile() || null === $context->run_id() || null === $context->conversation_id() ) {
			return; }
		$rows = $db->rows( 'SELECT r.checkpoint_json,c.context_json FROM ' . $db->table( 'runs' ) . ' r JOIN ' . $db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s', array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference() ) );
		if ( count( $rows ) !== 1 ) {
			return; }
		$binding = json_decode( $rows[0]['context_json'] );
		if ( isset( $binding->builder_selection ) ) {
			return; }
		$checkpoint = json_decode( $rows[0]['checkpoint_json'] );
		$text       = (string) ( $checkpoint->reply->text ?? $checkpoint->intent->text ?? '' );
		if ( 'page' === ( $graph->document->native_kind ?? null ) && BuilderPageRedesign::requested( $text ) ) {
			$input->changes = BuilderPageRedesign::bind_native_scopes( $graph, $input->scope, $input->changes );
			BuilderPageRedesign::assert_complete( $graph, $input->changes, isset( $input->directory_query ) );
			return;
		}
		// Enforce full coverage only after the gateway confirmed that every
		// requested native control is public and available for this turn.
		// An unavailable optional title control keeps ordinary omission behavior.
		if ( 'compound_complete' !== ( $checkpoint->builder_page_card_prefetch->stage ?? null ) ) {
			return; }
		self::assert_changes( $graph, $input->changes, $text );
	}
}
