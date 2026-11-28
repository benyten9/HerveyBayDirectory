<?php
/**
 * Recheck a reviewed open-editor apply at the native REST save boundary.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Closed native REST transaction boundary.

/** A voluntary reviewed-save header never grants WordPress edit authority. */
final class GutenbergReviewedSaveGuard {
	private const SAVEPOINT = 'dai_reviewed_editor_save';
	private bool $saving    = false;
	/**
	 * Reviewed draft receipt reader.
	 *
	 * @var BuilderDraftResultApi
	 */
	private BuilderDraftResultApi $drafts;

	/**
	 * Store the reviewed draft receipt reader.
	 *
	 * @param BuilderDraftResultApi $drafts Reviewed draft receipt reader.
	 */
	public function __construct( BuilderDraftResultApi $drafts ) {
		$this->drafts = $drafts;
	}

	/** Run the native controller after its permission callback, within an owned transaction. */
	public function dispatch( $response, \WP_REST_Request $request, string $route, array $handler ) {
		$live_operation = $request->get_header( 'x-directorist-ai-live-operation' );
		if ( is_string( $live_operation ) && '' !== $live_operation ) {
			return $this->dispatch_live( $response, $request, $handler, $live_operation );
		}
		$operation = $request->get_header( 'x-directorist-ai-reviewed-operation' );
		if ( ! is_string( $operation ) || '' === $operation || ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) || ! preg_match( '#^/wp/v2/pages/([1-9][0-9]*)$#D', $request->get_route(), $match ) ) {
			return $response;
		}
		$callback = $handler['callback'] ?? null;
		$id       = (int) $match[1];
		if ( null !== $response || $this->saving || ! is_array( $callback ) || ! ( $callback[0] ?? null ) instanceof \WP_REST_Posts_Controller || 'update_item' !== ( $callback[1] ?? null ) || ! current_user_can( 'edit_post', $id ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $operation ) ) {
			return $this->conflict();
		}
		// Additional document changes need their own review. Do not invoke native
		// metadata, assignment or extension field callbacks under a content review.
		$submitted = array_merge( $request->get_json_params() ?? array(), $request->get_body_params(), $request->get_query_params() );
		if ( array_diff( array_keys( $submitted ), array( 'id', 'content', 'context', '_fields', '_embed', '_locale', '_wpnonce', 'rest_route' ) ) ) {
			return $this->conflict();
		}
		$draft = $this->read_draft( $operation );
		if ( ! is_array( $draft ) || ( $draft['live_apply']['source_page_id'] ?? null ) !== $id || ! is_int( $draft['draft_post_id'] ?? null ) || ! GutenbergPageIdentity::supports_page( $id ) || ! GutenbergPageIdentity::supports_page( $draft['draft_post_id'] ) ) {
			return $this->conflict();
		}
		global $wpdb;
		$native    = $wpdb;
		$actor     = get_current_user_id();
		$blog      = get_current_blog_id();
		$candidate = $draft['draft_post_id'];
		$ids       = array( $id, $candidate );
		sort( $ids, SORT_NUMERIC );
		$started      = false;
		$this->saving = true;
		try {
			if ( ! $this->begin( $native, $ids ) ) {
				return new \WP_Error( 'directorist_ai_reviewed_save_unavailable', 'Reviewed saving needs an isolated transaction on the native WordPress tables. The page was not saved.', array( 'status' => 409 ) );
			}
			$started = true;
			foreach ( $ids as $post_id ) {
				clean_post_cache( $post_id );
			}
			$before = $this->snapshot( $native, $id );
			$copy   = $this->snapshot( $native, $candidate );
			$fresh  = $this->read_draft( $operation );
			if ( null === $before || null === $copy || ! is_array( $fresh ) || ( $fresh['draft_post_id'] ?? null ) !== $candidate || ! GutenbergPageIdentity::supports_page( $id ) || ! GutenbergPageIdentity::supports_page( $candidate ) || is_wp_error(
				$this->verify(
					(object) array(
						'ID'           => $id,
						'post_content' => $request['content'],
					),
					$request
				)
			) ) {
				return $this->conflict();
			}
			// The native controller retains its own schema, capability checks and hooks.
			// Its ordinary additional-field/metadata handling also stays inside the fence.
			$result = call_user_func( $callback, $request );
			$after  = $this->snapshot( $native, $id );
			if ( $wpdb !== $native || get_current_user_id() !== $actor || get_current_blog_id() !== $blog || null === $after || $copy !== $this->snapshot( $native, $candidate ) ) {
				return $this->unknown();
			}
			if ( is_wp_error( $result ) ) {
				return $before === $after ? $result : $this->unknown();
			}
			if ( ! $result instanceof \WP_REST_Response || $result->get_status() >= 400 || ! $this->matches( $before, $after, $copy['post']['post_content'] ) || ! current_user_can( 'edit_post', $id ) ) {
				return $this->unknown();
			}
			if ( false === $native->query( $native->prepare( 'RELEASE SAVEPOINT %i', self::SAVEPOINT ) ) || false === $native->query( 'COMMIT' ) ) {
				return $this->unknown();
			}
			clean_post_cache( $id );
			if ( $after !== $this->snapshot( $native, $id ) || $copy !== $this->snapshot( $native, $candidate ) ) {
				return $this->unknown();
			}
			$result->header( 'X-Directorist-AI-Reviewed-Save', 'verified' );
			return $result;
		} catch ( \Throwable $error ) {
			return $this->unknown();
		} finally {
			$errors    = $native->suppress_errors();
			$revisions = $started ? $native->get_col( $native->prepare( "SELECT ID FROM {$native->posts} WHERE post_parent=%d AND post_type='revision'", $id ) ) : array();
			if ( $started && false !== $native->query( $native->prepare( 'ROLLBACK TO SAVEPOINT %i', self::SAVEPOINT ) ) ) {
				// Clean revisions created in this transaction as well as the source.
				$native->query( 'ROLLBACK' );
			}
			foreach ( $revisions as $revision_id ) {
				clean_post_cache( (int) $revision_id );
			}
			$native->suppress_errors( $errors );
			foreach ( $ids as $post_id ) {
				clean_post_cache( $post_id );
			}
			$this->saving = false;
		}
	}

	/** Refuse existing transactions rather than implicitly committing another caller's work. */
	private function begin( \wpdb $native, array $ids ): bool {
		$engines = $native->get_col( $native->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s)', $native->posts, $native->postmeta, $native->term_relationships ) );
		if ( '' !== $native->last_error || 3 !== count( $engines ) || array_filter( $engines, static fn( string $engine ): bool => 'innodb' !== strtolower( $engine ) ) ) {
			return false;
		}
		$errors  = $native->suppress_errors();
		$started = false;
		try {
			if ( false === $native->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $native->query( 'START TRANSACTION' ) ) {
				return false;
			}
			$started = true;
			if ( false === $native->query( $native->prepare( 'SAVEPOINT %i', self::SAVEPOINT ) ) ) {
				$native->query( 'ROLLBACK' );
				return false;
			}
			foreach ( $ids as $id ) {
				if ( false === $native->query( $native->prepare( "SELECT ID FROM {$native->posts} WHERE ID=%d FOR UPDATE", $id ) ) || false === $native->query( $native->prepare( "SELECT meta_id FROM {$native->postmeta} WHERE post_id=%d FOR UPDATE", $id ) ) || false === $native->query( $native->prepare( "SELECT term_taxonomy_id FROM {$native->term_relationships} WHERE object_id=%d FOR UPDATE", $id ) ) ) {
					$native->query( 'ROLLBACK' );
					return false;
				}
			}
			return true;
		} catch ( \Throwable $error ) {
			if ( $started ) {
				$native->query( 'ROLLBACK' );
			}
			return false;
		} finally {
			$native->suppress_errors( $errors );
		}
	}

	/** Read complete native state without allowing object-cache drift to hide changes. */
	private function snapshot( \wpdb $native, int $id ): ?array {
		$post = $native->get_row( $native->prepare( "SELECT * FROM {$native->posts} WHERE ID=%d", $id ), ARRAY_A );
		if ( ! is_array( $post ) || '' !== $native->last_error ) {
			return null;
		}
		$meta = $native->get_results( $native->prepare( "SELECT meta_key,meta_value FROM {$native->postmeta} WHERE post_id=%d ORDER BY meta_key,meta_id", $id ), ARRAY_A );
		if ( '' !== $native->last_error || count( $meta ) > 2000 ) {
			return null;
		}
		$terms  = $native->get_results( $native->prepare( "SELECT term_taxonomy_id,term_order FROM {$native->term_relationships} WHERE object_id=%d ORDER BY term_taxonomy_id", $id ), ARRAY_A );
		$result = array(
			'post'  => $post,
			'meta'  => $meta,
			'terms' => $terms,
		);
		return '' === $native->last_error && strlen( wp_json_encode( $result ) ) <= 5242880 ? $result : null;
	}

	/** Content is the only reviewed document effect; native edit bookkeeping is permitted. */
	private function matches( array $before, array $after, string $content ): bool {
		if ( ! ( new GutenbergReviewedContentMatcher() )->matches( $content, $after['post']['post_content'] ) || $before['terms'] !== $after['terms'] ) {
			return false;
		}
		// wp_update_post advances an undated draft's local date on each save.
		// Explicit date changes are excluded at dispatch; accept only that native
		// behavior, while preserving scheduled/published or explicitly dated pages.
		if ( in_array( $before['post']['post_status'], array( 'draft', 'pending', 'auto-draft' ), true ) && '0000-00-00 00:00:00' === $before['post']['post_date_gmt'] && $after['post']['post_date'] === $after['post']['post_modified'] ) {
			unset( $before['post']['post_date'], $after['post']['post_date'] );
		}
		foreach ( array( 'post_content', 'post_modified', 'post_modified_gmt' ) as $field ) {
			unset( $before['post'][ $field ], $after['post'][ $field ] );
		}
		$meta = static fn( array $rows ): array => array_values( array_filter( $rows, static fn( array $row ): bool => ! in_array( $row['meta_key'], array( '_edit_lock', '_edit_last' ), true ) ) );
		return $before['post'] === $after['post'] && $meta( $before['meta'] ) === $meta( $after['meta'] );
	}

	private function read_draft( string $operation ): array|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/drafts/' . $operation );
		$request->set_param( 'operation_id', $operation );
		return $this->drafts->read( $request );
	}

	/** Database rollback cannot reverse arbitrary native hook notifications or effects. */
	private function unknown(): \WP_Error {
		return new \WP_Error(
			'directorist_ai_reviewed_save_unknown',
			'The native save could not be verified. Reload the saved page before trying again. External hook effects cannot be confirmed or reversed.',
			array(
				'status'           => 500,
				'native_state'     => 'unverified',
				'external_effects' => 'unverified_not_reversible',
			)
		);
	}

	/**
	 * Stop an editor autosave before WordPress can update an author-owned draft.
	 *
	 * The native autosaves controller does not honor a WP_Error returned from
	 * rest_pre_insert_page, so this must run before its callback.
	 *
	 * @param mixed            $response Existing REST response.
	 * @param array            $handler Matched native route handler.
	 * @param \WP_REST_Request $request Native REST request.
	 * @return mixed Existing response or guarded conflict.
	 */
	public function intercept_autosave( $response, array $handler, \WP_REST_Request $request ) {
		$operation = $request->get_header( 'x-directorist-ai-reviewed-operation' );
		$live      = $request->get_header( 'x-directorist-ai-live-operation' );
		if ( ! is_wp_error( $response ) && ( ( is_string( $operation ) && '' !== $operation ) || ( is_string( $live ) && '' !== $live ) ) && 'POST' === $request->get_method() && preg_match( '#^/wp/v2/pages/[1-9][0-9]*/autosaves$#D', $request->get_route() ) ) {
			return $this->conflict();
		}
		return $response;
	}

	/**
	 * Reject a stale or modified reviewed candidate before WordPress updates the page.
	 *
	 * @param object|\WP_Error $prepared Prepared native page update.
	 * @param \WP_REST_Request $request Native page request.
	 * @return object|\WP_Error Prepared update or conflict.
	 */
	public function verify( $prepared, \WP_REST_Request $request ) {
		$live_operation = $request->get_header( 'x-directorist-ai-live-operation' );
		if ( is_string( $live_operation ) && '' !== $live_operation ) {
			return $this->verify_live( $prepared, $request, $live_operation );
		}
		$operation = $request->get_header( 'x-directorist-ai-reviewed-operation' );
		if ( ! is_string( $operation ) || '' === $operation || is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( ! $prepared instanceof \stdClass || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $operation ) || ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) || ! preg_match( '#^/wp/v2/pages/([1-9][0-9]*)$#D', $request->get_route(), $match ) || (int) ( $prepared->ID ?? 0 ) !== (int) $match[1] || ! current_user_can( 'edit_post', (int) $match[1] ) ) {
			return $this->conflict();
		}
		$draft_request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/drafts/' . $operation );
		$draft_request->set_param( 'operation_id', $operation );
		$draft     = $this->drafts->read( $draft_request );
		$permit    = is_array( $draft ) ? ( $draft['live_apply'] ?? null ) : null;
		$candidate = is_array( $draft ) && is_int( $draft['draft_post_id'] ?? null ) ? get_post( $draft['draft_post_id'] ) : null;
		if ( ! is_array( $permit ) || ( $draft['operation_id'] ?? null ) !== $operation || ( $permit['source_page_id'] ?? null ) !== (int) $match[1] || ! $candidate instanceof \WP_Post || ! is_string( $prepared->post_content ?? null ) || ! ( new GutenbergReviewedContentMatcher() )->matches( (string) $candidate->post_content, $prepared->post_content ) ) {
			return $this->conflict();
		}
		return $prepared;
	}

	/** Execute one separately approved live ticket through the same locked native controller. */
	private function dispatch_live( $response, \WP_REST_Request $request, array $handler, string $operation ) {
		$callback = $handler['callback'] ?? null;
		if ( null !== $response || $this->saving || $request->get_header( 'x-directorist-ai-reviewed-operation' ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $operation ) || ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) || ! preg_match( '#^/wp/v2/pages/([1-9][0-9]*)$#D', $request->get_route(), $match ) || ! is_array( $callback ) || ! ( $callback[0] ?? null ) instanceof \WP_REST_Posts_Controller || 'update_item' !== ( $callback[1] ?? null ) ) {
			return $this->conflict();
		}
		$id        = (int) $match[1];
		$submitted = array_merge( $request->get_json_params() ?? array(), $request->get_body_params(), $request->get_query_params() );
		if ( ! current_user_can( 'edit_post', $id ) || array_diff( array_keys( $submitted ), array( 'id', 'content', 'context', '_fields', '_embed', '_locale', '_wpnonce', 'rest_route' ) ) || ! is_string( $request['content'] ?? null ) ) {
			return $this->conflict();
		}
		$ticket     = apply_filters( 'directorist_ai_gutenberg_live_ticket', null, $operation, $id );
		$inspection = is_array( $ticket ) ? ( $ticket['inspection'] ?? null ) : null;
		$impact     = is_array( $ticket ) ? ( $ticket['impact_hash'] ?? null ) : null;
		$draft_id   = $inspection->draft_id ?? null;
		if ( ! $inspection instanceof \stdClass || ! is_int( $draft_id ) || $draft_id < 1 || ! is_string( $impact ) || ! preg_match( '/^[a-f0-9]{64}$/D', $impact ) || ! GutenbergPageIdentity::supports_page( $id ) || ! GutenbergPageIdentity::supports_page( $draft_id ) ) {
			return $this->conflict();
		}
		global $wpdb;
		$native = $wpdb;
		$actor  = get_current_user_id();
		$blog   = get_current_blog_id();
		$ids    = array( $id, $draft_id );
		sort( $ids, SORT_NUMERIC );
		$started      = false;
		$this->saving = true;
		try {
			if ( ! $this->begin( $native, $ids ) ) {
				return new \WP_Error( 'directorist_ai_reviewed_save_unavailable', 'The reviewed native Save transaction is unavailable.', array( 'status' => 409 ) );
			}
			$started = true;
			foreach ( $ids as $post_id ) {
				clean_post_cache( $post_id );
			}
			$before = $this->snapshot( $native, $id );
			$copy   = $this->snapshot( $native, $draft_id );
			$fresh  = apply_filters( 'directorist_ai_gutenberg_live_ticket', null, $operation, $id );
			if ( null === $before || null === $copy || ! is_array( $fresh ) || wp_json_encode( $fresh ) !== wp_json_encode( $ticket ) || ! apply_filters( 'directorist_ai_builder_scoped_live_ticket_valid', false, $id, $operation, $impact, $inspection ) || ! $this->live_preimage( $before, $copy, $inspection ) || is_wp_error(
				$this->verify_live(
					(object) array(
						'ID'           => $id,
						'post_content' => $request['content'],
					),
					$request,
					$operation
				)
			) ) {
				return $this->conflict();
			}
			$result = call_user_func( $callback, $request );
			$after  = $this->snapshot( $native, $id );
			if ( $wpdb !== $native || get_current_user_id() !== $actor || get_current_blog_id() !== $blog || null === $after || $copy !== $this->snapshot( $native, $draft_id ) ) {
				return $this->unknown();
			}
			if ( is_wp_error( $result ) ) {
				return $before === $after ? $result : $this->unknown();
			}
			$matcher = new GutenbergReviewedContentMatcher();
			if ( ! $result instanceof \WP_REST_Response || $result->get_status() >= 400 || ! $this->matches( $before, $after, $copy['post']['post_content'] ) || ! hash_equals( $inspection->patch->after_tree_hash, (string) $matcher->fingerprint( $after['post']['post_content'] ) ) || ! current_user_can( 'edit_post', $id ) ) {
				return $this->unknown();
			}
			if ( true !== apply_filters( 'directorist_ai_gutenberg_live_mark', false, $ticket ) ) {
				return $this->unknown();
			}
			$marked    = $this->snapshot( $native, $id );
			$remaining = is_array( $marked ) ? $marked['meta'] : array();
			$receipt   = null;
			foreach ( $remaining as $index => $row ) {
				$decoded = '_directorist_ai_gutenberg_live_receipt' === ( $row['meta_key'] ?? null ) ? json_decode( $row['meta_value'], true ) : null;
				if ( is_array( $decoded ) && ( $decoded['operation_id'] ?? null ) === $operation ) {
					if ( null !== $receipt ) {
						return $this->unknown();
					}
					$receipt = $decoded;
					unset( $remaining[ $index ] );
				}
			}
			if ( ! is_array( $marked ) || $marked['post'] !== $after['post'] || $marked['terms'] !== $after['terms'] || array_values( $remaining ) !== $after['meta'] || ! is_array( $receipt ) || ( $receipt['impact_hash'] ?? null ) !== $impact ) {
				return $this->unknown();
			}
			if ( false === $native->query( $native->prepare( 'RELEASE SAVEPOINT %i', self::SAVEPOINT ) ) || false === $native->query( 'COMMIT' ) ) {
				return $this->unknown();
			}
			clean_post_cache( $id );
			if ( $marked !== $this->snapshot( $native, $id ) || $copy !== $this->snapshot( $native, $draft_id ) ) {
				return $this->unknown();
			}
			$result->header( 'X-Directorist-AI-Live-Save', 'verified' );
			return $result;
		} catch ( \Throwable $error ) {
			return $this->unknown();
		} finally {
			$errors    = $native->suppress_errors();
			$revisions = $started ? $native->get_col( $native->prepare( "SELECT ID FROM {$native->posts} WHERE post_parent=%d AND post_type='revision'", $id ) ) : array();
			if ( $started && false !== $native->query( $native->prepare( 'ROLLBACK TO SAVEPOINT %i', self::SAVEPOINT ) ) ) {
				$native->query( 'ROLLBACK' );
			}
			foreach ( $revisions as $revision_id ) {
				clean_post_cache( (int) $revision_id );
			}
			$native->suppress_errors( $errors );
			foreach ( $ids as $post_id ) {
				clean_post_cache( $post_id );
			}
			$this->saving = false;
		}
	}

	/** Check the locked native post and isolated candidate against the issued inspection. */
	private function live_preimage( array $before, array $copy, object $inspection ): bool {
		$matcher = new GutenbergReviewedContentMatcher();
		return (int) $before['post']['ID'] === $inspection->source_id && (int) $copy['post']['ID'] === $inspection->draft_id && $before['post']['post_status'] === $inspection->source_status && 'draft' === $copy['post']['post_status'] && hash_equals( $inspection->source_revision, hash( 'sha256', wp_json_encode( array( (int) $before['post']['ID'], $before['post']['post_status'], $before['post']['post_modified_gmt'], $inspection->patch->native->source_content_hash ) ) ) ) && hash_equals( $inspection->patch->native->source_content_hash, hash( 'sha256', $before['post']['post_content'] ) ) && hash_equals( $inspection->patch->native->candidate_content_hash, hash( 'sha256', $copy['post']['post_content'] ) ) && hash_equals( $inspection->patch->before_tree_hash, (string) $matcher->fingerprint( $before['post']['post_content'] ) ) && hash_equals( $inspection->patch->after_tree_hash, (string) $matcher->fingerprint( $copy['post']['post_content'] ) );
	}

	/** The native controller calls this before writing; the locked dispatch repeats it. */
	private function verify_live( $prepared, \WP_REST_Request $request, string $operation ) {
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( ! $prepared instanceof \stdClass || ! preg_match( '#^/wp/v2/pages/([1-9][0-9]*)$#D', $request->get_route(), $match ) || (int) ( $prepared->ID ?? 0 ) !== (int) $match[1] || ! is_string( $prepared->post_content ?? null ) ) {
			return $this->conflict();
		}
		$ticket     = apply_filters( 'directorist_ai_gutenberg_live_ticket', null, $operation, (int) $match[1] );
		$inspection = is_array( $ticket ) ? ( $ticket['inspection'] ?? null ) : null;
		$draft      = $inspection instanceof \stdClass ? get_post( $inspection->draft_id ) : null;
		if ( ! $inspection instanceof \stdClass || ! $draft instanceof \WP_Post || ! current_user_can( 'edit_post', (int) $match[1] ) || ! ( new GutenbergReviewedContentMatcher() )->matches( $draft->post_content, $prepared->post_content ) ) {
			return $this->conflict();
		}
		return $prepared;
	}

	/**
	 * Return an exact reviewed-save conflict.
	 *
	 * @return \WP_Error Exact reviewed-save conflict.
	 */
	private function conflict(): \WP_Error {
		return new \WP_Error( 'directorist_ai_reviewed_save_conflict', 'The reviewed draft or saved page changed. Refresh the editor and review the draft again.', array( 'status' => 409 ) );
	}
}
