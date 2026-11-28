<?php
/**
 * Separate exact approval for one native editor scoped live Save.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed approval and native ticket boundary.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Approval issues only a ticket; native Save plus reconciliation is the effect. */
final class BuilderScopedLiveApply implements EffectHandler {
	private Database $db;
	private ArtifactStore $artifacts;
	private ApprovalService $approvals;
	private CatalogEnvironmentReader $environment;
	private BuilderScopedLiveApplyRegistry $adapters;
	private BuilderScopedLiveStage $stages;
	private JsonCanonicalizer $canonical;

	public function __construct( Database $db, CatalogEnvironmentReader $environment, ?BuilderScopedLiveApplyRegistry $adapters = null ) {
		$this->db          = $db;
		$this->artifacts   = new ArtifactStore( $db );
		$this->approvals   = new ApprovalService( $db );
		$this->environment = $environment;
		$this->adapters    = $adapters ?? BuilderScopedLiveApplyRegistry::installed();
		$this->stages      = new BuilderScopedLiveStage( $db );
		$this->canonical   = new JsonCanonicalizer();
	}

	/** Review one stage and one current editor binding, never a model-supplied patch. */
	public function propose( Context $context, object $input ): object {
		$this->assert_context( $context );
		$keys = array_keys( get_object_vars( $input ) );
		sort( $keys, SORT_STRING );
		if ( array( 'directory_id', 'draft_operation_id', 'editor_binding_id', 'request_id' ) !== $keys || ! is_int( $input->directory_id ) || $input->directory_id < 1 || ! is_string( $input->draft_operation_id ) || ! is_string( $input->editor_binding_id ) || ! is_string( $input->request_id ) ) {
			throw new ContractException( 'invalid_input', 'Scoped live review requires one stage, directory and current editor binding.' );
		}
		GraphVersion::identifier( $input->draft_operation_id );
		GraphVersion::identifier( $input->editor_binding_id );
		GraphVersion::identifier( $input->request_id );
		$stage = $this->stages->read( $input->draft_operation_id, true );
		$this->assert_stage_scope( $stage, $input->directory_id );
		$this->assert_binding( $context, $stage, $input->directory_id );
		$adapter     = $this->adapters->adapter( $stage->builder );
		$inspection  = $this->inspection( $adapter->inspect( $stage->source_id, $stage->draft_id, $input->directory_id, $input->editor_binding_id, $stage ), $stage, $input->directory_id );
		$run_id      = $this->active_run( $context );
		$operation   = 'builder-live-' . substr( hash( 'sha256', $context->run_id() . "\0" . $input->request_id ), 0, 32 );
		$environment = $this->environment->read()->fingerprint();
		$payload     = (object) array(
			'kind'               => 'builder_scoped_live_ticket',
			'builder'            => $stage->builder,
			'source_id'          => $stage->source_id,
			'draft_id'           => $stage->draft_id,
			'directory_id'       => $input->directory_id,
			'draft_operation_id' => $stage->operation_id,
			'inspection'         => $inspection,
			'run_id'             => $run_id,
		);
		( new JsonDecoder( 2097152, 48 ) )->check( $payload );
		$artifact = $this->artifacts->put_once( $context->actor()->owner(), $context->conversation_id(), $operation, 'proposal', $payload, $environment );
		$patch    = $inspection->patch;
		$target   = new EffectTarget( 'builder-live:post:' . $stage->source_id, $patch->before_tree_hash, $patch->after_tree_hash );
		$plan     = new EffectPlan( $operation, $context->actor()->owner()->reference(), 'builder.live_apply', '1', $artifact['content_hash'], array( $target ), $run_id );
		$impact   = (object) array(
			'kind'               => 'builder_scoped_live_apply',
			'builder'            => $stage->builder,
			'source_page_id'     => $stage->source_id,
			'draft_post_id'      => $stage->draft_id,
			'draft_operation_id' => $stage->operation_id,
			'directory_id'       => $input->directory_id,
			'scope'              => $patch->scope,
			'target_ref'         => $patch->review->target_ref,
			'control_path'       => $patch->review->control_path,
			'before'             => $patch->review->before,
			'after'              => $patch->review->after,
			'before_hash'        => $patch->before_tree_hash,
			'candidate_hash'     => $patch->after_tree_hash,
			'protected_hash'     => $patch->protected_tree_hash,
			'editor_epoch'       => $inspection->editor_epoch,
			'save_confirmation'  => 'native_editor_required',
		);
		$review   = $this->approvals->create_staged( $context->actor(), new ApprovalSpec( $plan, $operation, (int) $artifact['version'], $artifact['content_hash'], $environment, $impact, (object) array( 'max_pages' => 1, 'max_controls' => 1 ) ) );
		try {
			$this->assert_binding( $context, $this->stages->read( $stage->operation_id, true ), $input->directory_id );
			$fresh = $this->inspection( $adapter->inspect( $stage->source_id, $stage->draft_id, $input->directory_id, $input->editor_binding_id, $stage ), $stage, $input->directory_id );
			$this->same_inspection( $inspection, $fresh );
			if ( ! hash_equals( $environment, $this->environment->read()->fingerprint() ) ) {
				throw new ContractException( 'stale_version', 'Native environment changed during live review.' );
			}
		} catch ( \Throwable $error ) {
			$this->approvals->revoke_unreserved( $context->actor(), $review['id'], $review['review_hash'] );
			throw $error;
		}
		$review = $this->approvals->activate_staged( $context->actor(), $review['id'], $review['review_hash'] );
		return (object) array(
			'operation_id'   => $operation,
			'artifact'       => (object) array( 'id' => $operation, 'version' => (int) $artifact['version'], 'hash' => $artifact['content_hash'] ),
			'review'         => (object) array( 'id' => $review['id'], 'state' => $review['state'], 'review_hash' => $review['review_hash'] ),
			'source_page_id' => $stage->source_id,
		);
	}

	public function permitted( EffectPlan $plan, Context $context ): bool {
		try {
			$payload = $this->load( $plan )->payload;
			return get_current_user_id() === $context->actor()->user_id() && ! $context->actor()->is_guest() && $context->actor()->owner()->reference() === $plan->actor_ref && current_user_can( 'edit_post', $payload->source_id ) && current_user_can( 'edit_post', $payload->draft_id );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public function environment_hash( EffectPlan $plan ): string {
		$this->load( $plan );
		return $this->environment->read()->fingerprint();
	}

	/** Owner-only native command material, after exact approved ticket issuance. */
	public function ticket( EffectPlan $plan ): object {
		$payload = $this->load( $plan )->payload;
		$this->assert_target( $plan, $plan->targets[0], $payload );
		if ( ! current_user_can( 'edit_post', $payload->source_id ) ) {
			throw new ContractException( 'unauthorized', 'Scoped live editor ticket is unavailable.' );
		}
		return (object) array(
			'builder'            => $payload->builder,
			'source_page_id'     => $payload->source_id,
			'source_status'      => $payload->inspection->source_status,
			'draft_post_id'      => $payload->draft_id,
			'draft_operation_id' => $payload->draft_operation_id,
			'directory_id'       => $payload->directory_id,
			'editor_binding_id'  => $payload->inspection->editor_binding_id,
			'editor_epoch'       => $payload->inspection->editor_epoch,
			'patch'              => $payload->inspection->patch,
		);
	}

	/** Native Save must prove the issued ticket still equals the reviewed artifact. */
	public function ticket_matches( EffectPlan $plan, int $source_id, string $impact_hash, object $inspection ): bool {
		try {
			$loaded  = $this->load( $plan );
			$payload = $loaded->payload;
			$this->assert_target( $plan, $plan->targets[0], $payload );
			return $payload->source_id === $source_id && current_user_can( 'edit_post', $source_id ) && hash_equals( $impact_hash, $this->impact_hash( $payload ) ) && $this->canonical->encode( $inspection ) === $this->canonical->encode( $payload->inspection ) && hash_equals( $loaded->environment, $this->environment->read()->fingerprint() );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Ticket issuance is deliberately an unknown ledger outcome. */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$loaded  = $this->load( $plan );
		$payload = $loaded->payload;
		$this->assert_target( $plan, $target, $payload );
		$adapter = $this->adapters->adapter( $payload->builder );
		try {
			$stage = $this->stages->read( $payload->draft_operation_id, true );
			$this->same_stage( $stage, $payload );
			$this->same_inspection( $payload->inspection, $this->inspection( $adapter->inspect( $payload->source_id, $payload->draft_id, $payload->directory_id, $payload->inspection->editor_binding_id, $stage ), $stage, $payload->directory_id ) );
			if ( ! hash_equals( $loaded->environment, $this->environment->read()->fingerprint() ) ) {
				throw new ContractException( 'stale_version', 'Native environment changed before live ticket issuance.' );
			}
			$check  = function () use ( $payload, $loaded, $adapter ): void {
				$stage = $this->stages->read( $payload->draft_operation_id, true );
				$this->same_stage( $stage, $payload );
				$this->same_inspection( $payload->inspection, $this->inspection( $adapter->inspect( $payload->source_id, $payload->draft_id, $payload->directory_id, $payload->inspection->editor_binding_id, $stage ), $stage, $payload->directory_id ) );
				if ( ! hash_equals( $loaded->environment, $this->environment->read()->fingerprint() ) ) {
					throw new ContractException( 'stale_version', 'Native environment changed at live ticket issuance.' );
				}
			};
			$result = $adapter->issue( $payload->inspection, $plan->operation_id, $this->impact_hash( $payload ), $check );
			return $this->outcome( $result, $plan, $target, $payload, $fence );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		} catch ( \Throwable $error ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array( 'source_page_id' => $payload->source_id ), array(), 'ticket_issue_uncertain' );
		}
	}

	/** A later read observes native Save or an expired unchanged ticket. */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$payload = $this->load( $plan )->payload;
		$this->assert_target( $plan, $target, $payload );
		try {
			$result = $this->adapters->adapter( $payload->builder )->reconcile( $payload->inspection, $plan->operation_id, $this->impact_hash( $payload ) );
			return $this->outcome( $result, $plan, $target, $payload, $fence );
		} catch ( \Throwable $error ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array( 'source_page_id' => $payload->source_id ), array(), 'native_reconciliation_unavailable' );
		}
	}

	private function inspection( object $inspection, object $stage, int $directory_id ): object {
		$required = array( 'builder', 'directory_id', 'draft_id', 'editor_binding_id', 'editor_epoch', 'editor_fingerprint', 'editor_generation', 'editor_session', 'patch', 'source_id', 'source_revision', 'source_status' );
		$keys     = array_keys( get_object_vars( $inspection ) );
		sort( $keys, SORT_STRING );
		$patch      = $inspection->patch ?? null;
		$patch_keys = $patch instanceof \stdClass ? array_keys( get_object_vars( $patch ) ) : array();
		sort( $patch_keys, SORT_STRING );
		$review      = $patch->review ?? null;
		$review_keys = $review instanceof \stdClass ? array_keys( get_object_vars( $review ) ) : array();
		sort( $review_keys, SORT_STRING );
		$native = $patch->native ?? null;
		if (
			$required !== $keys ||
			$stage->builder !== $inspection->builder ||
			$stage->source_id !== $inspection->source_id ||
			$stage->draft_id !== $inspection->draft_id ||
			$directory_id !== $inspection->directory_id ||
			array( 'after_tree_hash', 'before_tree_hash', 'native', 'protected_tree_hash', 'review', 'scope' ) !== $patch_keys ||
			! $native instanceof \stdClass ||
			array( 'after', 'before', 'control_path', 'kind', 'target_ref' ) !== $review_keys ||
			'plain_text' !== $review->kind ||
			! is_string( $review->target_ref ) ||
			'' === $review->target_ref ||
			strlen( $review->target_ref ) > 128 ||
			! is_string( $review->control_path ) ||
			'' === $review->control_path ||
			strlen( $review->control_path ) > 128 ||
			! is_string( $review->before ) ||
			! is_string( $review->after ) ||
			strlen( $review->after ) > 2048 ||
			! is_int( $inspection->editor_generation ) ||
			$inspection->editor_generation < 1
		) {
			throw new ContractException( 'unsupported_contract', 'Native scoped live inspection is invalid.' );
		}
		$this->assert_stage_scope( $stage, $directory_id );
		if ( $this->canonical->encode( $patch->scope ) !== $this->canonical->encode( $stage->scope ) ) {
			throw new ContractException( 'scope_conflict', 'Native live patch differs from reviewed scope.' );
		}
		foreach ( array( $patch->before_tree_hash, $patch->after_tree_hash, $patch->protected_tree_hash, $inspection->source_revision, $inspection->editor_epoch, $inspection->editor_fingerprint, $inspection->editor_session ) as $hash ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new ContractException( 'unsupported_contract', 'Native scoped live fence is invalid.' );
			}
		}
		if ( strlen( $this->canonical->encode( $inspection ) ) > 65536 ) {
			throw new ContractException( 'needs_expansion', 'Native scoped live evidence is too large.' );
		}
		return $inspection;
	}

	private function same_inspection( object $left, object $right ): void {
		if ( $this->canonical->encode( $left ) !== $this->canonical->encode( $right ) ) {
			throw new ContractException( 'stale_version', 'Native editor, source or candidate changed after live review.' );
		}
	}

	private function same_stage( object $stage, object $payload ): void {
		if ( $stage->operation_id !== $payload->draft_operation_id || $stage->builder !== $payload->builder || $stage->source_id !== $payload->source_id || $stage->draft_id !== $payload->draft_id || $stage->scope->directory_id !== (string) $payload->directory_id || $this->canonical->encode( $stage->scope ) !== $this->canonical->encode( $payload->inspection->patch->scope ) ) {
			throw new ContractException( 'evidence_stale', 'The reviewed native stage changed.' );
		}
	}

	private function assert_stage_scope( object $stage, int $directory_id ): void {
		if ( $stage->scope->directory_id !== (string) $directory_id ) {
			throw new ContractException( 'scope_conflict', 'Scoped live ticket must match the reviewed directory.' );
		}
	}

	private function load( EffectPlan $plan ): object {
		if ( 'builder.live_apply' !== $plan->capability || '1' !== $plan->capability_revision || 1 !== count( $plan->targets ) || null === $plan->run_id || ! preg_match( '/^user:([1-9][0-9]*)$/D', $plan->actor_ref, $match ) || get_current_user_id() !== (int) $match[1] ) {
			throw new ContractException( 'approval_mismatch', 'Scoped live plan is invalid.' );
		}
		$row     = $this->artifacts->get( Actor::user( (int) $match[1] )->owner(), $plan->operation_id, 1 );
		$payload = ( new JsonDecoder( 2097152, 48 ) )->decode( $row['payload_json'] );
		if ( 'proposal' !== $row['kind'] || ! hash_equals( $plan->proposal_hash, $row['content_hash'] ) || ! hash_equals( $row['content_hash'], hash( 'sha256', $row['payload_json'] ) ) || ! $payload instanceof \stdClass || ( $payload->kind ?? null ) !== 'builder_scoped_live_ticket' || ( $payload->run_id ?? null ) !== $plan->run_id || ! is_int( $payload->source_id ?? null ) || ! is_int( $payload->draft_id ?? null ) || ! is_int( $payload->directory_id ?? null ) || ! $payload->inspection instanceof \stdClass ) {
			throw new ContractException( 'evidence_stale', 'The exact reviewed live artifact is unavailable.' );
		}
		$stage = $this->stages->read( $payload->draft_operation_id, false );
		$this->same_stage( $stage, $payload );
		$this->inspection( $payload->inspection, $stage, $payload->directory_id );
		return (object) array( 'payload' => $payload, 'environment' => $row['environment_hash'] );
	}

	private function assert_target( EffectPlan $plan, EffectTarget $target, object $payload ): void {
		$reviewed = $plan->targets[0];
		if ( 'builder-live:post:' . $payload->source_id !== $target->key || $target->key !== $reviewed->key || $target->expected_version !== $reviewed->expected_version || $target->effect_hash !== $reviewed->effect_hash || $target->expected_version !== $payload->inspection->patch->before_tree_hash || $target->effect_hash !== $payload->inspection->patch->after_tree_hash ) {
			throw new ContractException( 'approval_mismatch', 'Scoped live target differs from its exact review.' );
		}
	}

	private function impact_hash( object $payload ): string {
		return hash( 'sha256', $this->canonical->encode( (object) array( 'draft_operation_id' => $payload->draft_operation_id, 'inspection' => $payload->inspection ) ) );
	}

	private function outcome( object $receipt, EffectPlan $plan, EffectTarget $target, object $payload, int $fence ): EffectOutcome {
		$state = $receipt->state ?? null;
		$after = $receipt->after_hash ?? null;
		if ( ! in_array( $state, array( 'succeeded', 'no_effect', 'failed', 'unknown' ), true ) || ( $receipt->source_id ?? null ) !== $payload->source_id || ( $receipt->draft_id ?? null ) !== $payload->draft_id || ( $receipt->operation_id ?? null ) !== $plan->operation_id || ( $receipt->before_hash ?? null ) !== $target->expected_version || ( $receipt->impact_hash ?? null ) !== $this->impact_hash( $payload ) || ( 'succeeded' === $state ? $after !== $target->effect_hash : null !== $after ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_live_receipt_invalid' );
		}
		$effects = (object) array( 'builder' => $payload->builder, 'source_page_id' => $payload->source_id, 'draft_post_id' => $payload->draft_id, 'draft_operation_id' => $payload->draft_operation_id, 'editor_epoch' => $payload->inspection->editor_epoch, 'fence' => $fence );
		return new EffectOutcome( $state, $target->expected_version, $after, $effects, array(), $receipt->error_code ?? null );
	}

	private function assert_context( Context $context ): void {
		$context->assert_execution_identity();
		if ( ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || ! in_array( $context->transport(), array( 'ui', 'worker' ), true ) || $context->actor()->is_guest() || get_current_user_id() !== $context->actor()->user_id() || null === $context->conversation_id() || null === $context->run_id() ) {
			throw new ContractException( 'unauthorized', 'Scoped live review needs an authenticated current workflow.' );
		}
	}

	private function active_run( Context $context ): int {
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id AND c.active_run_id=r.id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.erasure_requested=0 AND r.state='running' AND r.cancel_requested=0", array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'unauthorized', 'Scoped live review needs its current owning run.' );
		}
		return (int) $rows[0]['id'];
	}

	private function assert_binding( Context $context, object $stage, int $directory_id ): void {
		$json    = BuilderConversationTarget::active_context( $this->db, $context );
		$binding = is_string( $json ) ? ( new JsonDecoder( 65536, 12 ) )->decode( $json ) : null;
		if ( ! $binding instanceof \stdClass || ( $binding->target ?? null ) !== $stage->document->native_id || $this->canonical->encode( $binding->builder_scope ?? null ) !== $this->canonical->encode( $stage->scope ) || ( $binding->builder_document->builder ?? null ) !== $stage->builder || ( $binding->builder_document->native_kind ?? null ) !== 'page' ) {
			throw new ContractException( 'unauthorized', 'Scoped live review requires its selected page source and scope.' );
		}
		BuilderConversationTarget::assert_catalog_binding( $json, $stage->builder, $directory_id );
	}
}
