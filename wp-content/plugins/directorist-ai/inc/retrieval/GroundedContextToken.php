<?php
/**
 * Signed structural context for grounded follow-up turns.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Token invariants are documented at the class boundary.

use DirectoristAIPro\Validation\ContractException;

/** Integrity protects task scope; every embedded plan still gets revalidated. */
final class GroundedContextToken {
	private string $key;

	/** Bind a site-owned HMAC key. */
	public function __construct( string $key ) {
		if ( strlen( $key ) < 32 ) {
			throw new ContractException( 'unsupported_environment', 'Grounded follow-up signing is unavailable.' );
		}
		$this->key = $key;
	}

	/** Encode a trusted canonical payload. */
	public function encode( array $payload ): string {
		$json      = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		$body      = $this->base64url_encode( $json );
		$signature = $this->base64url_encode( hash_hmac( 'sha256', $body, $this->key, true ) );
		$token = $body . '.' . $signature;
		if ( strlen( $token ) > 32768 ) {
			throw new ContractException( 'invalid_input', 'Grounded follow-up context exceeds its bound.' );
		}
		return $token;
	}

	/** Decode and authenticate an exact bounded structural-context payload. */
	public function decode( string $token ): object {
		return $this->authenticate( $token, true );
	}

	/** Authenticate a producer-attested stored plan; this never admits a new turn. */
	public function decode_for_replay( string $token ): object {
		return $this->authenticate( $token, false );
	}

	/** Keep every structural/signature check identical across the two read paths. */
	private function authenticate( string $token, bool $current ): object {
		if ( '' === $token || strlen( $token ) > 32768 || 1 !== substr_count( $token, '.' ) ) {
			throw new ContractException( 'invalid_input', 'Grounded follow-up context is invalid.' );
		}
		list( $body, $encoded_signature ) = explode( '.', $token, 2 );
		$signature = $this->base64url_decode( $encoded_signature );
		if ( false === $signature || ! hash_equals( hash_hmac( 'sha256', $body, $this->key, true ), $signature ) ) {
			throw new ContractException( 'invalid_input', 'Grounded follow-up context is invalid.' );
		}
		$json = $this->base64url_decode( $body );
		if ( false === $json ) {
			throw new ContractException( 'invalid_input', 'Grounded follow-up context is invalid.' );
		}
		try {
			$payload = json_decode( $json, false, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new ContractException( 'invalid_input', 'Grounded follow-up context is invalid.' );
		}
		$keys = array( 'v', 'scope', 'site', 'family', 'directory_id', 'dataset', 'plan', 'expires' );
		if ( ! $payload instanceof \stdClass || array_keys( get_object_vars( $payload ) ) !== $keys || 1 !== $payload->v || ! is_string( $payload->scope ) || ! is_int( $payload->site ) || ! in_array( $payload->family, array( 'listing', 'knowledge' ), true ) || ( null !== $payload->directory_id && ( ! is_int( $payload->directory_id ) || 1 > $payload->directory_id ) ) || ( null !== $payload->dataset && ! in_array( $payload->dataset, array( 'public', 'own', 'management' ), true ) ) || ! $payload->plan instanceof \stdClass || ! is_int( $payload->expires ) || ( $current && time() >= $payload->expires ) ) {
			throw new ContractException( 'invalid_input', 'Grounded follow-up context is invalid or expired.' );
		}
		return $payload;
	}

	/** URL-safe base64 without padding. */
	private function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** Strict URL-safe base64 decoder. */
	private function base64url_decode( string $value ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) {
			return false;
		}
		$padding = ( 4 - strlen( $value ) % 4 ) % 4;
		$decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );
		return false !== $decoded && $this->base64url_encode( $decoded ) === $value ? $decoded : false;
	}
}
