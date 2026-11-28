<?php
/**
 * Private immutable managed submission retained for exact-idempotent recovery.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** A receipt lookup cannot recreate a request from changed context or declarations. */
final class ManagedSubmission {

	public const MAX_RESUBMISSIONS = 2;
	/** The service independently admits the same bounded request body. */
	private const MAX_BYTES = 1048576;

	/** Capture only the original body; credentials never belong in this envelope. */
	public static function capture( array $body, array $route ): object {
		$json = wp_json_encode( $body, JSON_PRESERVE_ZERO_FRACTION );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			throw new ModelException( 'invalid_input', 'The original managed submission exceeds its admitted transport bound.' );
		}
		return (object) array( 'version' => 1, 'body_json' => $json, 'seal' => self::seal( $json, $route ) );
	}

	/** Read exact originally admitted bytes after binding site, connection and operation. */
	public static function body( object $envelope, array $route, string $request_id, string $operation_id ): array {
		if ( 1 !== ( $envelope->version ?? null ) || ! is_string( $envelope->body_json ?? null ) || strlen( $envelope->body_json ) > self::MAX_BYTES || ! is_string( $envelope->seal ?? null ) || ! hash_equals( self::seal( $envelope->body_json, $route ), $envelope->seal ) ) {
			throw new ModelException( 'managed_submission_invalid', 'The original managed submission cannot be verified. Its receipt is retained.' );
		}
		try {
			$decoded = json_decode( $envelope->body_json, false, 64, JSON_THROW_ON_ERROR );
			$body = $decoded instanceof \stdClass ? get_object_vars( $decoded ) : null;
		} catch ( \Throwable $error ) {
			throw new ModelException( 'managed_submission_invalid', 'The original managed submission cannot be decoded. Its receipt is retained.' );
		}
		if ( ! is_array( $body ) || ( $body['request_id'] ?? null ) !== $request_id || ( $body['operation_id'] ?? null ) !== $operation_id || 1 !== ( $body['schema_version'] ?? null ) || ! in_array( $body['type'] ?? null, array( 'chat', 'listing', 'layout', 'inspection', 'image' ), true ) || array_diff( array_keys( $body ), array( 'schema_version', 'request_id', 'operation_id', 'type', 'messages', 'max_output_tokens', 'tools', 'image' ) ) || ( 'image' !== $body['type'] && isset( $body['image'] ) ) ) {
			throw new ModelException( 'managed_submission_invalid', 'The original managed submission identity changed. Its receipt is retained.' );
		}
		return $body;
	}

	/** A native-owned envelope is valid only for this site's unchanged connection and feature. */
	private static function seal( string $json, array $route ): string {
		return hash_hmac( 'sha256', get_current_blog_id() . "\0" . ( $route['source'] ?? '' ) . "\0" . ( $route['feature'] ?? '' ) . "\0" . ( $route['managed_fingerprint'] ?? '' ) . "\0" . $json, wp_salt( 'auth' ) );
	}
}
