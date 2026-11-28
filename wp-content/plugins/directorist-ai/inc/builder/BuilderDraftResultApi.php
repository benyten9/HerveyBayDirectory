<?php
/**
 * Owned read-only link to a successfully staged native builder draft.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Narrow REST read boundary.

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\JsonDecoder;

/** The operation receipt locates a draft; current WordPress caps still decide access. */
final class BuilderDraftResultApi {
	private Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/builder/drafts/(?P<operation_id>[A-Za-z0-9_.:-]{1,128})',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'read' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/publications/(?P<operation_id>[A-Za-z0-9_.:-]{1,128})',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'publication' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/assignments/(?P<operation_id>[A-Za-z0-9_.:-]{1,128})',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'assignment' ),
			)
		);
	}

	/** Read only the exact owned native template-assignment result. */
	public function assignment( \WP_REST_Request $request ): array|\WP_Error {
		return ( new BuilderTemplateAssignmentResult( $this->db ) )->read( $request );
	}

	/** An owned successful receipt is historical proof; current native state is read separately. */
	public function publication( \WP_REST_Request $request ): array|\WP_Error {
		$user      = get_current_user_id();
		$operation = (string) $request['operation_id'];
		if ( $user < 1 || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $operation ) ) {
			return $this->publication_unavailable();
		}
		try {
			$operations    = $this->db->table( 'operations' );
			$runs          = $this->db->table( 'runs' );
			$conversations = $this->db->table( 'conversations' );
			$rows          = $this->db->rows( "SELECT o.receipt_json FROM $operations o INNER JOIN $runs r ON r.id=o.run_id AND r.owner_ref=o.actor_ref INNER JOIN $conversations c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE o.public_id=%s AND o.actor_ref=%s AND ((o.capability='builder.publish' AND o.capability_revision='3') OR (o.capability='builder.restore' AND o.capability_revision='1')) AND o.state='succeeded' AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) LIMIT 2", array( $operation, 'user:' . $user ) );
			if ( 1 !== count( $rows ) || ! is_string( $rows[0]['receipt_json'] ) || strlen( $rows[0]['receipt_json'] ) > 1048576 ) {
				return $this->publication_unavailable();
			}
			$receipt = ( new JsonDecoder( 1048576, 32 ) )->decode( $rows[0]['receipt_json'] );
			$plan    = $receipt->plan ?? null;
			$targets = ( $receipt->targets ?? null ) instanceof \stdClass ? get_object_vars( $receipt->targets ) : array();
			$target  = 1 === count( $targets ) ? reset( $targets ) : null;
			$id      = $target->effects->page_id ?? null;
			if ( ! $receipt instanceof \stdClass || ( $receipt->state ?? null ) !== 'succeeded' || ( $receipt->operation_id ?? null ) !== $operation || ! $plan instanceof \stdClass || ( $plan->operation_id ?? null ) !== $operation || ( $plan->actor_ref ?? null ) !== 'user:' . $user || ! ( ( ( $plan->capability ?? null ) === 'builder.publish' && ( $plan->capability_revision ?? null ) === '3' ) || ( ( $plan->capability ?? null ) === 'builder.restore' && ( $plan->capability_revision ?? null ) === '1' ) ) || ! is_array( $plan->targets ?? null ) || 1 !== count( $plan->targets ) || ! $target instanceof \stdClass || ( $target->status ?? null ) !== 'succeeded' || ! is_int( $id ) || $id < 1 || ( $target->key ?? null ) !== 'builder-page:' . $id || ( $plan->targets[0]->key ?? null ) !== $target->key ) {
				return $this->publication_unavailable();
			}
			$exact = new \DirectoristAIPro\Effects\EffectPlan( $operation, $plan->actor_ref, $plan->capability, $plan->capability_revision, $plan->proposal_hash, array( new \DirectoristAIPro\Effects\EffectTarget( $plan->targets[0]->key, $plan->targets[0]->expected_version, $plan->targets[0]->effect_hash ) ), $plan->run_id, $plan->approval_id );
			clean_post_cache( $id );
			$restored = 'builder.restore' === $plan->capability;
			$marker   = get_post_meta( $id, $restored ? '_directorist_ai_page_restoration' : '_directorist_ai_page_publication', true );
			$post     = get_post( $id );
			if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) || (int) $post->post_author !== $user || ! current_user_can( 'edit_post', $id ) || ! is_array( $marker ) || ( $marker['operation_id'] ?? null ) !== $operation || ( $marker['plan_hash'] ?? null ) !== $exact->hash() || ( $receipt->plan_hash ?? null ) !== $exact->hash() || ! is_string( $target->after_version ?? null ) || ( $marker['version'] ?? null ) !== $target->after_version || ! in_array( $target->effects->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) || ( $marker['builder'] ?? null ) !== $target->effects->builder || ! is_string( $target->effects->dependency_version ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $target->effects->dependency_version ) || ( $marker['dependency_version'] ?? null ) !== $target->effects->dependency_version || ! is_string( $target->effects->draft_operation_id ?? null ) || ( $marker['draft_operation_id'] ?? null ) !== $target->effects->draft_operation_id ) {
				return $this->publication_unavailable();
			}
			$version   = BuilderPagePublication::native_version( $id );
			$edit_url  = get_edit_post_link( $id, 'raw' );
			$edit_base = admin_url();
			if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) ) {
				$edit_url = $this->elementor_editor_url( $post );
			} elseif ( 'on' === get_post_meta( $id, '_et_pb_use_builder', true ) ) {
				$edit_url  = $this->divi_editor_url( $post );
				$edit_base = home_url();
			}
			$current = ( $restored ? 'draft' : 'publish' ) === $post->post_status && hash_equals( $target->after_version, $version );
			if ( $current ) {
				try {
					$adapters = BuilderPagePublicationRegistry::installed();
					$current  = $adapters->qualify( $id, $target->effects->draft_operation_id ) === $target->effects->builder && hash_equals( $target->effects->dependency_version, $adapters->dependency_version( $target->effects->builder, $id ) );
				} catch ( \Throwable $error ) {
					$current = false;
				}
			}
			return array(
				'operation_id'           => $operation,
				'page_id'                => $id,
				'title'                  => $post->post_title,
				'status'                 => $post->post_status,
				'published_at_operation' => ! $restored,
				'restored_at_operation'  => $restored,
				'reviewed_state_current' => $current,
				'edit_url'               => $this->site_url( $edit_url, $edit_base ),
				'view_url'               => 'publish' === $post->post_status ? $this->site_url( get_permalink( $id ), home_url() ) : null,
			);
		} catch ( \Throwable $error ) {
			return $this->publication_unavailable();
		}
	}

	/** Do not let native link filters turn owned result actions into external links. */
	private function site_url( $url, string $base ): ?string {
		if ( ! is_string( $url ) || '' === $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( $base, PHP_URL_HOST ) || wp_parse_url( $url, PHP_URL_PORT ) !== wp_parse_url( $base, PHP_URL_PORT ) || null !== wp_parse_url( $url, PHP_URL_USER ) || null !== wp_parse_url( $url, PHP_URL_PASS ) ) {
			return null;
		}
		return $url;
	}

	private function publication_unavailable(): \WP_Error {
		return new \WP_Error( 'not_found', 'The owned page publication result is unavailable.', array( 'status' => 404 ) );
	}

	/** Recognize a native template's receipt; never expose another owner's AI state. */
	public function has_elementor_family_receipt( int $post_id, string $kind, int $parent_id, ?int $directory ): bool {
		$post      = get_post( $post_id );
		$operation = get_post_meta( $post_id, '_directorist_ai_elementor_draft_operation', true );
		if ( ! $post instanceof \WP_Post || 'draft' !== $post->post_status || $parent_id !== (int) $post->post_parent || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_post', $parent_id ) || ! is_string( $operation ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $operation ) ) {
			return false;
		}
		try {
			$operations = $this->db->table( 'operations' );
			$approvals  = $this->db->table( 'approvals' );
			$rows       = $this->db->rows( "SELECT o.receipt_json,a.review_json FROM $operations o INNER JOIN $approvals a ON a.id=o.approval_id AND a.actor_ref=o.actor_ref AND a.capability='builder.stage' WHERE o.public_id=%s AND o.actor_ref=%s AND o.capability='builder.stage' AND o.state='succeeded' LIMIT 2", array( $operation, 'user:' . (int) $post->post_author ) );
			if ( 1 !== count( $rows ) || ! is_string( $rows[0]['receipt_json'] ) || strlen( $rows[0]['receipt_json'] ) > 1048576 ) {
				return false; }
			$receipt = ( new JsonDecoder( 1048576, 32 ) )->decode( $rows[0]['receipt_json'] );
			$review  = $this->approval_review( $rows[0]['review_json'] ?? null );
			$targets = ( $receipt->targets ?? null ) instanceof \stdClass ? array_values( get_object_vars( $receipt->targets ) ) : array();
			$outcome = 1 === count( $targets ) ? $targets[0] : null;
			$source  = $review->impact->source_document ?? null;
			return 'succeeded' === ( $receipt->state ?? null )
				&& ( $receipt->operation_id ?? null ) === $operation
				&& 'succeeded' === ( $outcome->status ?? null )
				&& ( $outcome->effects->draft_post_id ?? null ) === $post_id
				&& ( $outcome->effects->draft_native_ref ?? null ) === 'post:' . $post_id
				&& 'elementor' === ( $source->builder ?? null )
				&& ( $source->native_kind ?? null ) === $kind
				&& ( $source->native_id ?? null ) === 'post:' . $parent_id
				&& ( 'template:single_listing' !== $kind || ( null !== $directory
				&& (string) ( $review->impact->scope->directory_id ?? '' ) === (string) $directory ) )
				&& 'private_template_draft' === ( $review->impact->intent ?? null )
				&& ( $review->plan->operation_id ?? null ) === $operation
				&& ( $review->plan->actor_ref ?? null ) === 'user:' . (int) $post->post_author
				&& is_string( $receipt->proposal_hash ?? null )
				&& ( $review->plan->proposal_hash ?? null ) === $receipt->proposal_hash;
		} catch ( \Throwable $error ) {
			return false; }
	}

	/** Return only the current owner's editable native draft link. */
	public function read( \WP_REST_Request $request ): array|\WP_Error {
		$user_id      = get_current_user_id();
		$operation_id = (string) $request['operation_id'];
		if ( $user_id < 1 || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $operation_id ) ) {
			return $this->unavailable();
		}
		try {
			$operations = $this->db->table( 'operations' );
			$approvals  = $this->db->table( 'approvals' );
			$rows       = $this->db->rows( "SELECT o.receipt_json,a.review_json FROM $operations o LEFT JOIN $approvals a ON a.id=o.approval_id AND a.actor_ref=o.actor_ref AND a.capability='builder.stage' WHERE o.public_id=%s AND o.actor_ref=%s AND o.capability='builder.stage' AND o.state='succeeded' LIMIT 2", array( $operation_id, 'user:' . $user_id ) );
			if ( 1 !== count( $rows ) || ! is_string( $rows[0]['receipt_json'] ) || strlen( $rows[0]['receipt_json'] ) > 1048576 ) {
				return $this->unavailable();
			}
			$receipt     = ( new JsonDecoder( 1048576, 32 ) )->decode( $rows[0]['receipt_json'] );
			$targets     = $receipt->targets ?? null;
			$target_rows = $targets instanceof \stdClass ? array_values( get_object_vars( $targets ) ) : array();
			$outcome     = 1 === count( $target_rows ) ? $target_rows[0] : null;
			$post_id     = $outcome instanceof \stdClass ? ( $outcome->effects->draft_post_id ?? null ) : null;
			if ( is_int( $post_id ) && 0 < $post_id ) {
				clean_post_cache( $post_id );
			}
			$post = is_int( $post_id ) && 0 < $post_id ? get_post( $post_id ) : null;
			if ( ! $receipt instanceof \stdClass || ( $receipt->operation_id ?? null ) !== $operation_id || ( $receipt->state ?? null ) !== 'succeeded' || ! $outcome instanceof \stdClass || ( $outcome->status ?? null ) !== 'succeeded' || ! $post instanceof \WP_Post || 'draft' !== $post->post_status || (int) $post->post_author !== $user_id || ! current_user_can( 'edit_post', $post->ID ) ) {
				return $this->unavailable();
			}
			$marker = $this->matched_marker( $post->ID, $operation_id );
			if ( null === $marker ) {
				return $this->unavailable();
			}
			$template_kind = '_directorist_ai_elementor_draft_operation' === $marker ? get_post_meta( $post->ID, '_directorist_ai_elementor_draft_kind', true ) : '';
			if ( 'elementor_library' === $post->post_type || str_starts_with( (string) $template_kind, 'template:' ) ) {
				$review        = $this->approval_review( $rows[0]['review_json'] ?? null );
				$source        = $review->impact->source_document ?? null;
				$parent        = (int) get_post_meta( $post->ID, '_directorist_ai_elementor_draft_source_page', true );
				$directory     = $review->impact->scope->directory_id ?? null;
				$types         = array(
					'template:taxonomy_category' => 'directorist-listing-category-archive',
					'template:taxonomy_location' => 'directorist-listing-location-archive',
					'template:taxonomy_tag'      => 'directorist-listing-tag-archive',
					'template:author_listing'    => 'directorist-listing-author-archive',
					'template:home_search'       => 'directorist-home-search-result',
				);
				$expected_type = 'template:single_listing' === $template_kind && null !== $directory ? 'directorist-single-listing-directory-' . $directory : ( $types[ $template_kind ] ?? null );
				$archive       = 'template:archive_listing' === $template_kind;
				if ( '_directorist_ai_elementor_draft_operation' !== $marker || ! $source instanceof \stdClass || ( $source->builder ?? null ) !== 'elementor' || ( $source->native_kind ?? null ) !== $template_kind || ( $source->native_id ?? null ) !== 'post:' . $parent || $parent < 1 || (int) $post->post_parent !== $parent || ( $review->impact->intent ?? null ) !== 'private_template_draft' || ( $review->plan->operation_id ?? null ) !== $operation_id || ( $review->plan->actor_ref ?? null ) !== 'user:' . $user_id || ( $receipt->proposal_hash ?? null ) !== ( $review->plan->proposal_hash ?? null ) || ( $outcome->effects->draft_native_ref ?? null ) !== 'post:' . $post->ID || ( $archive ? 'page' !== $post->post_type : ( 'elementor_library' !== $post->post_type || null === $expected_type || get_post_meta( $post->ID, '_elementor_template_type', true ) !== $expected_type ) ) || get_post_meta( $post->ID, '_elementor_conditions', true ) ) {
					return $this->unavailable();
				}
			}
			$reviewed_content_current = $this->reviewed_content_current( $post, $outcome, $marker );
			if ( null === $reviewed_content_current ) {
				return $this->unavailable();
			}
			$url = get_edit_post_link( $post->ID, 'raw' );
			if ( '_directorist_ai_elementor_draft_operation' === $marker ) {
				$url = $this->elementor_editor_url( $post );
			}
			if ( '_directorist_ai_divi_draft_operation' === $marker ) {
				$url = $this->divi_editor_url( $post );
			}
			if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
				$url = '_directorist_ai_draft_operation' === $marker ? $this->site_editor_url( $post, $outcome ) : null;
			}
			if ( ! is_string( $url ) || '' === $url ) {
				return $this->unavailable();
			}
			$preview_url = null;
			if ( 'page' === $post->post_type ) {
				$candidate = get_preview_post_link( $post );
				$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
				if ( is_string( $candidate ) && '' !== $candidate && is_string( $home_host ) && hash_equals( $home_host, (string) wp_parse_url( $candidate, PHP_URL_HOST ) ) && in_array( wp_parse_url( $candidate, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
					$preview_url = $candidate;
				}
			}
			$review              = '_directorist_ai_draft_operation' === $marker && 'page' === $post->post_type ? $this->approval_review( $rows[0]['review_json'] ?? null ) : null;
			$live_apply          = $this->gutenberg_live_apply( $post, $outcome, $marker, $operation_id, $reviewed_content_current, $review );
			$template_assignment = $this->template_assignment_ready( $post, $operation_id, $rows[0]['review_json'] ?? null, $marker, $reviewed_content_current, $outcome );
			$refinement_source   = $reviewed_content_current && 'page' === $post->post_type ? $this->refinement_source( $post, $receipt, $outcome ) : null;
			return array(
				'operation_id'             => $operation_id,
				'draft_post_id'            => $post->ID,
				'title'                    => $post->post_title,
				'edit_url'                 => $url,
				'preview_url'              => $preview_url,
				'status'                   => 'draft',
				'reviewed_content_current' => $reviewed_content_current,
				'builder'                  => array_search(
					$marker,
					array(
						'gutenberg' => '_directorist_ai_draft_operation',
						'elementor' => '_directorist_ai_elementor_draft_operation',
						'divi'      => '_directorist_ai_divi_draft_operation',
					),
					true
				),
				'can_publish_page'         => in_array( $template_kind, array( '', 'page' ), true ) && null !== $refinement_source && current_user_can( 'publish_pages' ) && $this->can_publish_page( $post->ID, $operation_id ),
				'can_assign_template'      => null !== $template_assignment,
				'assignment_source'        => $template_assignment,
				'refinement_source'        => $refinement_source,
				'live_apply'               => $live_apply,
			);
		} catch ( \Throwable $error ) {
			return $this->unavailable();
		}
	}

	/** Expose only a live native capability hint from the reviewed source and adapter. */
	private function template_assignment_ready( \WP_Post $post, string $operation, $review_json, string $marker, bool $reviewed_content_current, \stdClass $outcome ): ?array {
		if ( ! $reviewed_content_current || ! current_user_can( 'edit_theme_options' ) || ! in_array( $marker, array( '_directorist_ai_draft_operation', '_directorist_ai_elementor_draft_operation', '_directorist_ai_divi_draft_operation' ), true ) ) {
			return null;
		}
		$review    = $this->approval_review( $review_json );
		$source    = $review->impact->source_document ?? null;
		$scope     = $review->impact->scope ?? null;
		$directory = $scope->directory_id ?? null;
		$directory = is_string( $directory ) && ctype_digit( $directory ) ? (int) $directory : $directory;
		$gb_shared = $source instanceof \stdClass && 'gutenberg' === ( $source->builder ?? null ) && in_array( $source->native_kind ?? null, array( 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true );
		$gb_part   = $source instanceof \stdClass && 'gutenberg' === ( $source->builder ?? null ) && 'template_part' === ( $source->native_kind ?? null );
		$el_shared = $source instanceof \stdClass && 'elementor' === ( $source->builder ?? null ) && in_array( $source->native_kind ?? null, array( 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true );
		if ( $gb_part || $el_shared ) {
			if ( null !== ( $scope->directory_id ?? null ) || null !== ( $scope->view ?? null ) || null !== ( $scope->breakpoint_id ?? null ) || 'normal' !== ( $scope->interaction ?? null ) ) {
				return null;
			}
			$directory = 0;
		}
		if ( $gb_shared ) {
			if ( null !== ( $scope->directory_id ?? null ) || null !== ( $scope->view ?? null ) || null !== ( $scope->breakpoint_id ?? null ) || 'normal' !== ( $scope->interaction ?? null ) ) {
				return null;
			}
			$directory = $this->gutenberg_template_design_directory( $post->post_content );
		}
		if ( ! $source instanceof \stdClass || ! in_array( $source->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! is_string( $source->native_kind ?? null ) || ( ! str_starts_with( $source->native_kind, 'template:' ) && ! $gb_part ) || ! is_string( $source->native_id ?? null ) || ! is_int( $directory ) || ( $gb_part || $el_shared ? 0 !== $directory : $directory < 1 ) ) {
			return null;
		}
		try {
			$source_id = 0;
			if ( 'gutenberg' === $source->builder ) {
				if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $source->native_id, $parts ) || get_stylesheet() !== $parts[2] || $parts[1] !== $post->post_type || 'draft' !== $post->post_status || ( $outcome->effects->draft_native_ref ?? null ) !== 'template:' . $parts[1] . ':' . $parts[2] . ':' . $post->post_name || get_post_meta( $post->ID, '_directorist_ai_draft_source_ref', true ) !== $source->native_id ) {
					return null;
				}
				$shared = array(
					'archive-at_biz_dir'             => 'template:archive_listing',
					'taxonomy-at_biz_dir-category'   => 'template:taxonomy_category',
					'taxonomy-at_biz_dir-location'   => 'template:taxonomy_location',
					'taxonomy-at_biz_dir-tags'       => 'template:taxonomy_tag',
					'author-at_biz_dir'              => 'template:author_listing',
					'directorist-home-search-result' => 'template:home_search',
				);
				if ( ( $gb_part && ( 'wp_template_part' !== $parts[1] || ! in_array( $parts[3], array( 'header', 'footer' ), true ) ) ) || ( ! $gb_part && ( 'wp_template' !== $parts[1] || ( ! $gb_shared && ( 'template:single_listing' !== $source->native_kind || ! preg_match( '/^single-at_biz_dir-[A-Za-z0-9_-]+$/D', $parts[3] ) ) ) || ( $gb_shared && ( $shared[ $parts[3] ] ?? null ) !== $source->native_kind ) ) ) ) {
					return null;
				}
				$native    = get_block_template( $parts[2] . '//' . $parts[3], $parts[1] );
				$source_id = $native instanceof \WP_Block_Template ? (int) $native->wp_id : 0;
				$areas     = $gb_part ? wp_get_object_terms( $post->ID, 'wp_template_part_area', array( 'fields' => 'names' ) ) : null;
				if ( $gb_part && ( is_wp_error( $areas ) || array( $parts[3] ) !== $areas ) ) {
					return null;
				}
			} elseif ( preg_match( '/^post:([1-9][0-9]*)$/D', $source->native_id, $match ) && (int) $post->post_parent === (int) $match[1] ) {
				$source_id = (int) $match[1];
			}
			if ( 0 === $source_id && 'gutenberg' === $source->builder ) {
				$fallback = get_block_file_template( $parts[2] . '//' . $parts[3], $parts[1] );
				$terms    = wp_get_object_terms( $post->ID, 'wp_theme', array( 'fields' => 'names' ) );
				if ( ! $native instanceof \WP_Block_Template || ! $fallback instanceof \WP_Block_Template || ! in_array( $fallback->source, array( 'theme', 'plugin' ), true ) || 'publish' !== $fallback->status || $native->id !== $fallback->id || $native->content !== $fallback->content || 0 !== (int) $post->post_parent || is_wp_error( $terms ) || array( $parts[2] ) !== $terms || get_post_meta( $post->ID, '_directorist_ai_draft_source_hash', true ) !== hash( 'sha256', (string) $fallback->content ) ) {
					return null;
				}
			} elseif ( $source_id < 1 || ! current_user_can( 'edit_post', $source_id ) ) {
				return null;
			}
			$adapters = BuilderTemplateAssignmentRegistry::installed();
			if ( $adapters->qualify( $post->ID, $operation ) !== $source->builder ) {
				return null;
			}
			$inspection = $adapters->adapter( $source->builder )->inspect( $post->ID, $operation, $directory );
			if ( ( $inspection->draft_id ?? null ) !== $post->ID || ( $inspection->source_id ?? null ) !== $source_id || ( $inspection->directory_id ?? null ) !== $directory || ( $inspection->native_kind ?? null ) !== $source->native_kind ) {
				return null;
			}
			return array(
				'builder'          => $source->builder,
				'native_kind'      => $source->native_kind,
				'native_id'        => $source->native_id,
				'source_id'        => $source_id,
				'directory_id'     => $directory,
				'assignment_scope' => $gb_part ? 'global_parts' : ( $gb_shared || $el_shared ? 'global_routes' : 'directory' ),
			);
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** One reviewed shared template loop supplies the design directory for its global route. */
	private function gutenberg_template_design_directory( string $content ): ?int {
		$loops = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$loops ): void {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				if ( in_array( $block['blockName'] ?? null, array( 'directorist-gb-integration/listings-loop', 'directorist-gb-integration/homepage-search-loop' ), true ) ) {
					$loops[] = $block['attrs'] ?? array();
				}
				$walk( $block['innerBlocks'] ?? array() );
			}
		};
		$walk( parse_blocks( $content ) );
		if ( 1 !== count( $loops ) || ! is_array( $loops[0] ) ) {
			return null;
		}
		$ids = $loops[0]['directoryTypeIds'] ?? null;
		$id  = $loops[0]['designDirectoryTypeId'] ?? null;
		return is_array( $ids ) && 1 === count( $ids ) && is_int( $id ) && 0 < $id && array( $id ) === $ids ? $id : null;
	}

	/** Derive lineage from the owned approved artifact, never from browser or model text. */
	private function refinement_source( \WP_Post $post, object $receipt, object $outcome ): ?array {
		try {
			$version = BuilderPagePublication::native_version( $post->ID );
			$data    = $receipt->plan ?? null;
			if ( ! $data instanceof \stdClass || ! is_array( $data->targets ?? null ) || 1 !== count( $data->targets ) || ! is_int( $data->run_id ?? null ) || ! is_int( $data->approval_id ?? null ) || ( $data->actor_ref ?? null ) !== 'user:' . get_current_user_id() ) {
				return null;
			}
			$target    = $data->targets[0];
			$plan      = new \DirectoristAIPro\Effects\EffectPlan( $receipt->operation_id, $data->actor_ref, 'builder.stage', '1', $data->proposal_hash, array( new \DirectoristAIPro\Effects\EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), $data->run_id, $data->approval_id );
			$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
			if ( $canonical->encode( $data ) !== $canonical->encode( (object) $plan->data() ) || ( $receipt->plan_hash ?? null ) !== $plan->hash() || ( $receipt->proposal_hash ?? null ) !== $plan->proposal_hash || ( $receipt->target_set_hash ?? null ) !== $plan->target_set_hash() || ( $outcome->key ?? null ) !== $target->key || ( $outcome->effect_hash ?? null ) !== $target->effect_hash || ( $outcome->expected_version ?? null ) !== $target->expected_version || ( $outcome->before_version ?? null ) !== $target->expected_version || ( $outcome->after_version ?? null ) !== $target->effect_hash || ! is_int( $outcome->fence ?? null ) || $outcome->fence < 1 ) {
				return null;
			}
			$stage = ( new BuilderDraftStageArtifactReader( $this->db ) )->for_plan( $plan );
			if ( 'private_page_draft' !== $stage->payload->intent ) {
				return null;
			}
			if ( (int) $post->post_parent !== $stage->payload->source_page_id ) {
				return null;
			}
			$builder = BuilderPagePublicationRegistry::installed()->qualify( $post->ID, $receipt->operation_id );
			if ( $builder !== $stage->payload->source_document->builder ) {
				return null;
			}
			global $wpdb;
			$marker = array(
				'gutenberg' => '_directorist_ai_draft_operation',
				'elementor' => '_directorist_ai_elementor_draft_operation',
				'divi'      => '_directorist_ai_divi_draft_operation',
			)[ $builder ];
			$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s ORDER BY post_id LIMIT 2", $marker, $receipt->operation_id ) );
			if ( '' !== $wpdb->last_error || array( (string) $post->ID ) !== $ids ) {
				return null;
			}
			$conversation = ( new \DirectoristAIPro\Storage\ConversationStore( $this->db ) )->get( \DirectoristAIPro\Capabilities\Actor::user( get_current_user_id() )->owner(), $stage->payload->conversation_id );
			$binding      = ( new JsonDecoder( 65536, 12 ) )->decode( $conversation['context_json'] );
			$reference    = $stage->payload->proposal_ref;
			$proposal_row = ( new \DirectoristAIPro\Storage\ArtifactStore( $this->db ) )->get( \DirectoristAIPro\Capabilities\Actor::user( get_current_user_id() )->owner(), $reference->id, $reference->version );
			if ( 'proposal' !== $proposal_row['kind'] || (int) $proposal_row['conversation_id'] !== (int) $conversation['id'] || ! hash_equals( $reference->hash, $proposal_row['content_hash'] ) || ! hash_equals( $reference->hash, hash( 'sha256', $proposal_row['payload_json'] ) ) ) {
				return null;
			}
			$proposal = ( new JsonDecoder( 2097152, 32 ) )->decode( $proposal_row['payload_json'] );
			$scope    = $proposal->requested_scope ?? null;
			if ( ! $scope instanceof \stdClass || 'builder_change' !== ( $proposal->kind ?? null ) || ( $proposal->document_key ?? null ) !== $stage->payload->document_key || ( isset( $binding->builder_scope ) && $canonical->encode( $binding->builder_scope ) !== $canonical->encode( $scope ) ) ) {
				return null;
			}
			if ( ! hash_equals( $version, BuilderPagePublication::native_version( $post->ID ) ) ) {
				return null;
			}
			return array(
				'operation_id'    => $receipt->operation_id,
				'native_version'  => $version,
				'artifact'        => $stage->artifact,
				'conversation_id' => $stage->payload->conversation_id,
				'document'        => (object) array(
					'builder'     => $builder,
					'native_kind' => 'page',
					'native_id'   => 'post:' . $post->ID,
				),
				'scope'           => $scope,
			);
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** Bind a new workflow to one unchanged owned output; an earlier workflow never retargets. */
	public function bind_refinement( object $input ): object {
		if ( 'builder' !== ( $input->profile ?? null ) || ! isset( $input->builder_document, $input->builder_scope, $input->refinement_source ) || ! $input->refinement_source instanceof \stdClass || ! is_string( $input->refinement_source->operation_id ?? null ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $input->refinement_source->operation_id ) || ! is_string( $input->refinement_source->native_version ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input->refinement_source->native_version ) ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Draft refinement requires its explicit builder document and scope.' );
		}
		$request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/drafts/' . $input->refinement_source->operation_id );
		$request->set_param( 'operation_id', $input->refinement_source->operation_id );
		$result    = $this->read( $request );
		$source    = is_array( $result ) ? ( $result['refinement_source'] ?? null ) : null;
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		if ( ! is_array( $source ) || ( $source['native_version'] ?? null ) !== $input->refinement_source->native_version || $canonical->encode( $source['document'] ) !== $canonical->encode( $input->builder_document ) || $canonical->encode( $source['scope'] ) !== $canonical->encode( $input->builder_scope ) || ( $input->target ?? null ) !== $source['document']->native_id || ( $input->directory_id ?? null ) !== $source['scope']->directory_id ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'stale_version', 'The chosen draft or its owned refinement lineage changed. Refresh it before continuing.' );
		}
		return (object) $source;
	}

	/** Fresh parent lineage narrows every later catalog, inspect, proposal and effect check. */
	public function assert_refinement_binding( string $json ): void {
		$binding = ( new JsonDecoder( 65536, 12 ) )->decode( $json );
		if ( ! $binding instanceof \stdClass || ! property_exists( $binding, 'builder_parent' ) ) {
			return;
		}
		$parent = $binding->builder_parent;
		if ( ! $parent instanceof \stdClass || ! is_string( $parent->operation_id ?? null ) || ! is_string( $parent->native_version ?? null ) ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'evidence_stale', 'Stored draft refinement lineage is unavailable.' );
		}
		$input                    = clone $binding;
		$input->profile           = 'builder';
		$input->refinement_source = (object) array(
			'operation_id'   => $parent->operation_id,
			'native_version' => $parent->native_version,
		);
		$current                  = $this->bind_refinement( $input );
		if ( ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $current ) !== ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $parent ) ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'evidence_stale', 'Stored draft refinement artifact or lineage changed.' );
		}
	}

	private function can_publish_page( int $id, string $operation ): bool {
		try {
			BuilderPagePublicationRegistry::installed()->qualify( $id, $operation );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private function matched_marker( int $post_id, string $operation_id ): ?string {
		$present = array();
		foreach ( array( '_directorist_ai_draft_operation', '_directorist_ai_elementor_draft_operation', '_directorist_ai_divi_draft_operation' ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value ) {
				$present[ $key ] = $value;
			}
		}
		return 1 === count( $present ) && reset( $present ) === $operation_id ? array_key_first( $present ) : null;
	}

	/** Keep an owned, subsequently edited draft accessible without calling its new bytes reviewed. */
	private function reviewed_content_current( \WP_Post $post, \stdClass $outcome, string $marker ): ?bool {
		$markers = array(
			'_directorist_ai_draft_operation'           => array( '_directorist_ai_draft_source_hash', '_directorist_ai_draft_content_hash' ),
			'_directorist_ai_elementor_draft_operation' => array( '_directorist_ai_elementor_draft_source_hash', '_directorist_ai_elementor_draft_content_hash' ),
			'_directorist_ai_divi_draft_operation'      => array( '_directorist_ai_divi_draft_source_hash', '_directorist_ai_divi_draft_content_hash' ),
		);
		if ( ! isset( $markers[ $marker ] ) ) {
			return null;
		}
		$expected_source  = $outcome->before_version ?? null;
		$expected_content = $outcome->after_version ?? null;
		if ( ! is_string( $expected_source ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_source ) || ! is_string( $expected_content ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_content ) || ! hash_equals( $expected_source, (string) get_post_meta( $post->ID, $markers[ $marker ][0], true ) ) || ! hash_equals( $expected_content, (string) get_post_meta( $post->ID, $markers[ $marker ][1], true ) ) || ! hash_equals( $expected_content, (string) ( $outcome->effects->content_hash ?? '' ) ) ) {
			return null;
		}
		$content = '_directorist_ai_elementor_draft_operation' === $marker ? get_post_meta( $post->ID, '_elementor_data', true ) : $post->post_content;
		return is_string( $content ) && hash_equals( $expected_content, hash( 'sha256', $content ) );
	}

	private function approval_review( $json ): ?\stdClass {
		if ( ! is_string( $json ) || strlen( $json ) > 1048576 ) {
			return null;
		}
		try {
			$review = ( new JsonDecoder( 1048576, 32 ) )->decode( $json );
			return $review instanceof \stdClass ? $review : null;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** Only an unchanged reviewed Gutenberg page draft can enter an open source editor. */
	private function gutenberg_live_apply( \WP_Post $draft, \stdClass $outcome, string $marker, string $operation_id, bool $reviewed_content_current, ?\stdClass $review ): ?array {
		if ( ! $reviewed_content_current || '_directorist_ai_draft_operation' !== $marker || 'page' !== $draft->post_type || ! current_user_can( 'edit_pages' ) || ! $review instanceof \stdClass ) {
			return null;
		}
		try {
			BuilderScopedLiveApplyRegistry::installed()->adapter( 'gutenberg' );
		} catch ( \Throwable $error ) {
			return null;
		}
		$source_id = (int) get_post_meta( $draft->ID, '_directorist_ai_draft_source_page', true );
		$source    = $source_id > 0 ? get_post( $source_id ) : null;
		$impact    = $review->impact ?? null;
		if ( ! is_array( $impact->changes ?? null ) || 1 !== count( $impact->changes ) || 'set_control' !== ( $impact->changes[0]->operation ?? null ) || 'content' !== ( $impact->changes[0]->control ?? null ) ) {
			return null;
		}
		$document  = $impact instanceof \stdClass ? ( $impact->source_document ?? null ) : null;
		$directory = $impact->scope->directory_id ?? null;
		$directory = is_string( $directory ) && ctype_digit( $directory ) ? (int) $directory : $directory;
		if ( ! is_int( $directory ) || $directory < 1 || ! $source instanceof \WP_Post || 'page' !== $source->post_type || (int) $draft->post_parent !== $source_id || ! current_user_can( 'edit_post', $source_id ) || ! hash_equals( (string) $outcome->before_version, hash( 'sha256', $source->post_content ) ) || ( $review->plan->operation_id ?? null ) !== $operation_id || ( $review->plan->actor_ref ?? null ) !== 'user:' . get_current_user_id() || ( $review->plan->capability ?? null ) !== 'builder.stage' || ! $document instanceof \stdClass || ( $document->builder ?? null ) !== 'gutenberg' || ( $document->native_kind ?? null ) !== 'page' || ( $document->native_id ?? null ) !== 'post:' . $source_id || ( $impact->source_page_id ?? null ) !== $source_id || ( $impact->source_hash ?? null ) !== $outcome->before_version || ( $impact->candidate_hash ?? null ) !== $outcome->after_version ) {
			return null;
		}
		$editor_url = get_edit_post_link( $source_id, 'raw' );
		if ( ! is_string( $editor_url ) || '' === $editor_url ) {
			return null;
		}
		return array(
			'source_page_id' => $source_id,
			'directory_id'   => $directory,
			'source_hash'    => $outcome->before_version,
			'candidate_hash' => $outcome->after_version,
			'editor_url'     => add_query_arg( 'directorist_ai_stage', $operation_id, $editor_url ),
		);
	}

	/** The Site Editor identifies drafts by active theme, type and slug, not post.php. */
	private function site_editor_url( \WP_Post $post, \stdClass $outcome ): ?string {
		if ( ! current_user_can( 'edit_theme_options' ) || ! function_exists( 'get_block_template' ) || ! preg_match( '/^[A-Za-z0-9_.-]+$/D', $post->post_name ) ) {
			return null;
		}
		$theme = get_stylesheet();
		if ( ! preg_match( '/^[A-Za-z0-9_.\/-]+$/D', $theme ) || ( $outcome->effects->draft_native_ref ?? null ) !== 'template:' . $post->post_type . ':' . $theme . ':' . $post->post_name ) {
			return null;
		}
		$terms = wp_get_object_terms( $post->ID, 'wp_theme', array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || array( $theme ) !== $terms ) {
			return null;
		}
		$native = get_block_template( $theme . '//' . $post->post_name, $post->post_type );
		if ( ! $native instanceof \WP_Block_Template || (int) $native->wp_id !== $post->ID ) {
			return null;
		}
		return admin_url( 'site-editor.php?p=' . rawurlencode( '/' . $post->post_type . '/' . $theme . '//' . $post->post_name ) . '&canvas=edit' );
	}

	/** Elementor owns the editor route and editability of its native document. */
	private function elementor_editor_url( \WP_Post $post ): ?string {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		$plugin   = \Elementor\Plugin::instance();
		$document = isset( $plugin->documents ) ? $plugin->documents->get( $post->ID ) : null;
		if ( ! $document instanceof \Elementor\Core\Base\Document || ! $document->is_editable_by_current_user() ) {
			return null;
		}
		$url = $document->get_edit_url();
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/** Divi pages and hidden body layouts use distinct native Visual Builder routes. */
	private function divi_editor_url( \WP_Post $post ): ?string {
		if ( 'Divi' !== get_template() || ! in_array( $post->post_type, array( 'page', 'et_body_layout' ), true ) || 'on' !== get_post_meta( $post->ID, '_et_pb_use_builder', true ) || ! function_exists( 'et_fb_get_builder_url' ) || ! function_exists( 'et_pb_is_allowed' ) || ! et_pb_is_allowed( 'use_visual_builder' ) ) {
			return null;
		}
		$permalink = get_permalink( $post->ID );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return null;
		}
		$url = et_fb_get_builder_url( $permalink );
		if ( 'et_body_layout' === $post->post_type ) {
			// Direct navigation requires the outer shell, which owns the app iframe.
			$url = add_query_arg( 'et_tb', '1', remove_query_arg( 'app_window', $url ) );
		}
		return set_url_scheme( $url, is_ssl() ? 'https' : 'http' );
	}

	private function unavailable(): \WP_Error {
		return new \WP_Error( 'not_found', 'The owned native draft is unavailable.', array( 'status' => 404 ) );
	}
}
