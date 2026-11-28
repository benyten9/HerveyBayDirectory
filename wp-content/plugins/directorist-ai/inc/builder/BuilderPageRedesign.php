<?php
/**
 * Target and completeness rules for a heading, search button and card edit.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Internal native contract helpers.

use DirectoristAIPro\Validation\{ContractException, JsonCanonicalizer};

/** Resolve the native controls for a single whole-page redesign. */
final class BuilderPageRedesign {
	private const CONTROLS = array(
		'gutenberg' => array(
			'heading' => array( 'core/heading', array( 'content' ) ),
			'search'  => array( 'directorist-gb-integration/search-submit-button', array( 'design_elements.button.backgroundColor' ) ),
			'card'    => array( 'directorist-gb-integration/listing-card-template', array( 'design_elements.cardWrapper.backgroundColor' ) ),
			'loops'   => array( 'directorist-gb-integration/listings-loop' ),
		),
		'elementor' => array(
			'heading' => array( 'heading', array( 'title' ) ),
			'search'  => array( 'directorist_search_submit_button', array( 'native_controls.button_color' ) ),
			'card'    => array( 'directorist_listing_card_template', array( 'template_container_background_color' ) ),
			'loops'   => array( 'directorist_listings_loop' ),
		),
		'divi'      => array(
			'heading' => array( 'divi/text', array( 'content.innerContent.desktop.value' ) ),
			'search'  => array( 'directorist/homepage-search', array( 'submit_button_background_color.innerContent.desktop.value' ) ),
			'card'    => array( 'directorist/listings-card', array( 'listings_style.decoration.background.desktop.value.color', 'featured_listings_style.decoration.background.desktop.value.color' ) ),
			'loops'   => array( 'directorist/listings-archive', 'directorist/homepage-search-loop' ),
		),
	);

	/** Opt in only for the supported three goals on the ordinary active page. */
	public static function requested( string $text ): bool {
		$text = BuilderStyleIntent::user_text( $text );
		$text = preg_replace( '/“[^”]*”|"[^"]*"/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/(?:^|(?<=[.!?;])\s*|,\s*|\b(?:and|but)\s+)(?:keep|preserve|leave|retain)\b.*?(?=[.!?;\n]|\b(?:and|but|then)\s+(?:add|insert|remove|change|apply|replace|update|adjust|show|make|bind|rebind|connect|map)\b|$)/is', ' ', $text );
		return preg_match( '/\b(?:rename|change|update|rewrite|set|replace)\b[^.!?;,]{0,45}\b(?:heading|headline)\b/i', $text )
			&& preg_match( '/\b(?:search|submit)\s+button\b/i', $text )
			&& preg_match( '/\b(?:listing\s+cards?\b[^.!?;]{0,65}\bbackgrounds?|backgrounds?\b[^.!?;]{0,45}\blisting\s+cards?)\b/i', $text )
			&& ! preg_match( '/\b(?:add|insert|remove|delete|reorder|bind|rebind|connect|map|queries|query|filters?|fields?|pagination|responsive|mobile|tablet|directories|variants?|(?:grid|list|map)\s+views?|fonts?|padding|margins?|borders?|shadows?|icons?|paragraphs?|(?:title|text|foreground)\s+colou?r)\b/i', $text );
	}

	/** Every target is physical, unique and attached to one saved directory loop. */
	public static function targets( object $graph ): array {
		$builder = $graph->document->builder ?? null;
		$spec    = self::CONTROLS[ $builder ] ?? null;
		if ( null === $spec || 'page' !== ( $graph->document->native_kind ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || ! empty( $graph->omissions ) ) {
			return array();
		}
		$pattern  = 'elementor' === $builder ? '#^document/i(?:0|[1-9][0-9]*)(?:/elements/i(?:0|[1-9][0-9]*))*$#D' : '#^content/i(?:0|[1-9][0-9]*)(?:/innerBlocks/i(?:0|[1-9][0-9]*))*$#D';
		$physical = array_filter( $graph->nodes, static fn( $node ): bool => null === ( $node->opaque_ref ?? null ) && preg_match( $pattern, implode( '/', $node->locator->slot_path ?? array() ) ) );
		$loops    = array_values( array_filter( $physical, static fn( $node ): bool => in_array( $node->native_id, $spec['loops'], true ) ) );
		if ( 1 !== count( $loops ) || null === $loops[0]->scope->directory_id ) {
			return array();
		}
		$loop    = $loops[0];
		$root    = $loop->locator->slot_path;
		$targets = array();
		$views   = array();
		foreach ( array( 'heading', 'search', 'card' ) as $role ) {
			list( $native, $paths ) = $spec[ $role ];
			$nodes                  = array_values(
				array_filter(
					$physical,
					static function ( $node ) use ( $native, $loop, $root, $builder, $role ): bool {
						if ( $native !== $node->native_id || $node->scope->directory_id !== $loop->scope->directory_id || null !== $node->scope->breakpoint_id || 'normal' !== $node->scope->interaction ) {
							return false;
						}
						$inside = array_slice( $node->locator->slot_path, 0, count( $root ) ) === $root;
						if ( 'heading' !== $role ) {
							return $inside;
						}
						if ( $inside || null !== $node->scope->view || strnatcmp( implode( '/', $node->locator->slot_path ), implode( '/', $root ) ) >= 0 || ! empty( $node->attributes->metadata->bindings ) || ! empty( $node->attributes->bindings ) ) {
							return false;
						}
						return 'divi' !== $builder || (bool) preg_match( '#<h[1-6](?:\s[^>]*)?>[^<]+</h[1-6]>#i', $node->attributes->content->innerContent->desktop->value ?? '' );
					}
				)
			);
			if ( 1 !== count( $nodes ) ) {
				return array();
			}
			$node = $nodes[0];
			if ( null !== $node->scope->view ) {
				$views[ $node->scope->view ] = true;
			}
			$targets[] = array(
				'kind'  => $role,
				'node'  => $node,
				'paths' => $paths,
			);
		}
		return count( $views ) <= 1 ? $targets : array();
	}

	/** Validate the whole plan before any serializer can accept mixed native sections. */
	public static function batches( object $graph, array $changes ): ?array {
		if ( ! in_array( count( $changes ), array( 3, 4 ), true ) || array_unique( array_column( $changes, 'op' ) ) !== array( 'set_control' ) ) {
			return null;
		}
		$canonical = new JsonCanonicalizer();
		$expected  = array();
		$ids       = array();
		foreach ( self::targets( $graph ) as $entry ) {
			$node                                        = $entry['node'];
			$ids[ $canonical->encode( $node->locator ) ] = $node->id;
			foreach ( $entry['paths'] as $path ) {
				$expected[ $node->id ][ $path ] = $node;
			}
		}
		if ( ! $expected || count( $changes ) !== array_sum( array_map( 'count', $expected ) ) ) {
			return null;
		}
		$batches = array();
		foreach ( $changes as $change ) {
			$path   = $change->control_path ?? null;
			$id     = $change->target_id ?? ( $ids[ $canonical->encode( $change->target ?? null ) ] ?? null );
			$target = is_string( $path ) && is_string( $id ) ? ( $expected[ $id ][ $path ] ?? null ) : null;
			if ( null === $target || 'set_control' !== ( $change->op ?? null ) || $canonical->encode( $target->scope ) !== $canonical->encode( $change->scope ?? null ) ) {
				return null;
			}
			if ( 'native_controls.button_color' === $path && ! self::only_button_background( $canonical, $target, $change->value ?? null ) ) {
				return null;
			}
			if ( 'divi/text' === $target->native_id && ! self::only_heading_text( $target, $change->value ?? null ) ) {
				return null;
			}
			unset( $expected[ $id ][ $path ] );
			$batches[ $id ][] = $change;
		}
		return $batches;
	}

	private static function only_button_background( JsonCanonicalizer $canonical, object $node, $after ): bool {
		$before = $node->attributes->native_controls->button_color ?? null;
		if ( ! $before instanceof \stdClass || ! $after instanceof \stdClass || ! is_string( $after->button_background ?? null ) ) {
			return false;
		}
		$expected                    = clone $before;
		$expected->button_background = $after->button_background;
		return $canonical->encode( $expected ) === $canonical->encode( $after );
	}

	private static function only_heading_text( object $node, $after ): bool {
		$before = $node->attributes->content->innerContent->desktop->value ?? null;
		if ( ! is_string( $before ) || ! is_string( $after ) ) {
			return false;
		}
		$pattern         = '#(<h[1-6](?:\s[^>]*)?>)[^<]+(</h[1-6]>)#i';
		$without_heading = static fn( string $markup ): string => preg_replace( $pattern, '$1$2', $markup, 1 );
		return preg_match( $pattern, $after ) && $without_heading( $before ) === $without_heading( $after );
	}

	public static function assert_complete( object $graph, array $changes, bool $includes_query ): void {
		if ( $includes_query || null === self::batches( $graph, $changes ) ) {
			throw new ContractException( 'invalid_input', 'Include the reviewed heading, search button background and every supplied card background together in one proposal. No incomplete redesign was prepared.', array( 'complete_requested_page_redesign' ) );
		}
	}

	/** Resolve a repeated outer scope only for an exact locator and its declared path. */
	public static function bind_native_scopes( object $graph, object $page_scope, array $changes ): array {
		$canonical = new JsonCanonicalizer();
		$outer     = $canonical->encode( $page_scope );
		foreach ( self::targets( $graph ) as $entry ) {
			$node = $entry['node'];
			if ( $outer === $canonical->encode( $node->scope ) || ! self::narrow_scope( $graph, $node->locator, $node->scope, $page_scope ) ) {
				continue;
			}
			$locator = $canonical->encode( $node->locator );
			foreach ( $changes as $index => $change ) {
				if ( 'set_control' === ( $change->op ?? null ) && in_array( $change->control_path ?? null, $entry['paths'], true ) && $locator === $canonical->encode( $change->target ?? null ) && $outer === $canonical->encode( $change->scope ?? null ) ) {
					$changes[ $index ]        = clone $change;
					$changes[ $index ]->scope = $node->scope;
				}
			}
		}
		return $changes;
	}

	/** Bridge the page's captured view and the exact scopes of physical controls. */
	public static function narrow_scope( object $graph, object $target, object $scope, object $page_scope, ?string $path = null ): bool {
		if ( $scope->view === $page_scope->view || ( null !== $scope->view && null !== $page_scope->view ) || ! in_array( $scope->view ?? $page_scope->view, array( 'grid', 'list', 'map' ), true ) ) {
			return false;
		}
		$canonical  = new JsonCanonicalizer();
		$base       = clone $scope;
		$base->view = $page_scope->view;
		if ( $canonical->encode( $base ) !== $canonical->encode( $page_scope ) ) {
			return false;
		}
		$targets = self::targets( $graph );
		foreach ( $targets as $entry ) {
			$view = $entry['node']->scope->view ?? ( 'card' === $entry['kind'] ? ( $entry['node']->attributes->view ?? null ) : null );
			if ( null !== $page_scope->view && null !== $view && $page_scope->view !== $view ) {
				return false;
			}
		}
		foreach ( $targets as $entry ) {
			$node = $entry['node'];
			if ( $canonical->encode( $node->locator ) === $canonical->encode( $target ) && $canonical->encode( $node->scope ) === $canonical->encode( $scope ) && ( null === $path || in_array( $path, $entry['paths'], true ) ) ) {
				return true;
			}
		}
		return false;
	}
}
