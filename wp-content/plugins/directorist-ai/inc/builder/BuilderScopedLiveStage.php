<?php
/**
 * Exact owned page-stage evidence for a separate native editor live review.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact receipt validation.

use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** A private draft receipt never grants live authority by itself. */
final class BuilderScopedLiveStage {
	private Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	public function read( string $operation_id, bool $require_current ): object {
		GraphVersion::identifier( $operation_id );
		$user = get_current_user_id();
		if ( $user < 1 ) {
			throw new ContractException( 'unauthorized', 'Scoped live review requires an authenticated owner.' );
		}
		$rows = $this->db->rows( 'SELECT receipt_json FROM ' . $this->db->table( 'operations' ) . " WHERE public_id=%s AND actor_ref=%s AND capability='builder.stage' AND capability_revision='1' AND state='succeeded' LIMIT 2", array( $operation_id, 'user:' . $user ) );
		if ( 1 !== count( $rows ) || ! is_string( $rows[0]['receipt_json'] ) || strlen( $rows[0]['receipt_json'] ) > 1048576 ) {
			throw new ContractException( 'evidence_stale', 'The reviewed native stage is unavailable.' );
		}
		$receipt  = ( new JsonDecoder( 1048576, 32 ) )->decode( $rows[0]['receipt_json'] );
		$plan     = $receipt->plan ?? null;
		$outcomes = ( $receipt->targets ?? null ) instanceof \stdClass ? array_values( get_object_vars( $receipt->targets ) ) : array();
		$outcome  = 1 === count( $outcomes ) ? $outcomes[0] : null;
		if ( ! $plan instanceof \stdClass || ! $outcome instanceof \stdClass || ( $receipt->state ?? null ) !== 'succeeded' || ( $receipt->operation_id ?? null ) !== $operation_id || ( $outcome->status ?? null ) !== 'succeeded' || ! is_array( $plan->targets ?? null ) || 1 !== count( $plan->targets ) || ( $plan->operation_id ?? null ) !== $operation_id || ( $plan->actor_ref ?? null ) !== 'user:' . $user || ( $plan->capability ?? null ) !== 'builder.stage' || ( $plan->capability_revision ?? null ) !== '1' ) {
			throw new ContractException( 'evidence_stale', 'The exact native stage receipt is invalid.' );
		}
		$target = $plan->targets[0];
		$exact  = new EffectPlan( $operation_id, 'user:' . $user, 'builder.stage', '1', $plan->proposal_hash, array( new EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), $plan->run_id, $plan->approval_id );
		if ( ( $receipt->plan_hash ?? null ) !== $exact->hash() || ( $receipt->proposal_hash ?? null ) !== $exact->proposal_hash || ( $receipt->target_set_hash ?? null ) !== $exact->target_set_hash() || ( $outcome->key ?? null ) !== $target->key || ( $outcome->expected_version ?? null ) !== $target->expected_version || ( $outcome->effect_hash ?? null ) !== $target->effect_hash || ( $outcome->before_version ?? null ) !== $target->expected_version || ( $outcome->after_version ?? null ) !== $target->effect_hash || ( $outcome->effects->content_hash ?? null ) !== $target->effect_hash ) {
			throw new ContractException( 'evidence_stale', 'The native stage receipt changed after review.' );
		}
		$stage     = ( new BuilderDraftStageArtifactReader( $this->db ) )->for_plan( $exact );
		$payload   = $stage->payload;
		$proposal  = $this->reviewed_proposal( $payload, $user );
		$scope     = $proposal->requested_scope;
		$source    = $payload->source_document ?? null;
		$source_id = $payload->source_page_id ?? null;
		$draft_id  = $outcome->effects->draft_post_id ?? null;
		if ( 'private_page_draft' !== ( $payload->intent ?? null ) || ! $source instanceof \stdClass || 'page' !== ( $source->native_kind ?? null ) || ! in_array( $source->builder ?? null, array( 'elementor', 'gutenberg', 'divi' ), true ) || ! is_int( $source_id ) || ! is_int( $draft_id ) || $source_id < 1 || $draft_id < 1 || ( $source->native_id ?? null ) !== 'post:' . $source_id || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'edit_post', $draft_id ) || (int) get_post_field( 'post_author', $draft_id ) !== $user || (int) get_post_field( 'post_parent', $draft_id ) !== $source_id ) {
			throw new ContractException( 'scope_conflict', 'Scoped live apply accepts only an owned page and isolated page draft.' );
		}
		if ( $require_current ) {
			$request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/drafts/' . $operation_id );
			$request->set_param( 'operation_id', $operation_id );
			$result = ( new BuilderDraftResultApi( $this->db ) )->read( $request );
			if ( ! is_array( $result ) || true !== ( $result['reviewed_content_current'] ?? null ) || ( $result['draft_post_id'] ?? null ) !== $draft_id || ( $result['builder'] ?? null ) !== $source->builder || ( $result['status'] ?? null ) !== 'draft' ) {
				throw new ContractException( 'stale_version', 'The reviewed native page draft changed.' );
			}
		}
		return (object) array(
			'operation_id'       => $operation_id,
			'builder'            => $source->builder,
			'source_id'          => $source_id,
			'draft_id'           => $draft_id,
			'source_hash'        => $target->expected_version,
			'candidate_hash'     => $target->effect_hash,
			'document'           => $source,
			'scope'              => $scope,
			'normalized_changes' => $proposal->normalized_changes,
		);
	}

	/** Recover scope only from the original immutable owner/hash-bound proposal. */
	private function reviewed_proposal( object $payload, int $user ): object {
		$ref = $payload->proposal_ref ?? null;
		if ( ! $ref instanceof \stdClass || ! is_string( $ref->id ?? null ) || ! is_int( $ref->version ?? null ) || $ref->version < 1 || ! is_string( $ref->hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $ref->hash ) ) {
			throw new ContractException( 'evidence_stale', 'Scoped live stage has no original reviewed proposal.' );
		}
		$row = ( new ArtifactStore( $this->db ) )->get( Actor::user( $user )->owner(), $ref->id, $ref->version );
		if ( 'proposal' !== $row['kind'] || ! hash_equals( $ref->hash, $row['content_hash'] ) || ! hash_equals( $ref->hash, hash( 'sha256', $row['payload_json'] ) ) || (int) $row['conversation_id'] < 1 ) {
			throw new ContractException( 'evidence_stale', 'Scoped live original proposal changed.' );
		}
		$proposal = ( new JsonDecoder( 2097152, 32 ) )->decode( $row['payload_json'] );
		$scope    = $proposal->requested_scope ?? null;
		$keys     = $scope instanceof \stdClass ? array_keys( get_object_vars( $scope ) ) : array();
		sort( $keys, SORT_STRING );
		$change      = is_array( $proposal->normalized_changes ?? null ) && 1 === count( $proposal->normalized_changes ) ? $proposal->normalized_changes[0] : null;
		$view        = $scope->view ?? null;
		$breakpoint  = $scope->breakpoint_id ?? null;
		$interaction = $scope->interaction ?? null;
		if ( ! $proposal instanceof \stdClass || 'builder_change' !== ( $proposal->kind ?? null ) || ( $proposal->document_key ?? null ) !== $payload->document_key || array( 'breakpoint_id', 'directory_id', 'interaction', 'view' ) !== $keys || ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,18}$/D', $scope->directory_id ) || ( null !== $view && ( ! is_string( $view ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $view ) ) ) || ( null !== $breakpoint && ( ! is_string( $breakpoint ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $breakpoint ) ) ) || ! is_string( $interaction ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $interaction ) || ! $change instanceof \stdClass || 'set_control' !== ( $change->op ?? null ) || ( new JsonCanonicalizer() )->encode( $change->scope ?? null ) !== ( new JsonCanonicalizer() )->encode( $scope ) || ( isset( $payload->source_scope ) && ( new JsonCanonicalizer() )->encode( $payload->source_scope ) !== ( new JsonCanonicalizer() )->encode( $scope ) ) ) {
			throw new ContractException( 'scope_conflict', 'Scoped live stage has no exact supported reviewed scope.' );
		}
		return $proposal;
	}
}
