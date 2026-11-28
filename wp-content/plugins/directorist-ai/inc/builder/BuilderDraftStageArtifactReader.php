<?php
/**
 * Resolve one reviewed private native draft candidate from an exact effect plan.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal exact artifact boundary.

use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Reads only; the native effect handler must still revalidate current authority. */
final class BuilderDraftStageArtifactReader {
	private Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/** Return execution bytes only after checking the current native capture. */
	public function for_plan( EffectPlan $plan ): object {
		$loaded = $this->reviewed_artifact( $plan );
		if ( 'editor_candidate' === ( $loaded->payload->output_mode ?? null ) ) {
			\DirectoristAIPro\UI\EditorWorkingCopy::assert_candidate_source( $loaded->payload );
		}
		return $loaded;
	}

	/** Historical lineage only; this read never grants candidate or execution authority. */
	public function historical_candidate_metadata( EffectPlan $plan ): object {
		return self::candidate_history_metadata( $this->reviewed_artifact( $plan ) );
	}

	/** Resolve exact immutable artifact/plan bindings for execution or history. */
	private function reviewed_artifact( EffectPlan $plan ): object {
		if ( 'builder.stage' !== $plan->capability || '1' !== $plan->capability_revision || null === $plan->run_id || 1 !== count( $plan->targets ) ) {
			throw new ContractException( 'approval_mismatch', 'Native draft effect plan has the wrong capability or target count.' );
		}
		$artifacts     = $this->db->table( 'artifacts' );
		$conversations = $this->db->table( 'conversations' );
		$runs          = $this->db->table( 'runs' );
		$params        = array( $plan->operation_id, $plan->actor_ref, $plan->proposal_hash, $plan->run_id );
		$where         = '';
		if ( null !== $plan->approval_id ) {
			$approvals = $this->db->table( 'approvals' );
			$where     = "INNER JOIN $approvals p ON p.artifact_id=a.public_id AND p.artifact_version=a.version AND p.id=%d AND p.actor_ref=a.owner_ref AND p.capability='builder.stage' AND p.proposal_hash=a.content_hash";
			$params    = array( $plan->approval_id, $plan->operation_id, $plan->actor_ref, $plan->proposal_hash, $plan->run_id );
		}
		$rows = $this->db->rows( "SELECT a.payload_json,a.content_hash,a.environment_hash,a.public_id,a.version,c.public_id AS conversation_public_id,r.public_id AS run_public_id FROM $artifacts a INNER JOIN $conversations c ON c.id=a.conversation_id AND c.owner_ref=a.owner_ref INNER JOIN $runs r ON r.conversation_id=c.id AND r.owner_ref=a.owner_ref $where WHERE a.public_id=%s AND a.owner_ref=%s AND a.kind='builder_draft' AND a.content_hash=%s AND r.id=%d AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND (a.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) LIMIT 1", $params );
		if ( ! $rows ) {
			throw new ContractException( 'approval_mismatch', 'Reviewed native draft candidate is unavailable.' );
		}
		$row = $rows[0];
		if ( ! hash_equals( $row['content_hash'], hash( 'sha256', $row['payload_json'] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Reviewed native draft artifact bytes changed.' );
		}
		$payload = ( new JsonDecoder( 5242880, 32 ) )->decode( $row['payload_json'] );
		if ( $payload instanceof \stdClass && isset( $payload->completion_notes ) ) {
			BuilderCompletionNotes::validate( $payload->completion_notes );
		}
		$target = $plan->targets[0];
		if ( ! $payload instanceof \stdClass || 'builder_draft_stage' !== ( $payload->kind ?? null ) || ! in_array( $payload->intent ?? null, array( 'private_page_draft', 'private_template_draft' ), true ) || ! is_string( $payload->document_key ?? null ) || $row['public_id'] !== $plan->operation_id || ! preg_match( '/^builder-draft:[a-f0-9]{64}$/D', $target->key ) || 'builder-draft:' . hash( 'sha256', $payload->document_key ) !== $target->key ) {
			throw new ContractException( 'evidence_stale', 'Reviewed native draft identity differs from its stage plan.' );
		}
		if ( ! is_string( $payload->source_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $payload->source_hash ) || ! hash_equals( $target->expected_version, $payload->source_hash ) || ! is_string( $payload->native_content ?? null ) || strlen( $payload->native_content ) > 1048576 || ! is_string( $payload->native_content_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $payload->native_content_hash ) || ! hash_equals( $payload->native_content_hash, hash( 'sha256', $payload->native_content ) ) || ! hash_equals( $target->effect_hash, $payload->native_content_hash ) ) {
			throw new ContractException( 'evidence_stale', 'Reviewed native draft content differs from its stage plan.' );
		}
		if ( null !== ( $payload->dependency_revision ?? null ) && ( ! is_string( $payload->dependency_revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $payload->dependency_revision ) ) ) {
			throw new ContractException( 'evidence_stale', 'Reviewed native draft dependency revision is invalid.' );
		}
		$document           = $payload->source_document ?? null;
		$page               = 'private_page_draft' === $payload->intent && is_int( $payload->source_page_id ?? null ) && 0 < $payload->source_page_id && null === ( $payload->source_template_ref ?? null ) && $document instanceof \stdClass && in_array( $document->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) && 'page' === ( $document->native_kind ?? null ) && hash_equals( 'post:' . $payload->source_page_id, (string) ( $document->native_id ?? '' ) );
		$template_base      = 'private_template_draft' === $payload->intent && null === ( $payload->source_page_id ?? null ) && is_string( $payload->source_template_ref ?? null ) && $document instanceof \stdClass && ( $document->native_id ?? null ) === $payload->source_template_ref;
		$gutenberg_template = $template_base && 'gutenberg' === ( $document->builder ?? null ) && in_array( $document->native_kind ?? null, array( 'template:single_listing', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search', 'template_part' ), true ) && 1 === preg_match( '/^template:(wp_template|wp_template_part):[A-Za-z0-9_.\/-]+:[A-Za-z0-9_.-]+$/D', $payload->source_template_ref ) && ( ( 'template_part' === ( $document->native_kind ?? null ) ) === str_starts_with( $payload->source_template_ref, 'template:wp_template_part:' ) );
		$divi_template      = $template_base && 'divi' === ( $document->builder ?? null ) && in_array( $document->native_kind ?? null, array( 'template:single_listing', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) && 1 === preg_match( '/^post:[1-9][0-9]*$/D', $payload->source_template_ref );
		$elementor_template = $template_base && 'elementor' === ( $document->builder ?? null ) && in_array( $document->native_kind ?? null, array( 'template:single_listing', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) && 1 === preg_match( '/^post:[1-9][0-9]*$/D', $payload->source_template_ref );
		if ( $elementor_template ) {
			$scope = $payload->source_scope ?? null;
			$keys  = $scope instanceof \stdClass ? array_keys( get_object_vars( $scope ) ) : array();
			sort( $keys, SORT_STRING );
			if ( array( 'breakpoint_id', 'directory_id', 'interaction', 'view' ) !== $keys || ( null !== $scope->directory_id && ( ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $scope->directory_id ) ) ) || ! in_array( $scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || null !== $scope->breakpoint_id || 'normal' !== $scope->interaction ) {
				throw new ContractException( 'evidence_stale', 'Elementor template stage has no exact saved source scope.' );
			}
		}
		$template = $gutenberg_template || $divi_template || $elementor_template;
		if ( ( ! $page && ! $template ) || ! is_string( $payload->conversation_id ?? null ) || ! is_string( $payload->run_id ?? null ) || $row['conversation_public_id'] !== $payload->conversation_id || $row['run_public_id'] !== $payload->run_id ) {
			throw new ContractException( 'evidence_stale', 'Reviewed native draft payload or target differs from its stage plan.' );
		}
		if ( 'editor_candidate' === ( $payload->output_mode ?? null ) ) {
			if ( ( $payload->candidate_id ?? null ) !== $plan->operation_id || false !== ( $payload->saved ?? null ) ) {
				throw new ContractException( 'evidence_stale', 'Native canvas candidate identity differs from its exact stage.' );
			}
		}
		return (object) array(
			'payload'          => $payload,
			'environment_hash' => $row['environment_hash'],
			'artifact'         => (object) array(
				'id'      => $row['public_id'],
				'version' => (int) $row['version'],
				'hash'    => $row['content_hash'],
			),
		);
	}

	/** Copy closed lineage metadata without native bytes, source authority or control data. */
	private static function candidate_history_metadata( object $loaded ): object {
		$payload = $loaded->payload;
		if ( 'editor_candidate' !== ( $payload->output_mode ?? null ) || false !== ( $payload->saved ?? null ) || ! is_string( $payload->candidate_id ?? null ) || ! preg_match( '/^builder-stage-[A-Za-z0-9_.:-]{1,112}$/D', $payload->candidate_id ) || ! is_string( $payload->capture_id ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $payload->capture_id ) || ! is_string( $payload->captured_snapshot_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $payload->captured_snapshot_hash ) || ! is_string( $payload->source_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $payload->source_hash ) ) {
			throw new ContractException( 'evidence_stale', 'Historical canvas candidate metadata is unavailable.' );
		}
		$documents = array();
		foreach ( array( 'original_document', 'source_document' ) as $field ) {
			$document = $payload->{$field} ?? null;
			$keys     = $document instanceof \stdClass ? array_keys( get_object_vars( $document ) ) : array();
			sort( $keys, SORT_STRING );
			if ( array( 'builder', 'native_id', 'native_kind' ) !== $keys || ! is_string( $document->builder ) || ! is_string( $document->native_kind ) || ! is_string( $document->native_id ) || strlen( $document->native_id ) > 256 || ! preg_match( '/^(?:post:[1-9][0-9]*|template:(?:wp_template|wp_template_part):[A-Za-z0-9_.\/-]+:[A-Za-z0-9_.-]+)$/D', $document->native_id ) ) {
				throw new ContractException( 'evidence_stale', 'Historical canvas document metadata is unavailable.' );
			}
			$documents[ $field ] = (object) array(
				'builder'     => $document->builder,
				'native_kind' => $document->native_kind,
				'native_id'   => $document->native_id,
			);
		}
		$metadata = (object) array(
			'output_mode'            => 'editor_candidate',
			'saved'                  => false,
			'candidate_id'           => $payload->candidate_id,
			'conversation_id'        => $payload->conversation_id,
			'run_id'                 => $payload->run_id,
			'source_hash'            => $payload->source_hash,
			'capture_id'             => $payload->capture_id,
			'captured_snapshot_hash' => $payload->captured_snapshot_hash,
			'original_document'      => $documents['original_document'],
			'source_document'        => $documents['source_document'],
		);
		( new JsonDecoder( 8192, 8 ) )->check( $metadata );
		return (object) array(
			'artifact' => (object) array(
				'id'      => $loaded->artifact->id,
				'version' => $loaded->artifact->version,
				'hash'    => $loaded->artifact->hash,
			),
			'metadata' => $metadata,
		);
	}
}
