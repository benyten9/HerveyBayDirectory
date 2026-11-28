<?php
/**
 * Keep DirectoristAI native drafts private during editor saves.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

/** Drafts remain private until a separately reviewed publish operation exists. */
final class BuilderTemplateDraftSaveGuard {
	/**
	 * Request-local native publication permits.
	 *
	 * @var array<int,bool>
	 */
	private static array $publication = array();

	/**
	 * Allow only the native post selected by a trusted reviewed effect handler.
	 *
	 * @param int      $id Reviewed post ID.
	 * @param callable $save Native save callback, supplied by trusted PHP only.
	 * @return mixed Native callback result.
	 */
	public static function publish_reviewed( int $id, callable $save ) {
		$previous                 = self::$publication;
		self::$publication[ $id ] = true;
		try {
			return $save();
		} finally {
			self::$publication = $previous;
		}
	}
	/**
	 * Preserve a marked native draft's status until an explicit promotion workflow exists.
	 *
	 * @param array $data Sanitized post data.
	 * @param array $postarr Post update input.
	 * @param array $unsanitized_postarr Original post input.
	 * @param bool  $update Whether WordPress is updating a post.
	 * @return array Post data with protected draft status.
	 */
	public function preserve_draft( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		if ( ! $update || ! in_array( $data['post_type'] ?? null, array( 'page', 'wp_template', 'wp_template_part', 'et_body_layout', 'elementor_library' ), true ) || in_array( $data['post_status'] ?? null, array( 'draft', 'trash' ), true ) ) {
			return $data;
		}
		if ( $this->protected_draft( (int) ( $postarr['ID'] ?? 0 ), $data['post_type'] ) ) {
			$data['post_status'] = 'draft';
		}
		return $data;
	}

	/**
	 * Give explicit native Publish a clear error instead of a false success.
	 *
	 * @param object|\WP_Error $prepared Prepared REST template update.
	 * @param \WP_REST_Request $request Native template REST request.
	 * @return object|\WP_Error Valid update or a protected-draft error.
	 */
	public function reject_publish( $prepared, \WP_REST_Request $request ) {
		if ( is_wp_error( $prepared ) || ! $prepared instanceof \stdClass || 'publish' !== $request->get_param( 'status' ) ) {
			return $prepared;
		}
		$post = get_post( (int) ( $prepared->ID ?? 0 ) );
		if ( $post instanceof \WP_Post && $this->protected_draft( $post->ID, $post->post_type ) ) {
			return new \WP_Error( 'directorist_ai_draft_publish_blocked', 'This AI review draft cannot be published directly. Save the draft and apply it through a reviewed release workflow.', array( 'status' => 409 ) );
		}
		return $prepared;
	}

	/**
	 * Recognize an operation-bound native draft even after the owner edits it.
	 *
	 * @param int    $id Native post ID.
	 * @param string $type Template post type.
	 * @return bool Whether this is an AI review draft.
	 */
	private function protected_draft( int $id, string $type ): bool {
		if ( isset( self::$publication[ $id ] ) ) {
			return false;
		}
		$post = 0 < $id ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'draft' !== $post->post_status || $post->post_type !== $type ) {
			return false;
		}
		if ( 'elementor_library' === $type ) {
			return is_string( get_post_meta( $id, '_directorist_ai_elementor_draft_operation', true ) ) && '' !== get_post_meta( $id, '_directorist_ai_elementor_draft_operation', true );
		}
		if ( 'page' === $type || 'et_body_layout' === $type ) {
			$keys = 'page' === $type
				? array( '_directorist_ai_draft_operation', '_directorist_ai_elementor_draft_operation', '_directorist_ai_divi_draft_operation' )
				: array( '_directorist_ai_divi_draft_operation' );
			foreach ( $keys as $key ) {
				$operation = get_post_meta( $id, $key, true );
				if ( is_string( $operation ) && '' !== $operation ) {
					return true;
				}
			}
			return false;
		}
		$operation = get_post_meta( $id, '_directorist_ai_draft_operation', true );
		$source    = get_post_meta( $id, '_directorist_ai_draft_source_ref', true );
		$slug      = is_string( $operation ) ? 'directorist-ai-draft-' . substr( hash( 'sha256', get_current_blog_id() . '|' . $operation ), 0, 24 ) : '';
		if ( ! is_string( $operation ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,128}$/D', $operation ) || ! hash_equals( $slug, $post->post_name ) || ! is_string( $source ) || ! preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):[A-Za-z0-9_.-]+$/D', $source, $match ) || $post->post_type !== $match[1] ) {
			return false;
		}
		return true;
	}
}
