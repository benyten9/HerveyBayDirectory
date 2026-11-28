<?php
/**
 * Authenticated Divi iframe evidence routes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact REST boundary.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** Issues and consumes short-lived Divi editor challenges. */
final class DiviEditorSnapshotApi {
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_capture' ), 20 );
	}

	/** Load only inside an editable Divi 5 app iframe. */
	public function enqueue_capture(): void {
		if ( ! isset( $_GET['et_fb'], $_GET['app_window'] ) || '1' !== $_GET['et_fb'] || '1' !== $_GET['app_window'] || ! is_user_logged_in() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$relative = 'inc/builder/divi-editor-capture.js';
		$path     = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . $relative;
		if ( ! is_file( $path ) ) {
			return;
		}
		wp_enqueue_script( 'directorist-ai-divi-editor-capture', plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . $relative, array(), (string) filemtime( $path ), true );
		wp_add_inline_script(
			'directorist-ai-divi-editor-capture',
			'window.directoristAIDiviEditorBoot=' . wp_json_encode(
				array(
					'endpoint'         => rest_url( 'directorist-ai/v1/builder/divi/' ),
					'nonce'            => wp_create_nonce( 'wp_rest' ),
					'documentRef'      => 'post:' . $post_id,
					'defaultKind'      => 'page' === get_post_type( $post_id ) ? 'page' : null,
					'contractRevision' => DiviEditorSnapshotBinding::CONTRACT_REVISION,
				)
			) . ';',
			'before'
		);
	}

	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/builder/divi/editor-challenge',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'challenge' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/divi/editor-snapshot',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'snapshot' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/divi/editor-current',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'current' ),
			)
		);
	}

	/** Check the saved document revision and editor epoch before reusing a binding. */
	public function current( \WP_REST_Request $request ) {
		try {
			$binding_id  = $request->get_param( 'binding_id' );
			$fingerprint = $request->get_param( 'fingerprint' );
			if ( ! is_string( $binding_id ) || ! is_string( $fingerprint ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
				throw new ContractException( 'evidence_stale', 'Divi editor binding identity is invalid.' );
			}
			$binding = DiviEditorSnapshotBinding::load( Context::current( 'builder', 'ui' ), $binding_id );
			if ( ! hash_equals( $binding->fingerprint(), $fingerprint ) ) {
				throw new ContractException( 'evidence_stale', 'Divi editor fingerprint changed.' );
			}
			return rest_ensure_response(
				(object) array(
					'binding_id'  => $binding_id,
					'fingerprint' => $fingerprint,
				)
			);
		} catch ( ContractException $error ) {
			return $this->error( $error );
		}
	}

	public function challenge( \WP_REST_Request $request ) {
		try {
			$directory_id = $request->get_param( 'directory_id' );
			$directory_id = null === $directory_id ? null : ( is_int( $directory_id ) ? $directory_id : ( ctype_digit( (string) $directory_id ) ? (int) $directory_id : 0 ) );
			return rest_ensure_response( DiviEditorSnapshotBinding::challenge( Context::current( 'builder', 'ui' ), (string) $request->get_param( 'document_kind' ), $directory_id, (string) $request->get_param( 'document_ref' ), $this->origin() ) );
		} catch ( ContractException $error ) {
			return $this->error( $error );
		}
	}

	public function snapshot( \WP_REST_Request $request ) {
		try {
			$flat_source = $request->get_param( 'flat_source' );
			if ( null !== $flat_source && 'server_hash' !== $flat_source ) {
				throw new ContractException( 'evidence_stale', 'Divi editor flat source is invalid.' );
			}
			$flat = $request->get_param( 'flat_modules' );
			if ( 'server_hash' === $flat_source ) {
				if ( null !== $flat ) {
					throw new ContractException( 'evidence_stale', 'Divi editor flat request is invalid.' );
				}
				$flat = 'server_hash';
			}
			$registry_source = $request->get_param( 'registry_source' );
			if ( null !== $registry_source && 'server_registered' !== $registry_source ) {
				throw new ContractException( 'evidence_stale', 'Divi editor registry source is invalid.' );
			}
			$allowed  = $request->get_param( 'allowed_modules' );
			$registry = $request->get_param( 'module_registry' );
			if ( 'server_registered' === $registry_source ) {
				if ( null !== $registry || ! is_array( $allowed ) ) {
					throw new ContractException( 'evidence_stale', 'Divi editor registry request is invalid.' );
				}
				$registry = 'server_registered';
			}
			$binding  = DiviEditorSnapshotBinding::issue(
				Context::current( 'builder', 'ui' ),
				array(
					'allowed_modules'      => $allowed,
					'binding_id'           => $request->get_param( 'binding_id' ),
					'contract_revision'    => $request->get_param( 'contract_revision' ),
					'document_type'        => $request->get_param( 'document_type' ),
					'flat_modules'         => $flat,
					'graph_source'         => $request->get_param( 'graph_source' ),
					'modal_state'          => $request->get_param( 'modal_state' ),
					'module_registry'      => $registry,
					'nonce'                => $request->get_param( 'nonce' ),
					'origin'               => $this->origin(),
					'source_view_key'      => $request->get_param( 'source_view_key' ),
					'state_by_source_view' => $request->get_param( 'state_by_source_view' ),
					'view_modes'           => $request->get_param( 'view_modes' ),
				)
			);
			$snapshot = $binding->snapshot();
			return rest_ensure_response(
				(object) array(
					'binding_id'        => $binding->binding_id(),
					'fingerprint'       => $binding->fingerprint(),
					'scope_status'      => $snapshot->scope_status,
					'scope_revision'    => $snapshot->scope_revision,
					'provider_revision' => $snapshot->provider_revision,
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
		return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => 'unauthorized' === $error->error_code() ? 403 : 409 ) );
	}
}
