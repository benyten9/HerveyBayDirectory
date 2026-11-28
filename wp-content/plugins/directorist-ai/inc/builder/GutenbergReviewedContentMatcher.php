<?php
/**
 * Compare reviewed block content with the editor's native serialization.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

/** Source-derived attributes may move from comments into markup when the editor saves. */
final class GutenbergReviewedContentMatcher {
	/**
	 * Hash the same registered native state used by reviewed-save comparison.
	 *
	 * @param string $content Native block markup.
	 * @return string|null Bounded native fingerprint, or null for unsupported markup.
	 */
	public function fingerprint( string $content ): ?string {
		if ( strlen( $content ) > 5000000 ) {
			return null;
		}
		$count = 0;
		$walk  = function ( array $blocks, int $depth ) use ( &$walk, &$count ): ?array {
			$blocks = $this->nonempty_blocks( $blocks );
			if ( null === $blocks || $depth > 32 ) {
				return null;
			}
			$result = array();
			foreach ( $blocks as $block ) {
				$name = $block['blockName'];
				$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
				if ( ! $type instanceof \WP_Block_Type || ++$count > 2000 ) {
					return null;
				}
				$attrs = $block['attrs'] ?? array();
				foreach ( $type->attributes ?? array() as $key => $definition ) {
					if ( is_array( $definition ) && in_array( $definition['source'] ?? null, array( 'attribute', 'html', 'query', 'rich-text', 'text' ), true ) ) {
						unset( $attrs[ $key ] );
					}
				}
				$segments = $block['innerContent'] ?? array();
				$text     = '';
				foreach ( $segments as $segment ) {
					if ( null !== $segment && ! is_string( $segment ) ) {
						return null;
					}
					$text .= (string) $segment;
				}
				$markup = '' === trim( $text ) ? array( 'empty', count( array_filter( $segments, 'is_null' ) ) ) : array_map( static fn( $segment ) => null === $segment ? null : trim( $segment ), $segments );
				$inner  = $walk( $block['innerBlocks'] ?? array(), $depth + 1 );
				if ( null === $inner ) {
					return null;
				}
				$result[] = array( $name, $this->canonical( $attrs ), $markup, $inner );
			}
			return $result;
		};
		try {
			$tree = $walk( parse_blocks( $content ), 0 );
			return null === $tree ? null : hash( 'sha256', wp_json_encode( $tree ) );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Match the whole parsed tree while retaining every explicit control and nested scope.
	 *
	 * @param string $reviewed Exact approved draft content.
	 * @param string $submitted Native editor save content.
	 * @return bool Whether the submitted tree is the reviewed tree after native serialization.
	 */
	public function matches( string $reviewed, string $submitted ): bool {
		if ( strlen( $reviewed ) > 5000000 || strlen( $submitted ) > 5000000 ) {
			return false;
		}
		try {
			$count = 0;
			return $this->same_blocks( parse_blocks( $reviewed ), parse_blocks( $submitted ), 0, $count );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Compare registered blocks, source-independent controls and literal markup.
	 *
	 * @param array $left Reviewed blocks.
	 * @param array $right Editor blocks.
	 * @param int   $depth Current nesting depth.
	 * @param int   $count Visited block count.
	 * @return bool Whether both bounded trees match.
	 */
	private function same_blocks( array $left, array $right, int $depth, int &$count ): bool {
		if ( $depth > 32 ) {
			return false;
		}
		$left  = $this->nonempty_blocks( $left );
		$right = $this->nonempty_blocks( $right );
		if ( null === $left || null === $right || count( $left ) !== count( $right ) ) {
			return false;
		}
		foreach ( $left as $index => $before ) {
			++$count;
			$after = $right[ $index ];
			$name  = $before['blockName'] ?? null;
			$type  = is_string( $name ) ? \WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;
			if ( $count > 2000 || ! $type instanceof \WP_Block_Type || ! hash_equals( $name, (string) ( $after['blockName'] ?? '' ) ) || ! $this->same_attributes( $before['attrs'] ?? array(), $after['attrs'] ?? array(), $type ) || ! $this->same_markup( $before['innerContent'] ?? array(), $after['innerContent'] ?? array() ) || ! $this->same_blocks( $before['innerBlocks'] ?? array(), $after['innerBlocks'] ?? array(), $depth + 1, $count ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Ignore only whitespace-only freeform separators between block comments.
	 *
	 * @param array $blocks Parsed native blocks.
	 * @return array|null Blocks or null for unsupported freeform content.
	 */
	private function nonempty_blocks( array $blocks ): ?array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				return null;
			}
			if ( null === ( $block['blockName'] ?? null ) ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					return null;
				}
				continue;
			}
			$result[] = $block;
		}
		return $result;
	}

	/**
	 * Compare controls while allowing only registry-declared markup-sourced attributes to move.
	 *
	 * @param array          $left Reviewed comment attributes.
	 * @param array          $right Editor comment attributes.
	 * @param \WP_Block_Type $type Current installed block contract.
	 * @return bool Whether explicit non-markup values match.
	 */
	private function same_attributes( array $left, array $right, \WP_Block_Type $type ): bool {
		foreach ( $type->attributes ?? array() as $name => $definition ) {
			if ( is_array( $definition ) && in_array( $definition['source'] ?? null, array( 'attribute', 'html', 'query', 'rich-text', 'text' ), true ) ) {
				unset( $left[ $name ], $right[ $name ] );
			}
		}
		return $this->canonical( $left ) === $this->canonical( $right );
	}

	/**
	 * Compare every markup segment around nested blocks, trimming outer separators only.
	 *
	 * @param array $left Reviewed markup segments.
	 * @param array $right Editor markup segments.
	 * @return bool Whether literal markup is the same.
	 */
	private function same_markup( array $left, array $right ): bool {
		$plain = static function ( array $segments ): ?string {
			$text = '';
			foreach ( $segments as $segment ) {
				if ( null !== $segment && ! is_string( $segment ) ) {
					return null;
				}
				$text .= (string) $segment;
			}
			return trim( $text );
		};
		if ( '' === $plain( $left ) && '' === $plain( $right ) ) {
			return count( array_filter( $left, 'is_null' ) ) === count( array_filter( $right, 'is_null' ) );
		}
		if ( count( $left ) !== count( $right ) ) {
			return false;
		}
		foreach ( $left as $index => $segment ) {
			$other = $right[ $index ];
			if ( null === $segment || null === $other ) {
				if ( $segment !== $other ) {
					return false;
				}
			} elseif ( ! is_string( $segment ) || ! is_string( $other ) || trim( $segment ) !== trim( $other ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalize JSON object key order without changing array order or scalar values.
	 *
	 * @param mixed $value Parsed attribute value.
	 * @return string Canonical JSON representation.
	 */
	private function canonical( $value ): string {
		if ( is_array( $value ) ) {
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->canonical_value( $item );
			}
		}
		return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Recursively normalize an attribute value.
	 *
	 * @param mixed $value Parsed attribute value.
	 * @return mixed Normalized value.
	 */
	private function canonical_value( $value ) {
		if ( is_array( $value ) ) {
			if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->canonical_value( $item );
			}
		}
		return $value;
	}
}
