<?php
/**
 * Separate human review for native template assignment and its exact inverse.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Closed native assignment boundary.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** An owned native stage can be assigned only through a new exact approval. */
final class BuilderTemplateAssignment implements EffectHandler {
	private Database $db;
	private ArtifactStore $artifacts;
	private ApprovalService $approvals;
	private CatalogEnvironmentReader $environment;
	private BuilderTemplateAssignmentRegistry $adapters;

	public function __construct( Database $db, CatalogEnvironmentReader $environment, ?BuilderTemplateAssignmentRegistry $adapters = null ) {
		$this->db          = $db;
		$this->artifacts   = new ArtifactStore( $db );
		$this->approvals   = new ApprovalService( $db );
		$this->environment = $environment;
		$this->adapters    = $adapters ?? BuilderTemplateAssignmentRegistry::installed();
	}

	/** The caller supplies a stage operation and directory, never a live template ID. */
	public function propose( Context $context, object $input ): object {
		$this->assert_context( $context );
		$this->keys( $input, array( 'directory_id', 'draft_operation_id', 'request_id' ) );
		if ( ! is_int( $input->directory_id ) || $input->directory_id < 0 || ! is_string( $input->draft_operation_id ) || ! is_string( $input->request_id ) ) {
			throw new ContractException( 'invalid_input', 'Assignment needs an owned stage, directory and request ID.' );
		}
		GraphVersion::identifier( $input->draft_operation_id );
		GraphVersion::identifier( $input->request_id );
		$stage   = $this->stage( $input->draft_operation_id, $input->directory_id );
		$adapter = $this->adapters->adapter( $stage->builder );
		$this->authorize( $stage->draft_id, $stage->source_id );
		$this->assert_binding( $context, $stage );
		$inspect = $this->inspection( $adapter->inspect( $stage->draft_id, $stage->operation_id, $stage->directory_id ), $stage );
		$run_id  = $this->active_run( $context );
		return $this->prepare( $context, 'builder.template_assign', 'builder_template_assignment', 'builder-assign-', $input->request_id, $run_id, $stage, $inspect, null );
	}

	/** A successful current assignment receipt is the only source for an inverse. */
	public function propose_restore( Context $context, object $input ): object {
		$this->assert_context( $context );
		$this->keys( $input, array( 'assignment_operation_id', 'request_id' ) );
		if ( ! is_string( $input->assignment_operation_id ) || ! is_string( $input->request_id ) ) {
			throw new ContractException( 'invalid_input', 'Assignment restoration needs an owned result and request ID.' );
		}
		GraphVersion::identifier( $input->assignment_operation_id );
		GraphVersion::identifier( $input->request_id );
		$origin  = $this->origin( $input->assignment_operation_id );
		$payload = $origin->payload;
		$stage   = $this->stage( $payload->draft_operation_id, $payload->directory_id, true );
		if ( $stage->builder !== $payload->builder || $stage->draft_id !== $payload->draft_id || $stage->source_id !== $payload->source_id ) {
			throw new ContractException( 'evidence_stale', 'The original native stage changed.' );
		}
		$this->authorize( $stage->draft_id, $stage->source_id );
		$this->assert_binding( $context, $stage );
		$run_id = $this->active_run( $context );
		$copy   = clone $payload->inspection;
		return $this->prepare( $context, 'builder.template_restore', 'builder_template_restoration', 'builder-template-restore-', $input->request_id, $run_id, $stage, $copy, $origin );
	}

	private function prepare( Context $context, string $capability, string $kind, string $prefix, string $request_id, int $run_id, object $stage, object $inspection, ?object $origin ): object {
		$operation   = $prefix . substr( hash( 'sha256', $context->run_id() . "\0" . $request_id ), 0, 32 );
		$environment = $this->environment->read()->fingerprint();
		$restore     = null !== $origin;
		$global      = ( 'gutenberg' === $stage->builder && 'template:archive_listing' === $stage->native_kind ) || ( in_array( $stage->builder, array( 'gutenberg', 'elementor' ), true ) && in_array( $stage->native_kind, array( 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) );
		$before_hash = $restore ? $origin->after_hash : $inspection->before_hash;
		$effect_hash = $restore ? $inspection->before_hash : $inspection->impact_hash;
		$payload     = (object) array(
			'kind'                    => $kind,
			'builder'                 => $stage->builder,
			'draft_id'                => $stage->draft_id,
			'source_id'               => $stage->source_id,
			'directory_id'            => $stage->directory_id,
			'draft_operation_id'      => $stage->operation_id,
			'assignment_operation_id' => $restore ? $origin->operation_id : null,
			'expected_after_hash'     => $restore ? $origin->after_hash : null,
			'inspection'              => $inspection,
			'run_id'                  => $run_id,
		);
		( new JsonDecoder( 5242880, 64 ) )->check( $payload );
		$artifact = $this->artifacts->put_once( $context->actor()->owner(), $context->conversation_id(), $operation, 'proposal', $payload, $environment );
		$target   = new EffectTarget( 'builder-template:' . $stage->draft_id, $before_hash, $effect_hash );
		$plan     = new EffectPlan( $operation, $context->actor()->owner()->reference(), $capability, '1', $artifact['content_hash'], array( $target ), $run_id );
		$impact   = (object) array(
			'kind'                    => $restore ? 'builder_template_restore' : 'builder_template_assign',
			'assignment_scope'        => 'template_part' === $stage->native_kind ? 'global_parts' : ( $global ? 'global_routes' : 'directory' ),
			'builder'                 => $stage->builder,
			'native_kind'             => $inspection->native_kind,
			'draft_id'                => $stage->draft_id,
			'source_id'               => $stage->source_id,
			'directory_id'            => $stage->directory_id,
			'draft_operation_id'      => $stage->operation_id,
			'assignment_operation_id' => $restore ? $origin->operation_id : null,
			'consumer_ids'            => array_slice( $inspection->consumer_ids, 0, 100 ),
			'consumer_count'          => $inspection->consumer_count,
			'consumer_ids_truncated'  => $inspection->consumer_count > 100,
			'consumer_set_hash'       => hash( 'sha256', ( new JsonCanonicalizer() )->encode( $inspection->consumer_ids ) ),
			'before_hash'             => $before_hash,
			'impact_hash'             => $inspection->impact_hash,
			'inventory_hash'          => $inspection->inventory_hash,
			'dependency_hash'         => $inspection->dependency_hash,
			'after_status'            => $restore ? 'restored' : 'assigned',
			'external_effects'        => 'not_reversible',
		);
		$review   = $this->approvals->create_staged(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$operation,
				(int) $artifact['version'],
				$artifact['content_hash'],
				$environment,
				$impact,
				(object) array(
					'max_templates'        => 1,
					'max_listed_consumers' => 100,
				)
			)
		);
		try {
			$this->authorize( $stage->draft_id, $stage->source_id );
			$this->assert_binding( $context, $stage );
			if ( ! hash_equals( $environment, $this->environment->read()->fingerprint() ) ) {
				throw new ContractException( 'stale_version', 'Native environment changed during assignment review.' );
			}
			if ( $restore ) {
				$this->origin( $origin->operation_id );
			} else {
				$fresh = $this->inspection( $this->adapters->adapter( $stage->builder )->inspect( $stage->draft_id, $stage->operation_id, $stage->directory_id ), $stage );
				$this->same_inspection( $inspection, $fresh );
			}
		} catch ( \Throwable $error ) {
			$this->approvals->revoke_unreserved( $context->actor(), $review['id'], $review['review_hash'] );
			throw $error;
		}
		$review = $this->approvals->activate_staged( $context->actor(), $review['id'], $review['review_hash'] );
		return (object) array(
			'operation_id' => $operation,
			'artifact'     => (object) array(
				'id'      => $operation,
				'version' => (int) $artifact['version'],
				'hash'    => $artifact['content_hash'],
			),
			'review'       => (object) array(
				'id'          => $review['id'],
				'state'       => $review['state'],
				'review_hash' => $review['review_hash'],
			),
			'draft_id'     => $stage->draft_id,
		);
	}

	public function permitted( EffectPlan $plan, Context $context ): bool {
		if ( $context->actor()->is_guest() || get_current_user_id() !== $context->actor()->user_id() || $context->actor()->owner()->reference() !== $plan->actor_ref ) {
			return false;
		}
		try {
			$payload = $this->load( $plan )->payload;
			$this->authorize( $payload->draft_id, $payload->source_id );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public function environment_hash( EffectPlan $plan ): string {
		$this->load( $plan );
		return $this->environment->read()->fingerprint();
	}

	/** The native adapter owns its write lock, transaction and operation marker. */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$loaded  = $this->load( $plan );
		$payload = $loaded->payload;
		$this->assert_target( $plan, $target, $payload );
		$adapter = $this->adapters->adapter( $payload->builder );
		try {
			$this->authorize( $payload->draft_id, $payload->source_id );
			$stage = $this->stage( $payload->draft_operation_id, $payload->directory_id, 'builder.template_restore' === $plan->capability );
			$this->same_stage( $payload, $stage );
			if ( ! hash_equals( $loaded->environment, $this->environment->read()->fingerprint() ) ) {
				throw new ContractException( 'stale_version', 'Native environment changed before assignment.' );
			}
			if ( 'builder.template_restore' === $plan->capability ) {
				$this->origin( $payload->assignment_operation_id );
			} else {
				$fresh = $this->inspection( $adapter->inspect( $payload->draft_id, $payload->draft_operation_id, $payload->directory_id ), $stage );
				$this->same_inspection( $payload->inspection, $fresh );
			}
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		}
		$check = function () use ( $plan, $target, $payload, $loaded, $adapter ): void {
			$this->authorize( $payload->draft_id, $payload->source_id );
			$current_stage = $this->stage( $payload->draft_operation_id, $payload->directory_id, 'builder.template_restore' === $plan->capability );
			$this->same_stage( $payload, $current_stage );
			if ( ! hash_equals( $loaded->environment, $this->environment->read()->fingerprint() ) ) {
				throw new ContractException( 'stale_version', 'Native environment changed at assignment write.' );
			}
			if ( 'builder.template_restore' === $plan->capability ) {
				$this->origin( $payload->assignment_operation_id );
			} else {
				$this->same_inspection( $payload->inspection, $this->inspection( $adapter->inspect( $payload->draft_id, $payload->draft_operation_id, $payload->directory_id ), $current_stage ) );
			}
		};
		try {
			if ( 'builder.template_restore' === $plan->capability ) {
				$result = $adapter->restore( $payload->draft_id, $payload->draft_operation_id, $payload->directory_id, $payload->assignment_operation_id, $plan->operation_id, $payload->expected_after_hash, $payload->inspection->impact_hash, $payload->inspection->before_native, $check );
			} else {
				$result = $adapter->assign( $payload->draft_id, $payload->draft_operation_id, $payload->directory_id, $plan->operation_id, $payload->inspection->before_hash, $payload->inspection->impact_hash, $check );
			}
			return $this->outcome( $result, $plan, $target, $payload, $fence, false );
		} catch ( \Throwable $error ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array( 'draft_id' => $payload->draft_id ), $this->unverified_external(), 'native_assignment_interrupted' );
		}
	}

	/** Reconciliation only observes the operation marker; it never repeats a write. */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$payload = $this->load( $plan )->payload;
		$this->assert_target( $plan, $target, $payload );
		$this->authorize( $payload->draft_id, $payload->source_id );
		$adapter = $this->adapters->adapter( $payload->builder );
		try {
			if ( 'builder.template_restore' === $plan->capability ) {
				$result = $adapter->reconcile_restore( $payload->draft_id, $payload->draft_operation_id, $payload->directory_id, $payload->assignment_operation_id, $plan->operation_id, $payload->expected_after_hash, $payload->inspection->impact_hash, $payload->inspection->before_native );
			} else {
				$result = $adapter->reconcile( $payload->draft_id, $payload->draft_operation_id, $payload->directory_id, $plan->operation_id, $payload->inspection->before_hash, $payload->inspection->impact_hash );
			}
			return $this->outcome( $result, $plan, $target, $payload, $fence, true );
		} catch ( \Throwable $error ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array( 'draft_id' => $payload->draft_id ), $this->unverified_external(), 'native_assignment_reconciliation_unavailable' );
		}
	}

	/** Owned stage receipt, exact stage artifact, and current native draft are required. */
	private function stage( string $operation, int $directory_id, bool $historical = false ): object {
		$result = null;
		if ( ! $historical ) {
			$request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/drafts/' . $operation );
			$request->set_param( 'operation_id', $operation );
			$result = ( new BuilderDraftResultApi( $this->db ) )->read( $request );
			if ( ! is_array( $result ) || true !== ( $result['reviewed_content_current'] ?? null ) || 'draft' !== ( $result['status'] ?? null ) || ! is_int( $result['draft_post_id'] ?? null ) ) {
				throw new ContractException( 'evidence_stale', 'Assignment requires an unchanged owned native template draft.' );
			}
		}
		$rows = $this->db->rows( 'SELECT receipt_json FROM ' . $this->db->table( 'operations' ) . " WHERE public_id=%s AND actor_ref=%s AND capability='builder.stage' AND capability_revision='1' AND state='succeeded' LIMIT 2", array( $operation, 'user:' . get_current_user_id() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'evidence_stale', 'Native stage receipt is unavailable.' );
		}
		$receipt  = ( new JsonDecoder( 1048576, 32 ) )->decode( $rows[0]['receipt_json'] );
		$outcomes = $receipt->targets ?? null;
		$parts    = $outcomes instanceof \stdClass ? array_values( get_object_vars( $outcomes ) ) : array();
		$outcome  = 1 === count( $parts ) ? $parts[0] : null;
		$draft_id = $historical ? ( $outcome->effects->draft_post_id ?? null ) : $result['draft_post_id'];
		if ( ! $outcome instanceof \stdClass || ( $outcome->status ?? null ) !== 'succeeded' || ! is_int( $draft_id ) || $draft_id < 1 || ( $outcome->effects->draft_post_id ?? null ) !== $draft_id || ( $receipt->state ?? null ) !== 'succeeded' ) {
			throw new ContractException( 'evidence_stale', 'Native stage receipt has no exact owned draft.' );
		}
		$plan = $receipt->plan ?? null;
		if ( ! $plan instanceof \stdClass || ! is_array( $plan->targets ?? null ) || 1 !== count( $plan->targets ) ) {
			throw new ContractException( 'evidence_stale', 'Native stage plan is unavailable.' );
		}
		$target = $plan->targets[0];
		$exact  = new EffectPlan( $operation, 'user:' . get_current_user_id(), 'builder.stage', '1', $plan->proposal_hash, array( new EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), $plan->run_id, $plan->approval_id );
		if ( ( $receipt->plan_hash ?? null ) !== $exact->hash() || ( $receipt->proposal_hash ?? null ) !== $exact->proposal_hash || ( $receipt->target_set_hash ?? null ) !== $exact->target_set_hash() || ( $outcome->key ?? null ) !== $target->key || ( $outcome->expected_version ?? null ) !== $target->expected_version || ( $outcome->effect_hash ?? null ) !== $target->effect_hash || ( $outcome->before_version ?? null ) !== $target->expected_version || ( $outcome->after_version ?? null ) !== $target->effect_hash || ( $outcome->effects->content_hash ?? null ) !== $target->effect_hash ) {
			throw new ContractException( 'evidence_stale', 'Native stage receipt plan changed.' );
		}
		$stage         = ( new BuilderDraftStageArtifactReader( $this->db ) )->for_plan( $exact );
		$source        = $stage->payload->source_document ?? null;
		$scope         = $this->source_scope( $stage );
		$ref           = $stage->payload->source_template_ref ?? null;
		$gb_shared     = $source instanceof \stdClass && 'gutenberg' === ( $source->builder ?? null ) && in_array( $source->native_kind ?? null, array( 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true );
		$gb_part       = $source instanceof \stdClass && 'gutenberg' === ( $source->builder ?? null ) && 'template_part' === ( $source->native_kind ?? null ) && 0 === $directory_id;
		$el_shared     = $source instanceof \stdClass && 'elementor' === ( $source->builder ?? null ) && in_array( $source->native_kind ?? null, array( 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true );
		$scope_matches = $gb_part || $gb_shared || $el_shared ? null === ( $scope->directory_id ?? null ) : hash_equals( (string) $directory_id, (string) ( $scope->directory_id ?? '' ) );
		if ( 'private_template_draft' !== ( $stage->payload->intent ?? null ) || ! $source instanceof \stdClass || ! in_array( $source->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! is_string( $ref ) || ! $scope instanceof \stdClass || ! $scope_matches || ( ! $historical && ( $result['builder'] ?? null ) !== $source->builder ) || $source->builder !== $this->adapters->qualify( $draft_id, $operation ) || (int) get_post_field( 'post_author', $draft_id ) !== get_current_user_id() ) {
			throw new ContractException( 'evidence_stale', 'Native template stage does not match the selected directory and builder.' );
		}
		if ( 'gutenberg' !== $source->builder && ( ( $el_shared ? 0 !== $directory_id : $directory_id < 1 ) || ( $el_shared && ( null !== ( $scope->view ?? null ) || null !== ( $scope->breakpoint_id ?? null ) || 'normal' !== ( $scope->interaction ?? null ) ) ) ) ) {
			throw new ContractException( 'scope_conflict', 'Post-backed template assignment requires its exact reviewed native scope.' );
		}
		if ( 'gutenberg' === $source->builder ) {
			if ( ! $gb_part && 0 === $directory_id || ( ! $gb_shared && ! $gb_part && ( 'template:single_listing' !== ( $source->native_kind ?? null ) || 'single' !== ( $scope->view ?? null ) ) ) || ( ( $gb_shared || $gb_part ) && null !== ( $scope->view ?? null ) ) || null !== ( $scope->breakpoint_id ?? null ) || 'normal' !== ( $scope->interaction ?? null ) ) {
				throw new ContractException( 'scope_conflict', 'Block template assignment requires its exact reviewed native scope.' );
			}
			$source_id = $this->gutenberg_source_id( $ref, $draft_id, $outcome, $source->native_kind );
		} elseif ( preg_match( '/^post:([1-9][0-9]*)$/D', $ref, $matches ) && ( $outcome->effects->draft_native_ref ?? null ) === 'post:' . $draft_id ) {
			$source_id = (int) $matches[1];
		} else {
			throw new ContractException( 'evidence_stale', 'Post-backed template stage references are invalid.' );
		}
		return (object) array(
			'operation_id' => $operation,
			'draft_id'     => $draft_id,
			'source_id'    => $source_id,
			'directory_id' => $directory_id,
			'builder'      => $source->builder,
			'native_kind'  => $source->native_kind,
			'document'     => $source,
		);
	}

	/** Resolve only one current-theme, published DB-backed native listing source. */
	private function gutenberg_source_id( string $ref, int $draft_id, object $outcome, string $kind ): int {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! function_exists( 'get_block_template' ) || ! preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $ref, $parts ) || get_stylesheet() !== $parts[2] ) {
			throw new ContractException( 'unsupported_dependency', 'The current block-theme listing template source is unavailable.' );
		}
		$type   = $parts[1];
		$theme  = $parts[2];
		$slug   = $parts[3];
		$shared = array(
			'archive-at_biz_dir'             => 'template:archive_listing',
			'taxonomy-at_biz_dir-category'   => 'template:taxonomy_category',
			'taxonomy-at_biz_dir-location'   => 'template:taxonomy_location',
			'taxonomy-at_biz_dir-tags'       => 'template:taxonomy_tag',
			'author-at_biz_dir'              => 'template:author_listing',
			'directorist-home-search-result' => 'template:home_search',
		);
		if ( ( 'template_part' === $kind && ( 'wp_template_part' !== $type || ! in_array( $slug, array( 'header', 'footer' ), true ) ) ) || ( 'template_part' !== $kind && ( 'wp_template' !== $type || ( 'template:single_listing' === $kind && ! preg_match( '/^single-at_biz_dir-[A-Za-z0-9_-]+$/D', $slug ) ) || ( 'template:single_listing' !== $kind && ( $shared[ $slug ] ?? null ) !== $kind ) ) ) ) {
			throw new ContractException( 'scope_conflict', 'Reviewed block template kind and source slug differ.' );
		}
		$draft       = get_post( $draft_id );
		$staged_ref  = $outcome->effects->draft_native_ref ?? null;
		$staged_slug = is_string( $staged_ref ) && str_starts_with( $staged_ref, 'template:' . $type . ':' . $theme . ':' ) ? substr( $staged_ref, strlen( 'template:' . $type . ':' . $theme . ':' ) ) : '';
		if ( ! $draft instanceof \WP_Post || $type !== $draft->post_type || ! in_array( $draft->post_status, array( 'draft', 'publish' ), true ) || ! preg_match( '/^directorist-ai-draft-[a-f0-9]{24}$/D', $staged_slug ) || ( 'draft' === $draft->post_status && $draft->post_name !== $staged_slug ) || ( 'publish' === $draft->post_status && $draft->post_name !== $slug ) || 0 !== (int) $draft->post_parent || get_post_meta( $draft_id, '_directorist_ai_draft_source_ref', true ) !== $ref ) {
			throw new ContractException( 'evidence_stale', 'Reviewed block template draft identity changed.' );
		}
		$native        = get_block_template( $theme . '//' . $slug, $type );
		$source_id     = $native instanceof \WP_Block_Template ? (int) $native->wp_id : 0;
		$source        = $source_id > 0 ? get_post( $source_id ) : null;
		$draft_theme   = wp_get_object_terms( $draft_id, 'wp_theme', array( 'fields' => 'names' ) );
		$fallback      = $this->gutenberg_fallback( $theme, $slug, $type );
		$fallback_hash = $fallback instanceof \WP_Block_Template ? hash( 'sha256', (string) $fallback->content ) : '';
		$staged_hash   = get_post_meta( $draft_id, '_directorist_ai_draft_source_hash', true );
		if ( $fallback instanceof \WP_Block_Template && in_array( $fallback->source, array( 'theme', 'plugin' ), true ) && 'publish' === $fallback->status && $fallback->slug === $slug && 0 === (int) $fallback->wp_id && $staged_hash === $fallback_hash ) {
			$original       = 'draft' === $draft->post_status && 0 === $source_id && $native instanceof \WP_Block_Template && $native->id === $fallback->id && $native->source === $fallback->source && $native->content === $fallback->content;
			$owned_override = 'publish' === $draft->post_status && $source_id === $draft_id && $native instanceof \WP_Block_Template && $native->content === $draft->post_content;
			$draft_area     = 'wp_template_part' === $type ? wp_get_object_terms( $draft_id, 'wp_template_part_area', array( 'fields' => 'names' ) ) : null;
			if ( is_wp_error( $draft_theme ) || array( $theme ) !== $draft_theme || ( 'wp_template_part' === $type && ( is_wp_error( $draft_area ) || array( $slug ) !== $draft_area ) ) || ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_post', $draft_id ) || ( ! $original && ! $owned_override ) ) {
				throw new ContractException( 'evidence_stale', 'Native fallback or owned override changed after the reviewed stage.' );
			}
			return 0;
		}
		if ( $fallback instanceof \WP_Block_Template && $staged_hash === $fallback_hash ) {
			throw new ContractException( 'stale_version', 'A different database override displaced the reviewed native fallback.' );
		}
		$source_theme = wp_get_object_terms( $source_id, 'wp_theme', array( 'fields' => 'names' ) );
		$draft_area   = 'wp_template_part' === $type ? wp_get_object_terms( $draft_id, 'wp_template_part_area', array( 'fields' => 'names' ) ) : null;
		$source_area  = 'wp_template_part' === $type ? wp_get_object_terms( $source_id, 'wp_template_part_area', array( 'fields' => 'names' ) ) : null;
		if ( 'draft' !== $draft->post_status || ! $source instanceof \WP_Post || $type !== $source->post_type || 'publish' !== $source->post_status || $source->post_name !== $slug || is_wp_error( $draft_theme ) || is_wp_error( $source_theme ) || array( $theme ) !== $draft_theme || array( $theme ) !== $source_theme || ( 'wp_template_part' === $type && ( is_wp_error( $draft_area ) || is_wp_error( $source_area ) || array( $slug ) !== $draft_area || array( $slug ) !== $source_area ) ) ) {
			throw new ContractException( 'evidence_stale', 'Published block template source or theme binding changed.' );
		}
		return $source_id;
	}

	/** Older Divi stages lacked a scope copy; recover only the exact reviewed proposal. */
	private function source_scope( object $stage ): object {
		$payload = $stage->payload;
		$ref     = $payload->proposal_ref ?? null;
		if ( ! $ref instanceof \stdClass || ! is_string( $ref->id ?? null ) || ! is_int( $ref->version ?? null ) || $ref->version < 1 || ! is_string( $ref->hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $ref->hash ) ) {
			throw new ContractException( 'evidence_stale', 'Native stage proposal reference is unavailable.' );
		}
		$owner        = Actor::user( get_current_user_id() )->owner();
		$conversation = ( new ConversationStore( $this->db ) )->get( $owner, $payload->conversation_id );
		$row          = $this->artifacts->get( $owner, $ref->id, $ref->version );
		if ( 'proposal' !== $row['kind'] || (int) $row['conversation_id'] !== (int) $conversation['id'] || ! hash_equals( $ref->hash, $row['content_hash'] ) || ! hash_equals( $ref->hash, hash( 'sha256', $row['payload_json'] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Native stage proposal scope is unavailable.' );
		}
		$proposal = ( new JsonDecoder( 2097152, 32 ) )->decode( $row['payload_json'] );
		$scope    = $proposal->requested_scope ?? null;
		$recorded = property_exists( $payload, 'source_scope' ) ? $payload->source_scope : null;
		$keys     = $scope instanceof \stdClass ? array_keys( get_object_vars( $scope ) ) : array();
		sort( $keys, SORT_STRING );
		if ( ! $proposal instanceof \stdClass || 'builder_change' !== ( $proposal->kind ?? null ) || ( $proposal->document_key ?? null ) !== $payload->document_key || array( 'breakpoint_id', 'directory_id', 'interaction', 'view' ) !== $keys || ( property_exists( $payload, 'source_scope' ) && ( ! $recorded instanceof \stdClass || ( new JsonCanonicalizer() )->encode( $recorded ) !== ( new JsonCanonicalizer() )->encode( $scope ) ) ) ) {
			throw new ContractException( 'evidence_stale', 'Native stage and reviewed proposal scopes differ.' );
		}
		return $scope;
	}

	private function inspection( object $inspection, object $stage ): object {
		$required = array( 'before_hash', 'before_native', 'builder', 'consumer_count', 'consumer_ids', 'dependency_hash', 'directory_id', 'draft_id', 'impact_hash', 'inventory_hash', 'native_kind', 'source_id' );
		$keys     = array_keys( get_object_vars( $inspection ) );
		sort( $keys, SORT_STRING );
		if ( $keys !== $required || $inspection->builder !== $stage->builder || $inspection->draft_id !== $stage->draft_id || $inspection->source_id !== $stage->source_id || $inspection->directory_id !== $stage->directory_id || $inspection->native_kind !== $stage->native_kind || ! $inspection->before_native instanceof \stdClass || ! is_array( $inspection->consumer_ids ) || count( $inspection->consumer_ids ) > 500 || ! is_int( $inspection->consumer_count ) || count( $inspection->consumer_ids ) !== $inspection->consumer_count ) {
			throw new ContractException( 'unsupported_contract', 'Native template inspection is invalid.' );
		}
		foreach ( array( 'before_hash', 'impact_hash', 'dependency_hash', 'inventory_hash' ) as $field ) {
			if ( ! is_string( $inspection->{$field} ) || ! preg_match( '/^[a-f0-9]{64}$/D', $inspection->{$field} ) ) {
				throw new ContractException( 'unsupported_contract', 'Native template inspection hash is invalid.' );
			}
		}
		foreach ( $inspection->consumer_ids as $ref ) {
			if ( ! is_string( $ref ) || ! preg_match( '/^(?:post:[1-9][0-9]*|wp_block:[1-9][0-9]*|pattern:[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+|template:wp_template(?:_part)?:[A-Za-z0-9_.\/-]+:[A-Za-z0-9_.-]+(?::draft:[1-9][0-9]*)?|term:[a-z][a-z0-9_-]{0,63}:[1-9][0-9]*|author:[1-9][0-9]*|route:home-search)$/D', $ref ) ) {
				throw new ContractException( 'unsupported_contract', 'Native template consumer reference is invalid.' );
			}
		}
		if ( count( $inspection->consumer_ids ) !== count( array_unique( $inspection->consumer_ids ) ) || strlen( ( new JsonCanonicalizer() )->encode( $inspection ) ) > 4194304 ) {
			throw new ContractException( 'needs_expansion', 'Native template checkpoint exceeds its bound.' );
		}
		return $inspection;
	}

	private function same_inspection( object $before, object $after ): void {
		if ( ( new JsonCanonicalizer() )->encode( $before ) !== ( new JsonCanonicalizer() )->encode( $after ) ) {
			throw new ContractException( 'stale_version', 'Native template, consumers or active assignment changed.' );
		}
	}

	private function same_stage( object $payload, object $stage ): void {
		if ( $payload->draft_id !== $stage->draft_id || $payload->source_id !== $stage->source_id || $payload->builder !== $stage->builder || $payload->directory_id !== $stage->directory_id || $payload->draft_operation_id !== $stage->operation_id ) {
			throw new ContractException( 'evidence_stale', 'Reviewed native stage differs from its current receipt.' );
		}
	}

	private function origin( string $operation ): object {
		$request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/assignments/' . $operation );
		$request->set_param( 'operation_id', $operation );
		$result = ( new BuilderDraftResultApi( $this->db ) )->assignment( $request );
		if ( ! is_array( $result ) || true !== ( $result['reviewed_state_current'] ?? null ) || true !== ( $result['assigned_at_operation'] ?? null ) ) {
			throw new ContractException( 'stale_version', 'Assignment restore needs its current owned native result.' );
		}
		$owner   = Actor::user( get_current_user_id() )->owner();
		$row     = $this->artifacts->get( $owner, $operation, 1 );
		$payload = ( new JsonDecoder( 5242880, 64 ) )->decode( $row['payload_json'] );
		if ( 'proposal' !== $row['kind'] || ! hash_equals( $row['content_hash'], hash( 'sha256', $row['payload_json'] ) ) || ! $payload instanceof \stdClass || 'builder_template_assignment' !== ( $payload->kind ?? null ) || ( $payload->draft_id ?? null ) !== $result['draft_id'] ) {
			throw new ContractException( 'evidence_stale', 'Assignment inverse checkpoint is unavailable.' );
		}
		return (object) array(
			'operation_id' => $operation,
			'payload'      => $payload,
			'after_hash'   => $result['after_hash'],
		);
	}

	private function load( EffectPlan $plan ): object {
		if ( ! in_array( $plan->capability, array( 'builder.template_assign', 'builder.template_restore' ), true ) || '1' !== $plan->capability_revision || 1 !== count( $plan->targets ) || null === $plan->run_id || ! preg_match( '/^user:([1-9][0-9]*)$/D', $plan->actor_ref, $match ) || get_current_user_id() !== (int) $match[1] ) {
			throw new ContractException( 'approval_mismatch', 'Template assignment plan is invalid.' );
		}
		$row     = $this->artifacts->get( Actor::user( (int) $match[1] )->owner(), $plan->operation_id, 1 );
		$payload = ( new JsonDecoder( 5242880, 64 ) )->decode( $row['payload_json'] );
		$kind    = 'builder.template_restore' === $plan->capability ? 'builder_template_restoration' : 'builder_template_assignment';
		if ( 'proposal' !== $row['kind'] || ! hash_equals( $plan->proposal_hash, $row['content_hash'] ) || ! hash_equals( $row['content_hash'], hash( 'sha256', $row['payload_json'] ) ) || ! $payload instanceof \stdClass || ( $payload->kind ?? null ) !== $kind || ! is_int( $payload->draft_id ?? null ) || ! is_int( $payload->source_id ?? null ) || ! is_int( $payload->directory_id ?? null ) || ( $payload->run_id ?? null ) !== $plan->run_id || ! $payload->inspection instanceof \stdClass ) {
			throw new ContractException( 'evidence_stale', 'Reviewed assignment artifact is unavailable.' );
		}
		$stage = $this->stage( $payload->draft_operation_id, $payload->directory_id, true );
		$this->same_stage( $payload, $stage );
		$this->inspection( $payload->inspection, $stage );
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$bindings      = $this->db->rows( "SELECT c.context_json FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.id=%d AND c.id=%d AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.erasure_requested=0 AND r.cancel_requested=0", array( $plan->run_id, (int) $row['conversation_id'], $plan->actor_ref ) );
		if ( 1 !== count( $bindings ) ) {
			throw new ContractException( 'unauthorized', 'Assignment originating run is unavailable.' );
		}
		$this->assert_binding_json( $bindings[0]['context_json'], $stage );
		return (object) array(
			'payload'     => $payload,
			'environment' => $row['environment_hash'],
		);
	}

	private function assert_target( EffectPlan $plan, EffectTarget $target, object $payload ): void {
		$stored = $plan->targets[0];
		$before = 'builder.template_restore' === $plan->capability ? $payload->expected_after_hash : $payload->inspection->before_hash;
		$effect = 'builder.template_restore' === $plan->capability ? $payload->inspection->before_hash : $payload->inspection->impact_hash;
		if ( 'builder-template:' . $payload->draft_id !== $target->key || $stored->key !== $target->key || $stored->expected_version !== $target->expected_version || $stored->effect_hash !== $target->effect_hash || $before !== $target->expected_version || $effect !== $target->effect_hash ) {
			throw new ContractException( 'approval_mismatch', 'Template assignment target differs from its exact review.' );
		}
	}

	private function outcome( object $result, EffectPlan $plan, EffectTarget $target, object $payload, int $fence, bool $reconciled ): EffectOutcome {
		$after = $result->after_hash ?? null;
		if ( ! in_array( $result->state ?? null, array( 'succeeded', 'failed', 'unknown' ), true ) || ( $result->draft_id ?? null ) !== $payload->draft_id || ( $result->source_id ?? null ) !== $payload->source_id || ( $result->operation_id ?? null ) !== $plan->operation_id || ( $result->before_hash ?? null ) !== $target->expected_version || ( $result->impact_hash ?? null ) !== $payload->inspection->impact_hash || ( null === $after ? 'succeeded' === ( $result->state ?? null ) : ( ! is_string( $after ) || ! preg_match( '/^[a-f0-9]{64}$/D', $after ) ) ) || ( null !== ( $result->error_code ?? null ) && ( ! is_string( $result->error_code ) || ! preg_match( '/^[a-z_]{1,64}$/D', $result->error_code ) ) ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array( 'draft_id' => $payload->draft_id ), $this->unverified_external(), 'native_assignment_receipt_invalid' );
		}
		$effects = (object) array(
			'draft_id'                => $payload->draft_id,
			'source_id'               => $payload->source_id,
			'directory_id'            => $payload->directory_id,
			'builder'                 => $payload->builder,
			'draft_operation_id'      => $payload->draft_operation_id,
			'assignment_operation_id' => $payload->assignment_operation_id,
			'impact_hash'             => $payload->inspection->impact_hash,
			'dependency_hash'         => $payload->inspection->dependency_hash,
			'inventory_hash'          => $payload->inspection->inventory_hash,
			'consumer_count'          => $payload->inspection->consumer_count,
			'reconciled'              => $reconciled,
			'fence'                   => $fence,
		);
		return new EffectOutcome( $result->state, $target->expected_version, $after, $effects, 'unknown' === $result->state ? $this->unverified_external() : array(), $result->error_code );
	}

	private function unverified_external(): array {
		return array(
			array(
				'kind'  => 'native_template_hooks',
				'state' => 'unverified_not_reversible',
			),
		);
	}

	private function assert_context( Context $context ): void {
		$context->assert_execution_identity();
		if ( ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || ! in_array( $context->transport(), array( 'ui', 'worker' ), true ) || $context->actor()->is_guest() || get_current_user_id() !== $context->actor()->user_id() || null === $context->conversation_id() || null === $context->run_id() ) {
			throw new ContractException( 'unauthorized', 'Template assignment needs an authenticated current workflow.' );
		}
	}

	private function keys( object $input, array $expected ): void {
		$keys = array_keys( get_object_vars( $input ) );
		sort( $keys, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new ContractException( 'invalid_input', 'Template assignment input contains unsupported fields.' );
		}
	}

	private function active_run( Context $context ): int {
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id AND c.active_run_id=r.id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.erasure_requested=0 AND r.state='running' AND r.cancel_requested=0", array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'unauthorized', 'Template assignment needs its current owning run.' );
		}
		return (int) $rows[0]['id'];
	}

	private function authorize( int $draft_id, int $source_id ): void {
		if ( get_current_user_id() < 1 || ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_post', $draft_id ) || ( 0 < $source_id && ! current_user_can( 'edit_post', $source_id ) ) || ( 0 === $source_id && ! $this->gutenberg_fallback_authority( $draft_id ) ) || (int) get_post_field( 'post_author', $draft_id ) !== get_current_user_id() ) {
			throw new ContractException( 'unauthorized', 'Native template assignment requires its draft author and theme authority.' );
		}
	}

	/** Zero is a real file/plugin source only for one owned current-theme draft. */
	private function gutenberg_fallback_authority( int $draft_id ): bool {
		$draft = get_post( $draft_id );
		$ref   = get_post_meta( $draft_id, '_directorist_ai_draft_source_ref', true );
		$theme = get_stylesheet();
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! $draft instanceof \WP_Post || 0 !== (int) $draft->post_parent || ! is_string( $ref ) || ! preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $ref, $parts ) || $parts[1] !== $draft->post_type || $parts[2] !== $theme || ( 'wp_template_part' === $parts[1] && ! in_array( $parts[3], array( 'header', 'footer' ), true ) ) || ! in_array( $draft->post_status, array( 'draft', 'publish' ), true ) ) {
			return false;
		}
		$fallback = $this->gutenberg_fallback( $theme, $parts[3], $parts[1] );
		$native   = get_block_template( $theme . '//' . $parts[3], $parts[1] );
		$terms    = wp_get_object_terms( $draft_id, 'wp_theme', array( 'fields' => 'names' ) );
		$areas    = 'wp_template_part' === $parts[1] ? wp_get_object_terms( $draft_id, 'wp_template_part_area', array( 'fields' => 'names' ) ) : null;
		if ( ! $fallback instanceof \WP_Block_Template || ! $native instanceof \WP_Block_Template || ! in_array( $fallback->source, array( 'theme', 'plugin' ), true ) || 'publish' !== $fallback->status || $fallback->slug !== $parts[3] || 0 !== (int) $fallback->wp_id || get_post_meta( $draft_id, '_directorist_ai_draft_source_hash', true ) !== hash( 'sha256', (string) $fallback->content ) || is_wp_error( $terms ) || array( $theme ) !== $terms || ( 'wp_template_part' === $parts[1] && ( is_wp_error( $areas ) || array( $parts[3] ) !== $areas ) ) ) {
			return false;
		}
		if ( 'draft' === $draft->post_status ) {
			return preg_match( '/^directorist-ai-draft-[a-f0-9]{24}$/D', $draft->post_name ) && 0 === (int) $native->wp_id && $native->id === $fallback->id && $native->content === $fallback->content;
		}
		return $draft->post_name === $parts[3] && (int) $native->wp_id === $draft_id && $native->content === $draft->post_content;
	}

	/** The public file-template filter may return a DB override; read its native fallback directly. */
	private function gutenberg_fallback( string $theme, string $slug, string $type = 'wp_template' ): ?\WP_Block_Template {
		if ( ! function_exists( '_get_block_template_file' ) || ! function_exists( '_build_block_template_result_from_file' ) || get_stylesheet() !== $theme ) {
			return null;
		}
		$file = _get_block_template_file( $type, $slug );
		if ( is_array( $file ) ) {
			return _build_block_template_result_from_file( $file, $type );
		}
		$registered = 'wp_template' === $type ? \WP_Block_Templates_Registry::get_instance()->get_by_slug( $slug ) : null;
		if ( ! $registered instanceof \WP_Block_Template ) {
			return null;
		}
		$fallback          = clone $registered;
		$fallback->content = apply_block_hooks_to_content( $fallback->content, $fallback, 'insert_hooked_blocks_and_set_ignored_hooked_blocks_metadata' );
		return $fallback;
	}

	private function assert_binding( Context $context, object $stage ): void {
		$json = BuilderConversationTarget::active_context( $this->db, $context );
		if ( null === $json ) {
			throw new ContractException( 'unauthorized', 'Template assignment requires a selected native document.' );
		}
		$this->assert_binding_json( $json, $stage );
	}

	private function assert_binding_json( string $json, object $stage ): void {
		$binding = ( new JsonDecoder( 65536, 12 ) )->decode( $json );
		if ( ! $binding instanceof \stdClass || ( $binding->target ?? null ) !== $stage->document->native_id ) {
			throw new ContractException( 'unauthorized', 'Template assignment requires its selected saved source.' );
		}
		$catalog_directory = ( 'gutenberg' === $stage->builder && in_array( $stage->native_kind, array( 'template_part', 'template:archive_listing' ), true ) ) || ( in_array( $stage->builder, array( 'gutenberg', 'elementor' ), true ) && in_array( $stage->native_kind, array( 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) ) ? null : $stage->directory_id;
		BuilderConversationTarget::assert_catalog_binding( $json, $stage->builder, $catalog_directory );
		BuilderConversationTarget::assert_binding( $json, $binding->document_key ?? $stage->builder . '-template:' . $stage->source_id, $stage->document );
	}
}
