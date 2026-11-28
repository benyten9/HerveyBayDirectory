<?php
/**
 * Authenticated Elementor editor evidence issuance routes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact REST boundary.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Issues and consumes short-lived Elementor editor challenges. */
final class ElementorEditorSnapshotApi {
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) ); }

	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/builder/elementor/editor-challenge',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'challenge' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/elementor/editor-snapshot',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'snapshot' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/elementor/editor-close',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'close' ),
			)
		);
	}

	public function challenge( \WP_REST_Request $request ) {
		try {
			$input = $this->object( $request, array( 'directory_id', 'generation', 'session', 'v' ), false );
			if ( 2 !== $input->v || ! is_string( $input->session ) || ! is_int( $input->generation ) || ( null !== $input->directory_id && ( ! is_int( $input->directory_id ) || $input->directory_id < 1 ) ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor editor request is invalid.' );
			}
			$context                  = Context::current( 'builder', 'ui' );
			list( $record, $pointer ) = ( new ElementorEditorSession() )->current( $context, $input->session );
			if ( $pointer['generation'] !== $input->generation ) {
				throw new ContractException( 'evidence_stale', 'Elementor editor generation is stale.' );
			}
			$this->assert_origin( $record['origin'] );
			return rest_ensure_response( ElementorEditorSnapshotBinding::challenge( $context, $record['kind'], $input->directory_id, 'post:' . $record['main'], $record['origin'], $input->session, $input->generation ) );
		} catch ( ContractException $error ) {
			return $this->error( $error ); }
	}

	public function snapshot( \WP_REST_Request $request ) {
		try {
			$wire = $this->object( $request, array( 'allowed_elements', 'binding_id', 'breakpoints', 'contract_revision', 'document_elements', 'document_type', 'elements', 'generation', 'nonce', 'session', 'v' ), false );
			if ( 2 !== $wire->v || ! is_string( $wire->session ) || ! is_int( $wire->generation ) || ! is_string( $wire->binding_id ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor editor request is invalid.' );
			}
			$context                  = Context::current( 'builder', 'ui' );
			list( $record, $pointer ) = ( new ElementorEditorSession() )->current( $context, $wire->session );
			$this->assert_origin( $record['origin'] );
			if ( 'pending' !== $pointer['phase'] || $pointer['generation'] !== $wire->generation || $pointer['capture_id'] !== $wire->binding_id ) {
				throw new ContractException( 'evidence_stale', 'Elementor editor capture is stale.' );
			}
			$input   = array(
				'binding_id'        => $wire->binding_id,
				'nonce'             => $wire->nonce,
				'origin'            => $record['origin'],
				'elements'          => $this->metadata_array( $wire->elements ),
				'allowed_elements'  => $this->native_array( $wire->allowed_elements ),
				'breakpoints'       => $this->metadata_array( $wire->breakpoints ),
				'contract_revision' => $wire->contract_revision,
				'document_type'     => $wire->document_type,
				'document_elements' => $this->native_array( $wire->document_elements ),
			);
			$binding = ElementorEditorSnapshotBinding::issue(
				$context,
				$input
			);
			return rest_ensure_response(
				(object) array(
					'binding_id'    => $binding->binding_id(),
					'fingerprint'   => $binding->fingerprint(),
					'provider_lane' => $binding->snapshot()->provider_lane,
					'generation'    => $wire->generation,
				)
			);
		} catch ( ContractException $error ) {
			return $this->error( $error ); }
	}

	/** Keep wire JSON object/list identity before WordPress's associative parameter decoding. */
	private function wire_json( \WP_REST_Request $request ): ?\stdClass {
		$body = $request->get_body();
		if ( null === $body || '' === $body ) {
			return null;
		}
		if ( strlen( $body ) > 2097152 ) {
			throw new ContractException( 'needs_expansion', 'Elementor editor JSON exceeds its byte bound.' );
		}
		try {
			$decoded = ( new JsonDecoder( 2097152, 40 ) )->decode( $body );
			if ( ! $decoded instanceof \stdClass ) {
				throw new \RuntimeException( 'Invalid document envelope.' );
			}
			return $decoded;
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Elementor dirty document JSON is invalid.' );
		}
	}

	public function close( \WP_REST_Request $request ) {
		try {
			$wire = $this->object( $request, array( 'generation', 'session', 'v' ), false );
			if ( 2 !== $wire->v || ! is_string( $wire->session ) || ! is_int( $wire->generation ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor editor close is invalid.' );
			}
			$context                  = Context::current( 'builder', 'ui' );
			list( $record, $pointer ) = ( new ElementorEditorSession() )->current( $context, $wire->session );
			$this->assert_origin( $record['origin'] );
			if ( $pointer['generation'] !== $wire->generation || ! ( new ElementorEditorSessionStore() )->close( $record, $wire->generation ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor editor close is stale.' );
			}
			return rest_ensure_response( (object) array( 'closed' => true ) );
		} catch ( ContractException $error ) {
			return $this->error( $error );
		}
	}

	private function object( \WP_REST_Request $request, array $expected, bool $require_body ): \stdClass {
		$wire = $this->wire_json( $request );
		if ( null === $wire ) {
			if ( $require_body ) {
				throw new ContractException( 'evidence_stale', 'Elementor editor JSON is required.' );
			}
			// Native in-process fixtures may pass typed PHP values with an empty body.
			$wire = (object) $request->get_body_params();
		}
		$keys = array_keys( get_object_vars( $wire ) );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor request is invalid.' );
		}
		return $wire;
	}

	private function native_array( $value ): array {
		if ( ! is_array( $value ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor array is invalid.' );
		}
		return $value;
	}

	private function metadata_array( $value ): array {
		if ( ! is_array( $value ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor metadata is invalid.' );
		}
		try {
			$wire = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
			if ( strlen( $wire ) > 524288 ) {
				throw new \RuntimeException( 'Metadata bound.' );
			}
			$result = json_decode( $wire, true, 20, JSON_THROW_ON_ERROR );
			if ( ! is_array( $result ) ) {
				throw new \RuntimeException( 'Metadata shape.' );
			}
			return $result;
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor metadata is invalid.' );
		}
	}

	private function assert_origin( string $expected ): void {
		$origin = get_http_origin();
		if ( is_string( $origin ) && '' !== $origin ) {
			$root   = wp_parse_url( $expected );
			$actual = wp_parse_url( $origin );
			if ( ! is_array( $root ) || ! is_array( $actual ) || strtolower( (string) ( $root['scheme'] ?? '' ) ) !== strtolower( (string) ( $actual['scheme'] ?? '' ) ) || strtolower( (string) ( $root['host'] ?? '' ) ) !== strtolower( (string) ( $actual['host'] ?? '' ) ) || (int) ( $root['port'] ?? 0 ) !== (int) ( $actual['port'] ?? 0 ) ) {
				throw new ContractException( 'unauthorized', 'Elementor editor origin is invalid.' );
			}
		}
	}

	private function origin(): string {
		$origin = get_http_origin();
		return is_string( $origin ) && '' !== $origin ? $origin : home_url( '/' ); }
	private function error( ContractException $error ): \WP_Error {
		return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => 'unauthorized' === $error->error_code() ? 403 : 409 ) ); }
}
