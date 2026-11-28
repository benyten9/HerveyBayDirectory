<?php
/**
 * Bounded JSON decoding with duplicate-member rejection.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Validation;

/**
 * Preserve object/list identity and reject ambiguous input before dispatch.
 */
final class JsonDecoder {
	/**
	 * Maximum bytes.
	 *
	 * @var int
	 */
	private int $max_bytes;
	/**
	 * Maximum container depth.
	 *
	 * @var int
	 */
	private int $max_depth;

	/**
	 * Set independent transport resource bounds.
	 *
	 * @param int $max_bytes Maximum payload bytes.
	 * @param int $max_depth Maximum nested containers.
	 * @throws \InvalidArgumentException Invalid decoder limits.
	 */
	public function __construct( int $max_bytes = 1048576, int $max_depth = 32 ) {
		if ( $max_bytes < 1 || $max_depth < 1 || $max_depth > 128 ) {
			throw new \InvalidArgumentException( 'Invalid JSON bounds.' );
		}
		$this->max_bytes = $max_bytes;
		$this->max_depth = $max_depth;
	}

	/**
	 * Decode original JSON before a transport can coerce types.
	 *
	 * @param string $json Original request body or model arguments.
	 * @return mixed Preserved JSON value.
	 * @throws ContractException Invalid or ambiguous input.
	 */
	public function decode( string $json ) {
		if ( strlen( $json ) > $this->max_bytes ) {
			throw new ContractException( 'invalid_input', 'JSON exceeds the byte limit.' );
		}
		try {
			$value = json_decode( $json, false, $this->max_depth + 1, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new ContractException( 'invalid_input', 'Invalid JSON encoding or nesting.' );
		}
		$position = 0;
		$this->scan( $json, $position );
		$this->check_value( $value, 0 );
		return $value;
	}

	/**
	 * Validate PHP/ability input without changing arrays into objects.
	 *
	 * @param mixed $value Input value.
	 * @return mixed Validated original value.
	 * @throws ContractException Invalid value or oversized input.
	 */
	public function check( $value ) {
		$this->check_value( $value, 0 );
		try {
			$encoded = json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, $this->max_depth + 1 );
		} catch ( \JsonException $error ) {
			throw new ContractException( 'invalid_input', 'Value is not valid JSON.' );
		}
		if ( strlen( $encoded ) > $this->max_bytes ) {
			throw new ContractException( 'invalid_input', 'JSON exceeds the byte limit.' );
		}
		return $value;
	}

	/**
	 * Bound all transports and reject numbers unsafe across PHP/JavaScript.
	 *
	 * @param mixed $value Value to check.
	 * @param int   $depth Current depth.
	 * @return void
	 * @throws ContractException Invalid value.
	 */
	private function check_value( $value, int $depth ): void {
		if ( is_array( $value ) || $value instanceof \stdClass ) {
			if ( $depth >= $this->max_depth ) {
				throw new ContractException( 'invalid_input', 'JSON exceeds the depth limit.' );
			}
			if ( is_array( $value ) && $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				throw new ContractException( 'invalid_input', 'JSON objects must retain object identity.' );
			}
			foreach ( $value as $child ) {
				$this->check_value( $child, $depth + 1 );
			}
			return;
		}
		if ( is_int( $value ) && ( $value > 9007199254740991 || $value < -9007199254740991 ) ) {
			throw new ContractException( 'invalid_input', 'Large identifiers must be strings.' );
		}
		if ( is_float( $value ) && ( ! is_finite( $value ) || ( floor( $value ) === $value && abs( $value ) > 9007199254740991 ) ) ) {
			throw new ContractException( 'invalid_input', 'Number is not safely representable.' );
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			throw new ContractException( 'invalid_input', 'Unsupported JSON value type.' );
		}
	}

	/**
	 * Scan already syntax-validated JSON to detect duplicate object members.
	 *
	 * Native decoding alone discards duplicates. This scanner does not replace
	 * the native JSON parser and only runs after its byte/depth/syntax checks.
	 *
	 * @param string $json Valid JSON.
	 * @param int    $position Current byte offset.
	 * @return void
	 * @throws ContractException Duplicate member.
	 */
	private function scan( string $json, int &$position ): void {
		$position += strspn( $json, " \t\r\n", $position );
		$token     = $json[ $position ];
		if ( '"' === $token ) {
			$this->string_token( $json, $position );
			return;
		}
		if ( '{' !== $token && '[' !== $token ) {
			$position += strcspn( $json, ",]} \t\r\n", $position );
			return;
		}
		$object = '{' === $token;
		$end    = $object ? '}' : ']';
		$seen   = array();
		++$position;
		$position += strspn( $json, " \t\r\n", $position );
		while ( $json[ $position ] !== $end ) {
			if ( $object ) {
				$key = 'key:' . json_decode( $this->string_token( $json, $position ), false, 2, JSON_THROW_ON_ERROR );
				if ( isset( $seen[ $key ] ) ) {
					throw new ContractException( 'invalid_input', 'Duplicate JSON object member.' );
				}
				$seen[ $key ] = true;
				$position    += strspn( $json, " \t\r\n", $position );
				++$position; // Colon: syntax was checked by json_decode.
			}
			$this->scan( $json, $position );
			$position += strspn( $json, " \t\r\n", $position );
			if ( ',' === $json[ $position ] ) {
				++$position;
				$position += strspn( $json, " \t\r\n", $position );
			}
		}
		++$position;
	}

	/**
	 * Consume an already validated string token including its quotes.
	 *
	 * @param string $json Valid JSON.
	 * @param int    $position Current position at opening quote.
	 * @return string Encoded string token.
	 */
	private function string_token( string $json, int &$position ): string {
		$start = $position++;
		while ( '"' !== $json[ $position ] ) {
			if ( '\\' === $json[ $position ] ) {
				++$position;
			}
			++$position;
		}
		++$position;
		return substr( $json, $start, $position - $start );
	}
}
