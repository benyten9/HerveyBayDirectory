<?php
/**
 * Combine independently native-validated control candidates, never source writes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact internal native contract helpers.

use DirectoristAIPro\Validation\{ContractException, JsonCanonicalizer};

/** Composition cannot introduce writes beyond the independently validated native candidates. */
final class BuilderNativeControlBatch {
	/** Divi annotates parse results with fresh runtime IDs. Only these five keys serialize. */
	public static function parse( string $content ): array {
		$clean = static function ( array $blocks ) use ( &$clean ): array {
			return array_map(
				static function ( array $block ) use ( &$clean ): array {
					$saved                = array_intersect_key( $block, array_fill_keys( array( 'blockName', 'attrs', 'innerBlocks', 'innerHTML', 'innerContent' ), true ) );
					$saved['innerBlocks'] = $clean( $saved['innerBlocks'] );
					return $saved;
				},
				$blocks
			);
		};
		return $clean( parse_blocks( $content ) );
	}

	public static function revision(): string {
		return hash_file( 'sha256', __FILE__ ); }
	/** A three-way merge retains every untouched native value and rejects conflicting aliases. */
	public static function merge( array $original, array $current, array $candidate ): array {
		return self::value( $original, $current, $candidate, 0 );
	}

	private static function value( $before, $current, $after, int $depth ) {
		if ( $depth > 48 ) {
			throw new ContractException( 'needs_expansion', 'Native control composition exceeds its depth boundary.' ); }
		$canonical = new JsonCanonicalizer();
		$equal     = static fn( $a, $b ): bool => $canonical->encode( $a ) === $canonical->encode( $b );
		if ( $equal( $before, $after ) || $equal( $current, $after ) ) {
			return $current; }
		if ( $equal( $before, $current ) ) {
			return $after; }
		if ( ! is_array( $before ) || ! is_array( $current ) || ! is_array( $after ) ) {
			self::conflict(); }
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$b = array_key_exists( $key, $before );
			$c = array_key_exists( $key, $current );
			$a = array_key_exists( $key, $after );
			if ( $b && $a && $equal( $before[ $key ], $after[ $key ] ) ) {
				continue; }
			if ( $b && $c && $equal( $before[ $key ], $current[ $key ] ) ) {
				if ( $a ) {
					$current[ $key ] = $after[ $key ];
				} else {
					unset( $current[ $key ] ); }
				continue;
			}
			if ( ! $b && ! $c && $a ) {
				$current[ $key ] = $after[ $key ];
				continue; }
			if ( ! $a && ! $c ) {
				continue; }
			if ( $a && $c && $equal( $current[ $key ], $after[ $key ] ) ) {
				continue; }
			if ( $a && $c && is_array( $current[ $key ] ) && is_array( $after[ $key ] ) && ( ! $b || is_array( $before[ $key ] ) ) ) {
				$current[ $key ] = self::value( $b ? $before[ $key ] : array(), $current[ $key ], $after[ $key ], $depth + 1 );
				continue;
			}
			self::conflict();
		}
		return $current;
	}

	/** Exact physical card ancestry and each native scope are required, including on templates. */
	public static function card_batches( object $graph, array $changes, string $card, string $title ): array {
		$nodes       = array_column( $graph->nodes, null, 'id' );
		$batches     = array();
		$roots       = array();
		$views       = array();
		$directories = array();
		$canonical   = new JsonCanonicalizer();
		foreach ( $changes as $change ) {
			$node = $nodes[ $change->target_id ?? '' ] ?? null;
			if ( 'set_control' !== ( $change->op ?? null ) || null === $node || ! in_array( $node->native_id, array( $card, $title ), true ) || null !== $node->opaque_ref || $canonical->encode( $node->scope ) !== $canonical->encode( $change->scope ?? null ) || null !== $node->scope->breakpoint_id || 'normal' !== $node->scope->interaction || ! preg_match( '#^content/i(?:0|[1-9][0-9]*)(?:/innerBlocks/i(?:0|[1-9][0-9]*))*$#D', implode( '/', $node->locator->slot_path ) ) ) {
				throw new ContractException( 'scope_conflict', 'Compound card controls require exact physical card/title targets and their native normal scopes.' );
			}
			$batches[ $node->id ][] = $change;
			if ( $node->native_id === $card ) {
				$roots[ $node->id ] = $node->locator->slot_path; }
			$directories[ (string) $node->scope->directory_id ] = true;
			if ( null !== $node->scope->view ) {
				$views[ $node->scope->view ] = true; }
		}
		if ( 1 !== count( $roots ) || count( $batches ) < 2 || count( $directories ) !== 1 || count( $views ) > 1 ) {
			throw new ContractException( 'scope_conflict', 'One compound card review cannot mix cards, directories or views.' ); }
		$root = reset( $roots );
		foreach ( array_keys( $batches ) as $id ) {
			$path = $nodes[ $id ]->locator->slot_path;
			if ( array_slice( $path, 0, count( $root ) ) !== $root ) {
				throw new ContractException( 'scope_conflict', 'Every compound title must belong to its exact reviewed card.' ); }
		}
		return $batches;
	}

	private static function conflict(): void {
		throw new ContractException( 'scope_conflict', 'Independently validated native controls overlap. No partial candidate was prepared.' ); }
}
