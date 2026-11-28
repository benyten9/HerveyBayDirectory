<?php
/**
 * Read-only native preview context for an owned, successfully staged template.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.PHP.YodaConditions.NotYoda -- Closed internal read boundary.

use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Native metadata locates private authority; it never grants preview authority. */
final class BuilderDraftEditorContextReader {

	private Database $db;
	private \wpdb $native;
	private JsonCanonicalizer $canonical;
	private int $site_id;

	public function __construct( Database $db, \wpdb $native ) {
		$this->db        = $db;
		$this->native    = $native;
		$this->canonical = new JsonCanonicalizer();
		$this->site_id   = get_current_blog_id();
	}

	/** Return only public editor identity/scope facts, or no context. */
	public function read( int $post_id ): ?array {
		try {
			$before = $this->authority( $post_id );
			$after  = $this->authority( $post_id );
			return hash_equals( $this->canonical->encode( $before ), $this->canonical->encode( $after ) ) ? $before['context'] : null;
		} catch ( \Throwable $error ) {
			// Optional preview failure must not expose private lineage or affect editing.
			return null;
		}
	}

	private function authority( int $post_id ): array {
		$user = get_current_user_id();
		if ( $this->site_id !== get_current_blog_id() || $post_id < 1 || $user < 1 || ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_post', $post_id ) || ! $this->db->readiness()->status()['effects_enabled'] ) {
			throw new \RuntimeException( 'Preview authority unavailable.' );
		}
		$post = $this->native_post( $post_id );
		if ( 'wp_template' !== $post['post_type'] || 'draft' !== $post['post_status'] || $user !== (int) $post['post_author'] || strlen( $post['post_content'] ) > 1048576 ) {
			throw new \RuntimeException( 'Preview draft unavailable.' );
		}
		$markers      = $this->markers( $post_id );
		$operation_id = $markers['_directorist_ai_draft_operation'];
		$slug         = 'directorist-ai-draft-' . substr( hash( 'sha256', get_current_blog_id() . '|' . $operation_id ), 0, 24 );
		if ( ! hash_equals( $slug, $post['post_name'] ) ) {
			throw new \RuntimeException( 'Preview draft identity changed.' );
		}
		$marked = $this->native->get_col( $this->native->prepare( "SELECT DISTINCT post_id FROM {$this->native->postmeta} WHERE meta_key='_directorist_ai_draft_operation' AND meta_value=%s LIMIT 2", $operation_id ) );
		if ( array( (string) $post_id ) !== $marked ) {
			throw new \RuntimeException( 'Preview native operation ambiguous.' );
		}
		$owner      = 'user:' . $user;
		$operations = $this->db->table( 'operations' );
		$rows       = $this->db->rows( "SELECT * FROM $operations WHERE public_id=%s AND actor_ref=%s AND capability='builder.stage' AND capability_revision='1' AND state='succeeded' LIMIT 2", array( $operation_id, $owner ) );
		if ( 1 !== count( $rows ) ) {
			throw new \RuntimeException( 'Preview operation unavailable.' );
		}
		$operation = $rows[0];
		$receipt   = ( new JsonDecoder( 1048576, 32 ) )->decode( $operation['receipt_json'] );
		$data      = $receipt->plan ?? null;
		if ( ! $data instanceof \stdClass || ! is_array( $data->targets ?? null ) || 1 !== count( $data->targets ) || ! is_int( $data->run_id ?? null ) || ! is_int( $data->approval_id ?? null ) ) {
			throw new \RuntimeException( 'Preview plan unavailable.' );
		}
		$target_data = $data->targets[0];
		if ( ! $target_data instanceof \stdClass || ! is_string( $target_data->key ?? null ) || ! is_string( $target_data->expected_version ?? null ) || ! is_string( $target_data->effect_hash ?? null ) ) {
			throw new \RuntimeException( 'Preview target unavailable.' );
		}
		$target = new EffectTarget( $target_data->key, $target_data->expected_version, $target_data->effect_hash );
		$plan   = new EffectPlan( $operation_id, $owner, 'builder.stage', '1', $operation['proposal_hash'], array( $target ), (int) $operation['run_id'], (int) $operation['approval_id'] );
		if ( $this->canonical->encode( $data ) !== $this->canonical->encode( (object) $plan->data() ) || ( $receipt->operation_id ?? null ) !== $operation_id || ( $receipt->state ?? null ) !== 'succeeded' || ! hash_equals( $plan->hash(), (string) ( $receipt->plan_hash ?? '' ) ) || ! hash_equals( $plan->hash(), $operation['reconciliation_key'] ) || ! hash_equals( $plan->proposal_hash, (string) ( $receipt->proposal_hash ?? '' ) ) || ! hash_equals( $plan->target_set_hash(), $operation['target_set_hash'] ) || ! hash_equals( $plan->target_set_hash(), (string) ( $receipt->target_set_hash ?? '' ) ) ) {
			throw new \RuntimeException( 'Preview receipt changed.' );
		}
		$targets    = $receipt->targets ?? null;
		$outcome    = $targets instanceof \stdClass ? ( $targets->{$target->key} ?? null ) : null;
		$effects    = $outcome->effects ?? null;
		$theme      = get_stylesheet();
		$native_ref = 'template:wp_template:' . $theme . ':' . $slug;
		if ( ! $targets instanceof \stdClass || 1 !== count( get_object_vars( $targets ) ) || ! $outcome instanceof \stdClass || ( $outcome->key ?? null ) !== $target->key || ( $outcome->expected_version ?? null ) !== $target->expected_version || ( $outcome->effect_hash ?? null ) !== $target->effect_hash || ( $outcome->status ?? null ) !== 'succeeded' || ( $outcome->before_version ?? null ) !== $target->expected_version || ( $outcome->after_version ?? null ) !== $target->effect_hash || ! is_int( $outcome->fence ?? null ) || $outcome->fence < 1 || ! $effects instanceof \stdClass || ( $effects->draft_post_id ?? null ) !== $post_id || ( $effects->draft_native_ref ?? null ) !== $native_ref || ( $effects->status ?? null ) !== 'draft' || ( $effects->content_hash ?? null ) !== $target->effect_hash || ( $effects->fence ?? null ) !== $outcome->fence ) {
			throw new \RuntimeException( 'Preview effect changed.' );
		}
		$artifacts = $this->db->table( 'artifacts' );
		$approvals = $this->db->table( 'approvals' );
		$rows      = $this->db->rows( "SELECT a.payload_json,a.content_hash,a.environment_hash,a.version,p.review_json,p.review_identity,p.reserved_operation_public_id,p.state AS approval_state,p.target_set_hash,p.base_versions_hash FROM $artifacts a INNER JOIN $approvals p ON p.artifact_id=a.public_id AND p.artifact_version=a.version AND p.actor_ref=a.owner_ref AND p.capability='builder.stage' AND p.proposal_hash=a.content_hash AND p.environment_hash=a.environment_hash WHERE a.public_id=%s AND a.owner_ref=%s AND a.kind='builder_draft' AND p.id=%d LIMIT 2", array( $operation_id, $owner, $plan->approval_id ) );
		$base_hash = hash(
			'sha256',
			json_encode(
				array(
					array(
						'key'     => $target->key,
						'version' => $target->expected_version,
					),
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
			)
		);
		if ( 1 !== count( $rows ) || strlen( $rows[0]['payload_json'] ) > 5242880 || strlen( $rows[0]['review_json'] ) > 1048576 ) {
			throw new \RuntimeException( 'Preview artifact exceeds its bound.' );
		}
		if ( 1 !== count( $rows ) || ! hash_equals( $plan->proposal_hash, $rows[0]['content_hash'] ) || ! hash_equals( $rows[0]['content_hash'], hash( 'sha256', $rows[0]['payload_json'] ) ) || ! hash_equals( $rows[0]['review_identity'], hash( 'sha256', $rows[0]['review_json'] ) ) || $rows[0]['reserved_operation_public_id'] !== $operation_id || ! in_array( $rows[0]['approval_state'], array( 'reserved', 'consumed' ), true ) || ! hash_equals( $plan->target_set_hash(), $rows[0]['target_set_hash'] ) || ! hash_equals( $base_hash, $rows[0]['base_versions_hash'] ) ) {
			throw new \RuntimeException( 'Preview artifact changed.' );
		}
		$artifact        = $rows[0];
		$stage           = ( new BuilderDraftStageArtifactReader( $this->db ) )->for_plan( $plan )->payload;
		$review          = ( new JsonDecoder( 1048576, 32 ) )->decode( $artifact['review_json'] );
		$review_plan     = $review->plan ?? null;
		$unreserved_plan = new EffectPlan( $operation_id, $owner, 'builder.stage', '1', $plan->proposal_hash, array( $target ), $plan->run_id );
		if ( $this->canonical->encode( $review_plan ) !== $this->canonical->encode( (object) $unreserved_plan->data() ) || ( $review->artifact->id ?? null ) !== $operation_id || ( $review->artifact->version ?? null ) !== (int) $artifact['version'] || ( $review->artifact->hash ?? null ) !== $plan->proposal_hash || ( $review->environment_hash ?? null ) !== $artifact['environment_hash'] || 'private_template_draft' !== $stage->intent || 'template:single_listing' !== $stage->source_document->native_kind || $markers['_directorist_ai_draft_source_ref'] !== $stage->source_template_ref || $markers['_directorist_ai_draft_source_hash'] !== $stage->source_hash || $markers['_directorist_ai_draft_content_hash'] !== $stage->native_content_hash || $target->expected_version !== $stage->source_hash || $target->effect_hash !== $stage->native_content_hash ) {
			throw new \RuntimeException( 'Preview review changed.' );
		}
		if ( ! preg_match( '/^template:wp_template:([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $stage->source_template_ref, $match ) || $theme !== $match[1] ) {
			throw new \RuntimeException( 'Preview source has no scoped native identity.' );
		}
		$family = apply_filters( 'directorist_ai_pro_gutenberg_native_template_context', null, $stage->source_template_ref );
		if ( ! is_array( $family ) || 'template:single_listing' !== ( $family['native_kind'] ?? null ) || ! is_int( $family['directory_id'] ?? null ) || $family['directory_id'] < 1 ) {
			throw new \RuntimeException( 'Preview source family unavailable.' );
		}
		$source         = get_block_template( $theme . '//' . $match[2], 'wp_template' );
		$draft          = get_block_template( $theme . '//' . $slug, 'wp_template' );
		$theme_terms    = $this->native->get_col( $this->native->prepare( "SELECT t.name FROM {$this->native->terms} t INNER JOIN {$this->native->term_taxonomy} tt ON tt.term_id=t.term_id INNER JOIN {$this->native->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tr.object_id=%d AND tt.taxonomy='wp_theme' ORDER BY t.name", $post_id ) );
		$taxonomy       = defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types';
		$directory      = get_term( $family['directory_id'], $taxonomy );
		$directory_rows = $this->native->get_results( $this->native->prepare( "SELECT t.term_id,t.slug FROM {$this->native->terms} t INNER JOIN {$this->native->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s AND t.term_id=%d LIMIT 2", $taxonomy, $family['directory_id'] ), ARRAY_A );
		if ( 1 !== count( $directory_rows ) || ! $directory instanceof \WP_Term || (int) $directory_rows[0]['term_id'] !== (int) $directory->term_id || $directory_rows[0]['slug'] !== $directory->slug ) {
			throw new \RuntimeException( 'Preview directory changed.' );
		}
		if ( $source instanceof \WP_Block_Template && ! empty( $source->wp_id ) && ! hash_equals( $stage->source_hash, hash( 'sha256', $this->native_post( (int) $source->wp_id )['post_content'] ) ) ) {
			throw new \RuntimeException( 'Preview saved source changed.' );
		}
		if ( ! $source instanceof \WP_Block_Template || $source->id !== $theme . '//' . $match[2] || ! hash_equals( $stage->source_hash, hash( 'sha256', (string) $source->content ) ) || ! $draft instanceof \WP_Block_Template || (int) $draft->wp_id !== $post_id || ! hash_equals( (string) $draft->content, $post['post_content'] ) || array( $theme ) !== $theme_terms || ! $directory instanceof \WP_Term || ! GutenbergEditorSnapshotBinding::matches_saved_document( $stage->source_template_ref, 'template:single_listing', (int) $directory->term_id, $directory->slug ) ) {
			throw new \RuntimeException( 'Preview native context changed.' );
		}
		// The receipt proves family/ownership; native edits retain read-only preview.
		// Assignment and mutation still compare the reviewed content separately.
		$this->assert_preview_bounds( $post['post_content'] );
		$context = array(
			'document_id'       => $theme . '//' . $slug,
			'post_id'           => $post_id,
			'document_revision' => hash( 'sha256', $post['post_content'] ),
			'kind'              => 'template:single_listing',
			'directory_id'      => (int) $directory->term_id,
		);
		return array(
			'context'     => $context,
			'markers'     => $markers,
			'operation'   => $operation,
			'artifact'    => $artifact,
			'source_hash' => hash( 'sha256', (string) $source->content ),
			'directory'   => array( $directory->term_id, $directory->slug ),
			'post'        => $post,
		);
	}

	private function native_post( int $id ): array {
		$rows = $this->native->get_results( $this->native->prepare( "SELECT ID,post_type,post_status,post_author,post_name,post_content,post_modified_gmt FROM {$this->native->posts} WHERE ID=%d LIMIT 2", $id ), ARRAY_A );
		if ( 1 !== count( $rows ) ) {
			throw new \RuntimeException( 'Preview native post unavailable.' );
		}
		return $rows[0];
	}

	private function markers( int $id ): array {
		$keys   = array( '_directorist_ai_draft_operation', '_directorist_ai_draft_source_ref', '_directorist_ai_draft_source_hash', '_directorist_ai_draft_content_hash' );
		$rows   = $this->native->get_results( $this->native->prepare( "SELECT meta_key,meta_value FROM {$this->native->postmeta} WHERE post_id=%d AND meta_key IN (%s,%s,%s,%s) ORDER BY meta_key", $id, ...$keys ), ARRAY_A );
		$result = array();
		foreach ( $rows as $row ) {
			if ( isset( $result[ $row['meta_key'] ] ) || ! is_string( $row['meta_value'] ) || strlen( $row['meta_value'] ) > 256 ) {
				throw new \RuntimeException( 'Preview markers ambiguous.' );
			}
			$result[ $row['meta_key'] ] = $row['meta_value'];
		}
		if ( 4 !== count( $result ) ) {
			throw new \RuntimeException( 'Preview markers absent.' );
		}
		return $result;
	}

	/** Bound current editable markup without treating formatting as write authority. */
	private function assert_preview_bounds( string $content ): void {
		$queue = array( array( parse_blocks( $content ), 0 ) );
		$count = 0;
		while ( $queue ) {
			list( $blocks, $depth ) = array_pop( $queue );
			if ( $depth > 24 ) {
				throw new \RuntimeException( 'Preview nesting exceeds its bound.' );
			}
			foreach ( $blocks as $block ) {
				if ( ++$count > 1900 ) {
					throw new \RuntimeException( 'Preview node count exceeds its bound.' );
				}
				if ( $block['innerBlocks'] ) {
					$queue[] = array( $block['innerBlocks'], $depth + 1 );
				}
			}
		}
	}
}
