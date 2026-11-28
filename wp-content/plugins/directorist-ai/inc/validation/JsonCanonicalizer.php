<?php
/**
 * RFC 8785-compatible JSON canonicalization for normalized contract DTOs.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Validation;

/** Stable PHP/JavaScript graph hashing without native-payload rewriting. */
final class JsonCanonicalizer {
	/**
	 * Hash the exact canonical bytes without retaining the full encoded value.
	 *
	 * @param mixed $value JSON-compatible value.
	 * @return string SHA-256 of the same bytes returned by encode().
	 */
	public function digest( $value ): string {
		$context = hash_init( 'sha256' );
		$buffer  = '';
		$this->emit( $value, $context, $buffer );
		if ( '' !== $buffer ) {
			hash_update( $context, $buffer ); }
		return hash_final( $context );
	}

	/**
	 * Emit canonical tokens with bounded string chunks.
	 *
	 * Append small tokens to one shared buffer instead of calling a byte sink
	 * for every comma, colon and scalar in a native control registry. Flush at
	 * recursive value boundaries; large strings still stream in UTF-8 chunks.
	 *
	 * @param mixed        $value Validated JSON-compatible value.
	 * @param \HashContext $context Canonical digest state.
	 * @param string       $buffer Bounded pending canonical bytes.
	 * @return void
	 */
	private function emit( $value, \HashContext $context, string &$buffer ): void {
		if ( strlen( $buffer ) >= 65536 ) {
			hash_update( $context, $buffer );
			$buffer = '';
		}
		if ( is_string( $value ) && strlen( $value ) > 65536 ) {
			hash_update( $context, $buffer . '"' );
			$buffer = '';
			$length = strlen( $value );
			for ( $start = 0; $start < $length; $start = $end ) {
				$end = min( $length, $start + 65536 );
				while ( $end < $length && $end > $start && ( ord( $value[ $end ] ) & 0xC0 ) === 0x80 ) {
					--$end; }
				if ( $end === $start ) {
					$end = min( $length, $start + 65536 ); }
				$part = json_encode( substr( $value, $start, $end - $start ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS );
				hash_update( $context, substr( $part, 1, -1 ) );
			}
			$buffer = '"';
			return;
		}
		if ( is_array( $value ) ) {
			$is_list = true;
			$index   = 0;
			foreach ( $value as $key => $_ ) {
				if ( $key !== $index++ ) {
					$is_list = false;
					break; }
			}
			if ( $is_list ) {
				$buffer .= '[';
				$first = true;
				foreach ( $value as $item ) {
					if ( ! $first ) {
						$buffer .= ',';
					} $first = false;
					$this->emit( $item, $context, $buffer ); }
				$buffer .= ']';
				return;
			}
			$value = (object) $value;
		}
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			$keys       = $this->ordered_keys( $properties );
			$buffer .= '{';
			$first = true;
			foreach ( $keys as $key ) {
				if ( ! $first ) {
					$buffer .= ','; }
				$first = false;
				$this->emit( $key, $context, $buffer );
				$buffer .= ':';
				$this->emit( $properties[ $key ], $context, $buffer );
			}
			$buffer .= '}';
			return;
		}
		$buffer .= $this->encode( $value );
	}

	/**
	 * Sort exact property keys in RFC 8785 UTF-16 order.
	 *
	 * @param array $properties Native object properties, including NUL keys.
	 * @return array Ordered string keys.
	 */
	private function ordered_keys( array $properties ): array {
		$keys  = array();
		$ascii = true;
		foreach ( $properties as $key => $_ ) {
			$keys[] = (string) $key;
			if ( preg_match( '/[\x80-\xFF]/', (string) $key ) ) {
				$ascii = false; }
		}
		if ( $ascii ) {
			sort( $keys, SORT_STRING );
		} else {
			$sort_keys = array();
			foreach ( $keys as $key ) {
				$sort_keys[ $key ] = $this->utf16( $key ); }
			usort( $keys, static fn( string $left, string $right ): int => strcmp( $sort_keys[ $left ], $sort_keys[ $right ] ) );
		}
		return $keys;
	}

	/**
	 * Encode one already validated JSON-compatible value.
	 *
	 * @param mixed $value JSON-compatible value.
	 * @return string Canonical JSON.
	 * @throws ContractException Unsupported values or numbers.
	 */
	public function encode( $value ): string {
		if ( null === $value ) {
			return 'null';
		}
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}
		if ( is_int( $value ) ) {
			if ( $value > 9007199254740991 || $value < -9007199254740991 ) {
				throw new ContractException( 'invalid_input', 'Canonical JSON integer exceeds binary64 exact range.' );
			}
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return $this->number( $value );
		}
		if ( is_string( $value ) ) {
			return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS );
		}
		if ( is_array( $value ) ) {
			$is_list = true;
			$index   = 0;
			foreach ( $value as $key => $_ ) {
				if ( $key !== $index++ ) {
					$is_list = false;
					break; }
			}
			if ( $is_list ) {
				$json  = '[';
				$first = true;
				foreach ( $value as $item ) {
					if ( ! $first ) {
						$json .= ','; }
					$first = false;
					$json .= $this->encode( $item );
				}
				return $json . ']';
			}
			$value = (object) $value;
		}
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			// Native control registries contain mostly ASCII keys. Their byte order
			// already equals UTF-16 order; avoid converting both keys for every
			// comparison in these large, repeatedly inspected contract objects.
			$keys  = $this->ordered_keys( $properties );
			$json  = '{';
			$first = true;
			foreach ( $keys as $key ) {
				if ( ! $first ) {
					$json .= ','; }
				$first = false;
				$json .= $this->encode( $key );
				$json .= ':';
				$json .= $this->encode( $properties[ $key ] );
			}
			return $json . '}';
		}
		throw new ContractException( 'invalid_input', 'Canonical JSON value is unsupported.' );
	}

	/**
	 * Reformat PHP's shortest binary64 digits using ECMAScript JSON thresholds.
	 *
	 * @param float $value Finite binary64 value.
	 * @return string ECMAScript-compatible number.
	 * @throws ContractException Nonfinite or unavailable number.
	 */
	private function number( float $value ): string {
		if ( ! is_finite( $value ) ) {
			throw new ContractException( 'invalid_input', 'Canonical JSON number must be finite.' );
		}
		if ( 0.0 === $value ) {
			return '0';
		}
		$negative  = $value < 0;
		$precision = ini_get( 'serialize_precision' );
		ini_set( 'serialize_precision', '-1' );
		try {
			$raw = json_encode( abs( $value ), JSON_THROW_ON_ERROR );
		} finally {
			ini_set( 'serialize_precision', (string) $precision );
		}
		if ( ! preg_match( '/^([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $raw, $match ) ) {
			throw new ContractException( 'invalid_input', 'Canonical JSON number is unavailable.' );
		}
		$fraction = $match[2] ?? '';
		$digits   = ltrim( $match[1] . $fraction, '0' );
		$power    = isset( $match[3] ) ? (int) $match[3] : 0;
		$power   -= strlen( $fraction );
		while ( str_ends_with( $digits, '0' ) ) {
			$digits = substr( $digits, 0, -1 );
			++$power;
		}
		$exponent = strlen( $digits ) - 1 + $power;
		if ( $exponent >= 21 || $exponent <= -7 ) {
			$number = $digits[0] . ( strlen( $digits ) > 1 ? '.' . substr( $digits, 1 ) : '' ) . 'e' . ( $exponent >= 0 ? '+' : '' ) . $exponent;
		} else {
			$point = strlen( $digits ) + $power;
			if ( $point <= 0 ) {
				$number = '0.' . str_repeat( '0', -$point ) . $digits;
			} elseif ( $point >= strlen( $digits ) ) {
				$number = $digits . str_repeat( '0', $point - strlen( $digits ) );
			} else {
				$number = substr( $digits, 0, $point ) . '.' . substr( $digits, $point );
			}
		}
		return ( $negative ? '-' : '' ) . $number;
	}

	/**
	 * Convert valid UTF-8 keys to binary UTF-16BE for RFC property ordering.
	 *
	 * @param string $value Validated UTF-8 object key.
	 * @return string Binary UTF-16BE sort key.
	 */
	private function utf16( string $value ): string {
		$result = '';
		$length = strlen( $value );
		for ( $index = 0; $index < $length; ) {
			$first = ord( $value[ $index++ ] );
			if ( $first < 0x80 ) {
				$codepoint = $first;
			} elseif ( $first < 0xE0 ) {
				$codepoint = ( ( $first & 0x1F ) << 6 ) | ( ord( $value[ $index++ ] ) & 0x3F );
			} elseif ( $first < 0xF0 ) {
				$codepoint = ( ( $first & 0x0F ) << 12 ) | ( ( ord( $value[ $index++ ] ) & 0x3F ) << 6 ) | ( ord( $value[ $index++ ] ) & 0x3F );
			} else {
				$codepoint = ( ( $first & 0x07 ) << 18 ) | ( ( ord( $value[ $index++ ] ) & 0x3F ) << 12 ) | ( ( ord( $value[ $index++ ] ) & 0x3F ) << 6 ) | ( ord( $value[ $index++ ] ) & 0x3F );
			}
			if ( $codepoint <= 0xFFFF ) {
				$result .= pack( 'n', $codepoint );
			} else {
				$codepoint -= 0x10000;
				$result    .= pack( 'n2', 0xD800 + ( $codepoint >> 10 ), 0xDC00 + ( $codepoint & 0x3FF ) );
			}
		}
		return $result;
	}
}
