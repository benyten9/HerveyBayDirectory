<?php
/**
 * Versioned Directorist listing proposals and exact review creation.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Domain;

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Proposal artifacts contain reviewed changes, never a replacement form payload. */
final class ListingProposalService {
	/**
	 * Runtime storage.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Native read adapter.
	 *
	 * @var DirectoristInspector
	 */
	private DirectoristInspector $inspector;
	/**
	 * Human approval lifecycle.
	 *
	 * @var ApprovalService
	 */
	private ApprovalService $approvals;

	/**
	 * Bind native inspection, artifact storage and approval creation.
	 *
	 * @param Database             $db Runtime storage.
	 * @param DirectoristInspector $inspector Native read adapter.
	 * @param ApprovalService      $approvals Human approval lifecycle.
	 */
	public function __construct( Database $db, DirectoristInspector $inspector, ApprovalService $approvals ) {
		$this->db        = $db;
		$this->inspector = $inspector;
		$this->approvals = $approvals;
	}

	/**
	 * Check proposal ownership and the server-bound workflow identity.
	 *
	 * @param Context $context Current actor/profile/run context.
	 * @param object  $input Strict proposal input.
	 */
	public function permitted( Context $context, object $input ): bool {
		if ( null === $context->conversation_id() || null === $context->run_id() ) {
			return false;
		}
		if ( null === $input->listing_id ) {
			try {
				$this->inspector->creation_eligibility( $context, $input->directory_id );
				return true;
			} catch ( ContractException $error ) {
				return false;
			}
		}
		return $this->inspector->can_inspect( $context, $input->directory_id, array( $input->listing_id ) );
	}

	/**
	 * Create one idempotent private proposal and its pending exact review.
	 *
	 * @param Context $context Current actor/profile/run context.
	 * @param object  $input Strict proposal input.
	 * @return object Artifact, diff, prediction and review reference.
	 * @throws ContractException Stale, forbidden or invalid change.
	 */
	public function propose( Context $context, object $input ): object {
		if ( ! $this->permitted( $context, $input ) ) {
			throw new ContractException( 'unauthorized', 'Listing proposal is unavailable.' );
		}
		if ( null === $input->listing_id ) {
			if ( null !== $input->base ) {
				throw new ContractException( 'invalid_input', 'A new listing cannot have an existing base.' );
			}
			return $this->propose_creation( $context, $input );
		}
		if ( null === $input->base ) {
			throw new ContractException( 'invalid_input', 'An existing listing needs its exact base.' );
		}
		$native     = $this->inspector->proposal_context( $context, $input->directory_id, $input->listing_id );
		$inspection = $native['inspection'];
		if ( ! $inspection->complete ) {
			throw new ContractException( 'incomplete_graph', 'Listing constraints are incomplete for a safe proposal.' );
		}
		$listing = $inspection->listings[0];
		$this->assert_base( $listing->version, $input->base, $input->listing_id );
		$descriptors = array();
		foreach ( $native['descriptors'] as $descriptor ) {
			$descriptors[ $descriptor->field_id ] = $descriptor;
		}
		$values = array();
		foreach ( $listing->fields as $fact ) {
			$values[ $fact->field_id ] = $fact->value;
		}
		$directory = $this->inspector->describe( $context, $input->directory_id, $context->actor()->can( 'manage_options' ) ? 'management' : 'submission' );
		$changes   = $this->changes( $input->changes, $descriptors, $directory, $values );
		$after     = $values;
		$diff      = array();
		foreach ( $changes as $change ) {
			$before                     = $after[ $change->field_id ] ?? null;
			$after[ $change->field_id ] = 'clear' === $change->action ? null : $change->value;
			$diff[]                     = (object) array(
				'field_id' => $change->field_id,
				'before'   => $before,
				'after'    => $after[ $change->field_id ],
			);
		}
		$posted = $this->hydrate( $input->listing_id, $input->directory_id, $native['native_fields'], $after );
		$this->validate_native( $input->directory_id, $native['native_fields'], $posted );
		$predicted_status = (string) directorist_get_listing_edit_status( $input->directory_id, $input->listing_id );
		$payload          = (object) array(
			'kind'             => 'listing_change',
			'directory_id'     => $input->directory_id,
			'listing_id'       => $input->listing_id,
			'base'             => $input->base,
			'changes'          => $changes,
			'diff'             => $diff,
			'predicted_status' => $predicted_status,
		);
		$identity         = hash( 'sha256', $context->run_id() . "\0" . json_encode( $payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
		$artifact_id      = 'listing-proposal-' . substr( $identity, 0, 32 );
		$artifact         = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), $artifact_id, 'proposal', $payload, $native['environment_hash'] );
		$run_id           = $this->run_internal_id( $context );
		$operation_id     = 'listing-apply-' . substr( $identity, 0, 32 );
		$plan             = new EffectPlan(
			$operation_id,
			$context->actor()->owner()->reference(),
			'listing.apply',
			'1',
			$artifact['content_hash'],
			array( new EffectTarget( 'listing:' . $input->listing_id, $listing->version->content_hash, $artifact['content_hash'] ) ),
			$run_id
		);
		$review           = $this->approvals->create(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				(int) $artifact['version'],
				$artifact['content_hash'],
				$native['environment_hash'],
				(object) array(
					'kind'             => 'listing_change',
					'listing_id'       => $input->listing_id,
					'current_status'   => $listing->status,
					'predicted_status' => $predicted_status,
					'diff'             => $diff,
				),
				(object) array(
					'max_targets' => 1,
					'max_fields'  => 50,
				)
			)
		);
		return (object) array(
			'capability'       => 'listing.apply',
			'artifact'         => (object) array(
				'id'      => $artifact['public_id'],
				'version' => (int) $artifact['version'],
				'hash'    => $artifact['content_hash'],
			),
			'operation_id'     => $operation_id,
			'review'           => (object) array(
				'id'          => $review['id'],
				'state'       => $review['state'],
				'review_hash' => $review['review_hash'],
				'expires_at'  => $review['expires_at'],
			),
			'diff'             => $diff,
			'predicted_status' => $predicted_status,
		);
	}

	/**
	 * Build a complete unsaved form, exact review and synthetic creation lock.
	 *
	 * @param Context $context Reviewed input.
	 * @param object  $input Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When native validation fails.
	 */
	private function propose_creation( Context $context, object $input ): object {
		$native    = $this->inspector->creation_context( $context, $input->directory_id );
		$directory = $native['directory'];
		if ( ! $directory->complete ) {
			throw new ContractException( 'incomplete_graph', 'Directory constraints are incomplete for a safe creation.' );
		}
		$descriptors = array();
		foreach ( $native['descriptors'] as $descriptor ) {
			$descriptors[ $descriptor->field_id ] = $descriptor;
		}
		$changes = $this->changes( $input->changes, $descriptors, $directory, array() );
		$values  = array();
		$diff    = array();
		foreach ( $changes as $change ) {
			$values[ $change->field_id ] = 'clear' === $change->action ? null : $change->value;
			$diff[]                      = (object) array(
				'field_id' => $change->field_id,
				'before'   => null,
				'after'    => $values[ $change->field_id ]
			);
		}
		$posted                   = $this->hydrate( 0, $input->directory_id, $native['native_fields'], $values );
		$posted['t_c_check']      = true;
		$posted['privacy_policy'] = true;
		$posted['plan_id']        = $native['eligibility']->plan_id;
		$this->validate_native( $input->directory_id, $native['native_fields'], $posted );
		$predicted_status = (string) directorist_get_listing_create_status( $input->directory_id );
		if ( '' === $predicted_status ) {
			$predicted_status = 'draft'; // WordPress normalizes an empty insert status to draft.
		}
		$payload      = (object) array(
			'kind'                => 'listing_creation',
			'directory_id'        => $input->directory_id,
			'listing_id'          => null,
			'base'                => null,
			'changes'             => $changes,
			'diff'                => $diff,
			'predicted_status'    => $predicted_status,
			'eligibility_version' => $native['eligibility']->version,
			'plan_id'             => $native['eligibility']->plan_id
		);
		$identity     = hash( 'sha256', $context->run_id() . "\0" . json_encode( $payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
		$artifact_id  = 'listing-create-' . substr( $identity, 0, 32 );
		$artifact     = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), $artifact_id, 'proposal', $payload, $native['environment_hash'] );
		$operation_id = 'listing-create-' . substr( $identity, 0, 32 );
		$plan         = new EffectPlan( $operation_id, $context->actor()->owner()->reference(), 'listing.create', '1', $artifact['content_hash'], array( new EffectTarget( 'listingcreate:' . $context->actor()->user_id() . ':' . $input->directory_id, $native['eligibility']->version, $artifact['content_hash'] ) ), $this->run_internal_id( $context ) );
		$review       = $this->approvals->create(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				(int) $artifact['version'],
				$artifact['content_hash'],
				$native['environment_hash'],
				(object) array(
					'kind'             => 'listing_creation',
					'directory_id'     => $input->directory_id,
					'listing_id'       => null,
					'predicted_status' => $predicted_status,
					'diff'             => $diff,
					'requires_terms'   => (bool) directorist_should_check_terms_and_condition( $input->directory_id ),
					'requires_privacy' => (bool) directorist_should_check_privacy_policy( $input->directory_id )
				),
				(object) array(
					'max_targets' => 1,
					'max_fields'  => 50
				)
			)
		);
		return (object) array(
			'capability'       => 'listing.create',
			'artifact'         => (object) array(
				'id'      => $artifact['public_id'],
				'version' => (int) $artifact['version'],
				'hash'    => $artifact['content_hash']
			),
			'operation_id'     => $operation_id,
			'review'           => (object) array(
				'id'          => $review['id'],
				'state'       => $review['state'],
				'review_hash' => $review['review_hash'],
				'expires_at'  => $review['expires_at']
			),
			'diff'             => $diff,
			'predicted_status' => $predicted_status
		);
	}

	/**
	 * Rehydrate a creation from reviewed field values and freshly eligible native form.
	 *
	 * @param Context $context Reviewed input.
	 * @param object  $proposal Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When native validation fails.
	 */
	public function hydrate_creation( Context $context, object $proposal ): array {
		if ( 'listing_creation' !== ( $proposal->kind ?? null ) || null !== $proposal->listing_id || null !== $proposal->base ) {
			throw new ContractException( 'approval_mismatch', 'Reviewed creation proposal is invalid.' );
		}
		$native = $this->inspector->creation_context( $context, (int) $proposal->directory_id );
		if ( ! $native['directory']->complete || ! hash_equals( $native['eligibility']->version, $proposal->eligibility_version ) || $native['eligibility']->plan_id !== $proposal->plan_id ) {
			throw new ContractException( 'stale_version', 'Creation eligibility or directory changed.' );
		}
		$values = array();
		foreach ( $proposal->changes as $change ) {
			$values[ $change->field_id ] = 'clear' === $change->action ? null : $change->value;
		}
		$posted                   = $this->hydrate( 0, (int) $proposal->directory_id, $native['native_fields'], $values );
		$posted['t_c_check']      = true;
		$posted['privacy_policy'] = true;
		$posted['plan_id']        = $native['eligibility']->plan_id;
		$this->validate_native( (int) $proposal->directory_id, $native['native_fields'], $posted );
		return array(
			'native' => $native,
			'posted' => $posted,
			'values' => $values
		);
	}

	/**
	 * Rebuild the complete native form from current facts plus reviewed changes.
	 *
	 * @param Context $context Current actor context.
	 * @param object  $proposal Stored reviewed proposal.
	 * @return array Current native context and hydrated form.
	 * @throws ContractException Stale or invalid native state.
	 */
	public function hydrate_reviewed( Context $context, object $proposal ): array {
		$native     = $this->inspector->proposal_context( $context, $proposal->directory_id, $proposal->listing_id );
		$inspection = $native['inspection'];
		if ( ! $inspection->complete || ! hash_equals( $proposal->base->content_hash, $inspection->listings[0]->version->content_hash ) || ! hash_equals( $proposal->base->schema_hash, $inspection->schema_hash ) ) {
			throw new ContractException( 'stale_version', 'Listing or directory schema changed before native application.' );
		}
		$values = array();
		foreach ( $inspection->listings[0]->fields as $fact ) {
			$values[ $fact->field_id ] = $fact->value;
		}
		foreach ( $proposal->changes as $change ) {
			$values[ $change->field_id ] = 'clear' === $change->action ? null : $change->value;
		}
		$posted = $this->hydrate( $proposal->listing_id, $proposal->directory_id, $native['native_fields'], $values );
		$this->validate_native( $proposal->directory_id, $native['native_fields'], $posted );
		return array(
			'native'     => $native,
			'inspection' => $inspection,
			'posted'     => $posted,
			'values'     => $values,
		);
	}

	/**
	 * Load an exact approved proposal by internal approval identity.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 * @return object Proposal and environment.
	 * @throws ContractException Missing or conflicting artifact.
	 */
	public function proposal_for_plan( EffectPlan $plan ): object {
		$artifacts = $this->db->table( 'artifacts' );
		if ( null === $plan->approval_id ) {
			$rows = $this->db->rows( "SELECT payload_json,content_hash,environment_hash FROM $artifacts WHERE owner_ref=%s AND kind='proposal' AND content_hash=%s ORDER BY id DESC LIMIT 2", array( $plan->actor_ref, $plan->proposal_hash ) );
		} else {
			$approvals = $this->db->table( 'approvals' );
			$rows      = $this->db->rows( "SELECT a.payload_json,a.content_hash,a.environment_hash FROM $approvals p INNER JOIN $artifacts a ON a.public_id=p.artifact_id AND a.version=p.artifact_version WHERE p.id=%d AND p.actor_ref=%s AND p.capability=%s", array( $plan->approval_id, $plan->actor_ref, $plan->capability ) );
		}
		if ( ! $rows || ! hash_equals( $rows[0]['content_hash'], $plan->proposal_hash ) ) {
			throw new ContractException( 'approval_mismatch', 'Approved listing proposal is unavailable.' );
		}
		foreach ( $rows as $row ) {
			if ( ! hash_equals( $row['payload_json'], $rows[0]['payload_json'] ) || ! hash_equals( $row['environment_hash'], $rows[0]['environment_hash'] ) ) {
				throw new ContractException( 'approval_mismatch', 'Proposal hash resolves to conflicting artifacts.' );
			}
		}
		return (object) array(
			'proposal'         => json_decode( $rows[0]['payload_json'] ),
			'environment_hash' => $rows[0]['environment_hash'],
		);
	}

	/**
	 * Validate exact base identity.
	 *
	 * @param object $actual Current native version.
	 * @param object $expected Requested base version.
	 * @param int    $listing_id Exact listing ID.
	 * @throws ContractException Base is stale or retargeted.
	 */
	private function assert_base( object $actual, object $expected, int $listing_id ): void {
		if ( 'listing' !== $expected->target->kind || (int) $expected->target->id !== $listing_id || $actual->modified_gmt !== $expected->modified_gmt || ! hash_equals( $actual->content_hash, $expected->content_hash ) || ! hash_equals( $actual->schema_hash, $expected->schema_hash ) ) {
			throw new ContractException( 'stale_version', 'Listing base version has changed.' );
		}
	}

	/**
	 * Validate, canonicalize and order reviewed changes.
	 *
	 * @param object[] $changes Requested changes.
	 * @param array    $descriptors Eligible field descriptors.
	 * @param object   $directory Current directory description.
	 * @param array    $values Current values.
	 * @return object[] Canonical changes.
	 * @throws ContractException Change is duplicate, denied or invalid.
	 */
	private function changes( array $changes, array $descriptors, object $directory, array $values ): array {
		$result = array();
		$seen   = array();
		foreach ( $changes as $change ) {
			if ( isset( $seen[ $change->field_id ] ) || ! isset( $descriptors[ $change->field_id ] ) ) {
				throw new ContractException( 'invalid_input', 'Proposal contains an unknown or duplicate field.' );
			}
			$descriptor = $descriptors[ $change->field_id ];
			if ( ! $descriptor->editable || $descriptor->admin_only || 'images' === $change->field_id ) {
				throw new ContractException( 'unauthorized', 'Proposal contains a field unavailable to this workflow.' );
			}
			if ( 'clear' === $change->action && $descriptor->required ) {
				throw new ContractException( 'native_validation_failed', 'A required field cannot be cleared.' );
			}
			if ( 'set' === $change->action ) {
				$this->validate_value( $descriptor, $change->value, $directory );
			}
			$seen[ $change->field_id ] = true;
			$result[]                  = (object) array(
				'field_id' => $change->field_id,
				'action'   => $change->action,
				'value'    => 'clear' === $change->action ? null : $change->value,
			);
		}
		usort( $result, static fn( object $left, object $right ): int => strcmp( $left->field_id, $right->field_id ) );
		return $result;
	}

	/**
	 * Check field type, static choice and taxonomy membership.
	 *
	 * @param object $descriptor Current field descriptor.
	 * @param mixed  $value Proposed value.
	 * @param object $directory Current directory description.
	 * @throws ContractException Proposed value is invalid.
	 */
	private function validate_value( object $descriptor, $value, object $directory ): void {
		if ( 'number' === $descriptor->type && ! is_int( $value ) && ! is_float( $value ) ) {
			throw new ContractException( 'native_validation_failed', 'Numeric field requires a JSON number.' );
		}
		if ( 'email' === $descriptor->type && ( ! is_string( $value ) || ! is_email( $value ) ) ) {
			throw new ContractException( 'native_validation_failed', 'Email field is invalid.' );
		}
		if ( $descriptor->allowed_values ) {
			$allowed = array_map( static fn( object $choice ): string => wp_json_encode( $choice->value ), $descriptor->allowed_values );
			if ( ! in_array( wp_json_encode( $value ), $allowed, true ) ) {
				throw new ContractException( 'native_validation_failed', 'Field value is not an allowed choice.' );
			}
		}
		if ( in_array( $descriptor->field_id, array( 'categories', 'locations', 'tags' ), true ) ) {
			if ( ! is_array( $value ) || count( $value ) > 100 || count( array_filter( $value, 'is_int' ) ) !== count( $value ) ) {
				throw new ContractException( 'native_validation_failed', 'Taxonomy field requires bounded integer term IDs.' );
			}
			$allowed = array();
			foreach ( $directory->taxonomies as $taxonomy ) {
				if ( $taxonomy->field_id === $descriptor->field_id && ! $taxonomy->truncated ) {
					$allowed = array_map( static fn( object $term ): int => $term->id, $taxonomy->terms );
				}
			}
			if ( array_diff( $value, $allowed ) ) {
				throw new ContractException( 'native_validation_failed', 'Taxonomy value is unavailable in this directory.' );
			}
		}
	}

	/**
	 * Build the complete native submission payload, preserving omitted state.
	 *
	 * @param int   $listing_id Exact listing ID.
	 * @param int   $directory_id Exact directory ID.
	 * @param array $native_fields Eligible native field map.
	 * @param array $values Complete semantic values.
	 */
	private function hydrate( int $listing_id, int $directory_id, array $native_fields, array $values ): array {
		$posted = array(
			'listing_id'     => $listing_id,
			'directory_type' => $directory_id,
			'directory_id'   => $directory_id,
			'tax_input'      => array(),
			't_c_check'      => get_post_meta( $listing_id, '_t_c_check', true ),
			'privacy_policy' => get_post_meta( $listing_id, '_privacy_policy', true ),
		);
		foreach ( $native_fields as $field_id => $field ) {
			$key   = (string) $field['field_key'];
			$value = $values[ $field_id ] ?? null;
			switch ( (string) $field['widget_name'] ) {
				case 'category':
					$posted['tax_input'][ ATBDP_CATEGORY ] = array_map( static fn( $term ): int => is_object( $term ) ? (int) $term->id : (int) $term, is_array( $value ) ? $value : array() );
					break;
				case 'location':
					$posted['tax_input'][ ATBDP_LOCATION ] = array_map( static fn( $term ): int => is_object( $term ) ? (int) $term->id : (int) $term, is_array( $value ) ? $value : array() );
					break;
				case 'tag':
					$posted['tax_input'][ ATBDP_TAGS ] = array_map( static fn( $term ): int => is_object( $term ) ? (int) $term->id : (int) $term, is_array( $value ) ? $value : array() );
					break;
				case 'pricing':
					$posted['atbd_listing_pricing'] = $value->type ?? '';
					$posted['price']                = $value->price ?? '';
					$posted['price_range']          = $value->range ?? '';
					break;
				case 'map':
					$posted['hide_map']   = $value->hide_map ?? false;
					$posted['manual_lat'] = $value->latitude ?? '';
					$posted['manual_lng'] = $value->longitude ?? '';
					break;
				case 'image_upload':
					$old            = array_filter( array_merge( array( (int) ( $value->preview_id ?? 0 ) ), isset( $value->gallery_ids ) && is_array( $value->gallery_ids ) ? $value->gallery_ids : array() ) );
					$posted[ $key ] = array_values( array_unique( array_map( 'intval', $old ) ) );
					break;
				default:
					$posted[ $key ] = $value;
			}
		}
		return $posted;
	}

	/**
	 * Run Directorist's field and conditional validation without saving.
	 *
	 * @param int   $directory_id Exact directory ID.
	 * @param array $native_fields Eligible native field map.
	 * @param array $posted Complete native payload.
	 * @throws ContractException Native validation failed.
	 */
	private function validate_native( int $directory_id, array $native_fields, array $posted ): void {
		foreach ( $native_fields as $field ) {
			$native = directorist_get_field( $field );
			if ( $native->is_admin_only() || ! \Directorist\Fields\Conditional_Logic::should_show_field( $field, $posted ) ) {
				continue;
			}
			if ( $native->is_required() && $native->is_value_empty( $posted ) ) {
				throw new ContractException( 'native_validation_failed', 'A required native field is empty.' );
			}
			if ( ! $native->is_value_empty( $posted ) ) {
				$native->validate( $posted );
				if ( $native->has_error() ) {
					throw new ContractException( 'native_validation_failed', 'A native field rejected the proposed value.' );
				}
			}
		}
		if ( directorist_should_check_privacy_policy( $directory_id ) && empty( $posted['privacy_policy'] ) || directorist_should_check_terms_and_condition( $directory_id ) && empty( $posted['t_c_check'] ) ) {
			throw new ContractException( 'native_validation_failed', 'Required directory acceptance is unavailable.' );
		}
	}

	/**
	 * Resolve the context-bound internal run key.
	 *
	 * @param Context $context Bound workflow context.
	 * @throws ContractException Bound run is unavailable.
	 */
	private function run_internal_id( Context $context ): int {
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$id            = $this->db->scalar( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.public_id=%s", array( $context->run_id(), $context->actor()->owner()->reference(), $context->conversation_id() ) );
		if ( null === $id ) {
			throw new ContractException( 'unauthorized', 'Bound proposal run is unavailable.' );
		}
		return (int) $id;
	}
}
