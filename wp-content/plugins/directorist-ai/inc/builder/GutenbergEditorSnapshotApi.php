<?php
/**
 * Authenticated Gutenberg editor evidence issuance routes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact REST boundary.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** A client may answer a server challenge; it cannot mint binding authority. */
final class GutenbergEditorSnapshotApi {
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/builder/gutenberg/editor-challenge',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'challenge' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/gutenberg/editor-snapshot',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'snapshot' ),
			)
		);
	}

	public function challenge( \WP_REST_Request $request ) {
		try {
			$directory_id  = $request->get_param( 'directory_id' );
			$document_ref  = (string) $request->get_param( 'document_ref' );
			$saved_content = $request->get_param( 'saved_content' );
			if ( str_starts_with( $document_ref, 'post:' ) && ( ! is_string( $saved_content ) || strlen( $saved_content ) > 5242880 ) ) {
				throw new ContractException( 'evidence_stale', 'Editor saved-source evidence is unavailable.' );
			}
			if ( null !== $directory_id ) {
				$directory_id = is_int( $directory_id ) ? $directory_id : ( ctype_digit( (string) $directory_id ) ? (int) $directory_id : 0 );
			}
			$challenge = GutenbergEditorSnapshotBinding::challenge(
				Context::current( 'builder', 'ui' ),
				(string) $request->get_param( 'document_kind' ),
				$directory_id,
				$document_ref,
				$this->origin(),
				is_string( $saved_content ) ? $saved_content : null
			);
			return rest_ensure_response( $challenge );
		} catch ( ContractException $error ) {
			return $this->error( $error );
		}
	}

	public function snapshot( \WP_REST_Request $request ) {
		try {
			$binding = GutenbergEditorSnapshotBinding::issue(
				Context::current( 'builder', 'ui' ),
				array(
					'binding_id'          => $request->get_param( 'binding_id' ),
					'nonce'               => $request->get_param( 'nonce' ),
					'origin'              => $this->origin(),
					'blocks'              => $request->get_param( 'blocks' ),
					'allowed_block_types' => $request->get_param( 'allowed_block_types' ),
					'group_variations'    => $request->get_param( 'group_variations' ),
					'document_blocks'     => $request->get_param( 'document_blocks' ),
					'editor_revision'     => $request->get_param( 'editor_revision' ),
				)
			);
			return rest_ensure_response(
				(object) array(
					'binding_id'  => $binding->binding_id(),
					'fingerprint' => $binding->fingerprint(),
				)
			);
		} catch ( ContractException $error ) {
			return $this->error( $error );
		}
	}

	private function origin(): string {
		$origin = get_http_origin();
		return is_string( $origin ) && '' !== $origin ? $origin : home_url( '/' );
	}

	private function error( ContractException $error ): \WP_Error {
		$status = 'unauthorized' === $error->error_code() ? 403 : 409;
		return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => $status ) );
	}
}
