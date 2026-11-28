<?php
/** Bounded, owned manual source discovery; model disclosure remains separate.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Private source catalog presentation contract.

/** Bounded discovery complements authoritative checks of any manual pointer. */
final class SourceCatalog {
	public const PAGE_SIZE      = 100;
	public const MAX_PATHS      = 5000;
	public const MAX_PATH_BYTES = 1048576;

	public static function page( iterable $records, string $search = '', int $offset = 0 ): array {
		if ( $offset < 0 || $offset > self::MAX_PATHS || mb_strlen( $search ) > 100 || 1 !== preg_match( '//u', $search ) || preg_match( '/[\x00-\x1f\x7f]/u', $search ) ) {
			throw new ContractException( 'source_path', 'Use a bounded source-path search and nonnegative offset.' );
		}
		$seen       = array();
		$path_bytes = 0;
		foreach ( $records as $record ) {
			foreach ( self::walk( $record, '' ) as $path ) {
				if ( '' !== $search && false === mb_stripos( $path, $search ) ) {
					continue;
				}
				if ( isset( $seen[ $path ] ) ) {
					continue;
				}
				if ( count( $seen ) >= self::MAX_PATHS || $path_bytes + strlen( $path ) > self::MAX_PATH_BYTES ) {
					return array(
						'paths'       => array_slice( array_keys( $seen ), $offset, self::PAGE_SIZE ),
						'has_more'    => false,
						'next_offset' => null,
						'total'       => null,
						'complete'    => false,
						'truncated'   => true,
						'reason'      => 'catalog_limit',
					);
				}
				$seen[ $path ] = true;
				$path_bytes   += strlen( $path );
				if ( count( $seen ) > $offset + self::PAGE_SIZE ) {
					return array(
						'paths'       => array_slice( array_keys( $seen ), $offset, self::PAGE_SIZE ),
						'has_more'    => true,
						'next_offset' => $offset + self::PAGE_SIZE,
						'total'       => null,
						'complete'    => false,
						'truncated'   => false,
						'reason'      => null,
					);
				}
			}
		}
		return array(
			'paths'       => array_slice( array_keys( $seen ), $offset, self::PAGE_SIZE ),
			'has_more'    => false,
			'next_offset' => null,
			'total'       => count( $seen ),
			'complete'    => true,
			'truncated'   => false,
			'reason'      => null,
		);
	}

	private static function walk( array $value, string $prefix ): \Generator {
		foreach ( $value as $key => $child ) {
			$path = $prefix . '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), (string) $key );
			if ( strlen( $path ) > 1024 ) {
				continue;
			}
			yield $path;
			if ( is_array( $child ) ) {
				yield from self::walk( $child, $path );
			}
		}
	}

	public static function check( iterable $records, array $schema, string $path ): array {
		if ( '' === $path || strlen( $path ) > 1024 || '/' !== $path[0] || preg_match( '/~(?![01])/', $path ) || 1 !== preg_match( '//u', $path ) ) {
			return array(
				'valid'       => false,
				'source_path' => $path,
				'examples'    => array(),
				'reason'      => 'invalid_pointer',
			);
		}
		$found    = false;
		$examples = array();
		foreach ( $records as $record ) {
			$value = SourceParser::pointer( $record, $path );
			if ( null === $value ) {
				continue;
			}
			$found = true;
			if ( count( $examples ) < 2 ) {
				$safe    = Mapping::safe_path( $schema, array( array( 'data' => $record ) ), $path );
				$example = $safe ? ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) : '[Private value withheld]';
				if ( is_string( $example ) ) {
					$example = mb_substr( wp_strip_all_tags( $example ), 0, 160 );
				}
				if ( ! in_array( $example, $examples, true ) ) {
					$examples[] = $example;
				}
			}
		}
		return array(
			'valid'       => $found,
			'source_path' => $path,
			'examples'    => $examples,
			'reason'      => $found ? null : 'absent_from_source',
		);
	}
}
