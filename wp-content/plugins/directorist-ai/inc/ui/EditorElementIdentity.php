<?php
/**
 * Read-only binding of a persistent history marker to its live native element.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\UI;

use DirectoristAIPro\Builder\BuilderEditorSelection;
use DirectoristAIPro\Validation\ContractException;

/** Opaque history identity never becomes a native locator or an editing grant. */
final class EditorElementIdentity {
	/**
	 * Verify one selected physical node, excluding serialized native cache aliases.
	 *
	 * @param object $document Authorized original native identity.
	 * @param object $snapshot Current unsaved native capture.
	 * @param object $selection Current native selection, independent of history.
	 * @param string $identity Opaque selected history marker.
	 * @throws ContractException Missing, ambiguous, malformed or misplaced marker.
	 */
	public static function assert_capture( object $document, object $snapshot, object $selection, string $identity ): void {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $identity ) || null !== ( $selection->native_node_id ?? null ) || ! is_array( $selection->slot_path ?? null ) ) {
			throw new ContractException( 'invalid_input', 'An exact selected native element history identity is required.' );
		}
		$count = 0;
		$nodes = 0;
		if ( 'gutenberg' === ( $document->builder ?? null ) ) {
			if ( ! is_string( $snapshot->content ?? null ) || strlen( $snapshot->content ) > 1048576 || ! function_exists( 'parse_blocks' ) ) {
				self::unavailable();
			}
			$blocks   = parse_blocks( $snapshot->content );
			$path     = BuilderEditorSelection::gutenberg_saved_path( $blocks, $selection->slot_path );
			$selected = self::block_at( $blocks, $path );
			self::count_blocks( $blocks, $identity, $count, $nodes, 0 );
			$marker = $selected['attrs']['metadata']['directoristAIElementId'] ?? null;
		} elseif ( 'divi' === ( $document->builder ?? null ) ) {
			if ( 'divi5-module-tree' !== ( $snapshot->format ?? null ) || ! is_array( $snapshot->modules ?? null ) || strlen( wp_json_encode( $snapshot ) ) > 1048576 || ! is_string( $document->native_id ?? null ) || ! preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $match ) ) {
				self::unavailable();
			}
			$source = get_post( (int) $match[1] );
			if ( ! $source instanceof \WP_Post ) {
				self::unavailable();
			}
			$saved = parse_blocks( $source->post_content );
			$path  = $selection->slot_path;
			if ( 1 === count( $saved ) && 'divi/placeholder' === ( $saved[0]['blockName'] ?? null ) ) {
				if ( array( 'content', 'i0', 'innerBlocks' ) !== array_slice( $path, 0, 3 ) ) {
					self::unavailable();
				}
				$path = array_merge( array( 'content' ), array_slice( $path, 3 ) );
			}
			$selected = self::module_at( $snapshot->modules, $path );
			self::count_modules( $snapshot->modules, $identity, $count, $nodes, 0 );
			$marker = self::module_marker( $selected );
		} else {
			throw new ContractException( 'invalid_input', 'This builder uses its existing native element identity.' );
		}
		if ( 1 !== $count || $identity !== $marker ) {
			self::unavailable();
		}
	}

	/**
	 * Resolve only an exact parser path produced from the current browser ordinal.
	 *
	 * @param array $blocks Parsed native sibling blocks.
	 * @param array $path Exact current parser path.
	 * @return array Selected physical native block.
	 * @throws ContractException The selected path is unavailable.
	 */
	private static function block_at( array $blocks, array $path ): array {
		$selected = null;
		foreach ( array_slice( $path, 1 ) as $offset => $part ) {
			if ( 0 === $offset % 2 ) {
				if ( ! is_string( $part ) || ! preg_match( '/^i(0|[1-9][0-9]*)$/D', $part, $match ) || ! isset( $blocks[ (int) $match[1] ] ) ) {
					self::unavailable();
				}
				$selected = $blocks[ (int) $match[1] ];
			} else {
				if ( 'innerBlocks' !== $part || ! is_array( $selected['innerBlocks'] ?? null ) ) {
					self::unavailable();
				}
				$blocks = $selected['innerBlocks'];
			}
		}
		if ( ! is_array( $selected ) || ! is_string( $selected['blockName'] ?? null ) || 0 !== count( $path ) % 2 ) {
			self::unavailable();
		}
		return $selected;
	}

	/**
	 * Count only physical blocks; cached alias strings and attribute trees are data.
	 *
	 * @param array  $blocks Physical sibling blocks.
	 * @param string $identity Requested opaque marker.
	 * @param int    $count Exact matching markers.
	 * @param int    $nodes Visited native nodes.
	 * @param int    $depth Native depth.
	 * @throws ContractException Bounded physical source is unavailable.
	 */
	private static function count_blocks( array $blocks, string $identity, int &$count, int &$nodes, int $depth ): void {
		if ( ! $blocks ) {
			return;
		}
		if ( $depth > 32 ) {
			self::unavailable();
		}
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && null === ( $block['blockName'] ?? null ) && '' === trim( $block['innerHTML'] ?? '' ) ) {
				continue;
			}
			if ( ++$nodes > 1600 || ! is_array( $block ) ) {
				self::unavailable();
			}
			if ( is_string( $block['blockName'] ?? null ) && ( $block['attrs']['metadata']['directoristAIElementId'] ?? null ) === $identity ) {
				++$count;
			}
			self::count_blocks( $block['innerBlocks'] ?? array(), $identity, $count, $nodes, $depth + 1 );
		}
	}

	/**
	 * Resolve the live module address after its saved wrapper has been accounted for.
	 *
	 * @param array $modules Physical native module siblings.
	 * @param array $path Current exact native address.
	 * @return object Selected live module.
	 * @throws ContractException Selected module is unavailable.
	 */
	private static function module_at( array $modules, array $path ): object {
		if ( 'content' !== ( $path[0] ?? null ) || count( $path ) < 2 || 0 !== count( $path ) % 2 ) {
			self::unavailable();
		}
		$selected = null;
		foreach ( array_slice( $path, 1 ) as $offset => $part ) {
			if ( 0 === $offset % 2 ) {
				if ( ! is_string( $part ) || ! preg_match( '/^i(0|[1-9][0-9]*)$/D', $part, $match ) || ! isset( $modules[ (int) $match[1] ] ) || ! $modules[ (int) $match[1] ] instanceof \stdClass ) {
					self::unavailable();
				}
				$selected = $modules[ (int) $match[1] ];
			} else {
				if ( 'innerBlocks' !== $part || ! is_array( $selected->children ?? null ) ) {
					self::unavailable();
				}
				$modules = $selected->children;
			}
		}
		if ( ! $selected instanceof \stdClass || ! is_string( $selected->name ?? null ) || ! preg_match( '/^(divi|directorist)\/[a-z0-9-]+$/D', $selected->name ) ) {
			self::unavailable();
		}
		return $selected;
	}

	/**
	 * Count live children only, excluding active/inactive serialized card state copies.
	 *
	 * @param array  $modules Physical native module siblings.
	 * @param string $identity Requested marker.
	 * @param int    $count Exact matching live markers.
	 * @param int    $nodes Physical visited nodes.
	 * @param int    $depth Current native depth.
	 * @throws ContractException Invalid or excessive live native tree.
	 */
	private static function count_modules( array $modules, string $identity, int &$count, int &$nodes, int $depth ): void {
		if ( ! $modules ) {
			return;
		}
		if ( $depth > 24 || ( $modules && array_keys( $modules ) !== range( 0, count( $modules ) - 1 ) ) ) {
			self::unavailable();
		}
		foreach ( $modules as $module ) {
			if ( ++$nodes > 500 || ! $module instanceof \stdClass || ! is_string( $module->name ?? null ) || ! is_array( $module->children ?? null ) ) {
				self::unavailable();
			}
			if ( self::module_marker( $module ) === $identity ) {
				++$count;
			}
			self::count_modules( $module->children, $identity, $count, $nodes, $depth + 1 );
		}
	}

	/**
	 * Read only the declared native module metadata object.
	 *
	 * @param object $module Live native module.
	 * @return mixed Marker scalar or absent value.
	 */
	private static function module_marker( object $module ) {
		$value = $module;
		foreach ( array( 'attrs', 'module', 'meta', 'directoristAI', 'elementId' ) as $part ) {
			if ( ! $value instanceof \stdClass || ! property_exists( $value, $part ) ) {
				return null;
			}
			$value = $value->{$part};
		}
		return $value;
	}

	/**
	 * Refuse ambiguous source without revealing another element or its history.
	 *
	 * @throws ContractException Persistent marker is unavailable for this selection.
	 */
	private static function unavailable(): void {
		throw new ContractException( 'scope_conflict', 'This element history identity is missing, duplicated or belongs to another area.' );
	}
}
