<?php
/**
 * Signed, scope-bound listing search cursor.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment -- Security checks remain visually grouped.

use DirectoristAIPro\Validation\ContractException;

/** Cursor contents are integrity protected and never grant dataset authority. */
final class ListingQueryCursor {
	/**
	 * Site HMAC key.
	 *
	 * @var string
	 */
	private string $key;
	/**
	 * Exact stored cursor hashes, available only during attested read-only replay.
	 *
	 * @var array<string,bool>
	 */
	private static array $replay_tokens = array();
	/**
	 * Native-equivalent renewed cursors, withheld until replay evidence matches.
	 *
	 * @var array<string,string>
	 */
	private static array $renewed_tokens = array();

	/**
	 * Scope the expiration exception to exact original tokens; always restore it.
	 *
	 * @param array    $tokens Exact stored signed tokens.
	 * @param callable $callback Attested native read-only replay.
	 * @return mixed
	 * @throws ContractException Invalid bounded token collection.
	 */
	public static function with_replay_tokens( array $tokens, callable $callback ) {
		if ( count( $tokens ) > 101 ) {
			throw new ContractException( 'invalid_cursor', 'Stored listing cursor scope exceeds its bound.' );
		}
		$previous = self::$replay_tokens;
		$previous_renewed = self::$renewed_tokens;
		self::$replay_tokens = array();
		self::$renewed_tokens = array();
		try {
			foreach ( $tokens as $token ) {
				if ( ! is_string( $token ) || strlen( $token ) > 4096 ) {
					throw new ContractException( 'invalid_cursor', 'Stored listing cursor is invalid.' );
				}
				self::$replay_tokens[ hash( 'sha256', $token ) ] = true;
			}
			return $callback();
		} finally {
			self::$replay_tokens = $previous;
			self::$renewed_tokens = $previous_renewed;
		}
	}

	/**
	 * Read the native renewal only inside its exact attested replay callback.
	 *
	 * @param string $token Original stored cursor.
	 * @return string|null
	 */
	public static function renewed_replay_token( string $token ): ?string {
		return self::$renewed_tokens[ hash( 'sha256', $token ) ] ?? null;
	}

	/**
	 * Bind a site secret supplied by trusted bootstrap code.
	 *
	 * @param string $key Site signing secret.
	 * @throws ContractException Weak signing key.
	 */
	public function __construct( string $key ) {
		if ( strlen( $key ) < 32 ) {
			throw new ContractException( 'unsupported_environment', 'Query cursor signing is unavailable.' );
		}
		$this->key = $key;
	}

	/**
	 * Encode one canonical payload with an HMAC signature.
	 *
	 * @param array $payload Trusted cursor payload.
	 * @return string
	 */
	public function encode( array $payload ): string {
		$json      = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		$body      = $this->base64url_encode( $json );
		$signature = $this->base64url_encode( hash_hmac( 'sha256', $body, $this->key, true ) );
		return $body . '.' . $signature;
	}

	/**
	 * Decode and authenticate a bounded cursor payload.
	 *
	 * @param string $token Signed cursor token.
	 * @return object
	 * @throws ContractException Invalid or expired cursor.
	 */
	public function decode( string $token ): object {
		if ( '' === $token || strlen( $token ) > 4096 || 1 !== substr_count( $token, '.' ) ) {
			throw new ContractException( 'invalid_cursor', 'Listing query cursor is invalid.' );
		}
		list( $body, $encoded_signature ) = explode( '.', $token, 2 );
		$signature               = $this->base64url_decode( $encoded_signature );
		if ( false === $signature || ! hash_equals( hash_hmac( 'sha256', $body, $this->key, true ), $signature ) ) {
			throw new ContractException( 'invalid_cursor', 'Listing query cursor is invalid.' );
		}
		$json = $this->base64url_decode( $body );
		if ( false === $json ) {
			throw new ContractException( 'invalid_cursor', 'Listing query cursor is invalid.' );
		}
		try {
			$payload = json_decode( $json, false, 16, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new ContractException( 'invalid_cursor', 'Listing query cursor is invalid.' );
		}
		$required = array( 'v', 'scope', 'site', 'directory', 'catalog', 'query', 'position', 'expires' );
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both sides are runtime values.
		if ( ! $payload instanceof \stdClass || $required !== array_keys( get_object_vars( $payload ) ) || 1 !== $payload->v || ! is_string( $payload->scope ) || ! is_int( $payload->site ) || ! is_int( $payload->directory ) || ! is_string( $payload->catalog ) || ! is_string( $payload->query ) || ! is_array( $payload->position ) || ! is_int( $payload->expires ) || ( ! isset( self::$replay_tokens[ hash( 'sha256', $token ) ] ) && max( time(), $payload->expires ) !== $payload->expires ) ) {
			throw new ContractException( 'invalid_cursor', 'Listing query cursor is invalid or expired.' );
		}
		if ( isset( self::$replay_tokens[ hash( 'sha256', $token ) ] ) ) {
			$renewed = clone $payload;
			// Match the existing native ListingQueryService cursor lifetime.
			$renewed->expires = time() + 1800;
			self::$renewed_tokens[ hash( 'sha256', $token ) ] = $this->encode( (array) $renewed );
		}
		return $payload;
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Strict URL-safe base64 decoder.
	 *
	 * @param string $value Encoded value.
	 * @return string|false
	 */
	private function base64url_decode( string $value ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) {
			return false;
		}
		$padding = ( 4 - strlen( $value ) % 4 ) % 4;
		$decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );
		return false !== $decoded && $this->base64url_encode( $decoded ) === $value ? $decoded : false;
	}
}
