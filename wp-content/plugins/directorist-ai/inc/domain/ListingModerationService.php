<?php
/**
 * Bounded Directorist listing moderation proposal service.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.VariableComment -- Compact immutable review construction.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Moderation never accepts a query that can expand after review. */
final class ListingModerationService {
	/** Runtime storage. @var Database */
	private Database $db;
	/** Native inspector. @var DirectoristInspector */
	private DirectoristInspector $inspector;
	/** Approval lifecycle. @var ApprovalService */
	private ApprovalService $approvals;

	/**
	 * Bind runtime storage, native inspection and approval.
	 *
	 * @param Database             $db Runtime storage.
	 * @param DirectoristInspector $inspector Native inspector.
	 * @param ApprovalService      $approvals Approval lifecycle.
	 */
	public function __construct( Database $db, DirectoristInspector $inspector, ApprovalService $approvals ) {
		$this->db = $db;
		$this->inspector = $inspector;
		$this->approvals = $approvals;
	}

	/**
	 * Check manager authority for every explicit target.
	 *
	 * @param Context $context Current workflow context.
	 * @param object  $input Strict moderation input.
	 */
	public function permitted( Context $context, object $input ): bool {
		$post_type = get_post_type_object( ATBDP_POST_TYPE );
		$publish = $post_type && isset( $post_type->cap->publish_posts ) ? $post_type->cap->publish_posts : 'publish_posts';
		if ( $context->actor()->is_guest() || null === $context->conversation_id() || null === $context->run_id() || ! $context->actor()->can( $publish ) ) {
			return false;
		}
		foreach ( $input->targets as $target ) {
			if ( ! $this->inspector->can_inspect( $context, $target->directory_id, array( $target->listing_id ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Create an exact multi-target moderation review.
	 *
	 * @param Context $context Current workflow context.
	 * @param object  $input Strict moderation input.
	 * @return object Proposal and review references.
	 * @throws ContractException Target is denied, stale or duplicated.
	 */
	public function propose( Context $context, object $input ): object {
		if ( ! $this->permitted( $context, $input ) ) {
			throw new ContractException( 'unauthorized', 'Listing moderation proposal is unavailable.' );
		}
		$seen = array();
		$targets = array();
		$effects = array();
		$environments = array();
		$after_status = 'publish' === $input->action ? 'publish' : 'draft';
		foreach ( $input->targets as $target ) {
			if ( isset( $seen[ $target->listing_id ] ) ) {
				throw new ContractException( 'invalid_input', 'Moderation target is duplicated.' );
			}
			$native = $this->inspector->proposal_context( $context, $target->directory_id, $target->listing_id );
			$listing = $native['inspection']->listings[0];
			if ( ! $native['inspection']->complete || ! hash_equals( $listing->version->content_hash, $target->base->content_hash ) || ! hash_equals( $listing->version->schema_hash, $target->base->schema_hash ) || $listing->version->modified_gmt !== $target->base->modified_gmt ) {
				throw new ContractException( 'stale_version', 'Moderation target changed before review.' );
			}
			$seen[ $target->listing_id ] = true;
			$targets[] = (object) array( 'listing_id' => $target->listing_id, 'directory_id' => $target->directory_id, 'base' => $target->base, 'before_status' => $listing->status, 'after_status' => $after_status );
			$environments[] = $native['environment_hash'];
		}
		usort( $targets, static fn( object $left, object $right ): int => $left->listing_id <=> $right->listing_id );
		sort( $environments, SORT_STRING );
		$environment_hash = hash( 'sha256', wp_json_encode( array_values( array_unique( $environments ) ) ) );
		$payload = (object) array( 'kind' => 'listing_moderation', 'action' => $input->action, 'targets' => $targets );
		$identity = hash( 'sha256', $context->run_id() . "\0" . wp_json_encode( $payload ) );
		$artifact = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), 'moderation-proposal-' . substr( $identity, 0, 32 ), 'proposal', $payload, $environment_hash );
		foreach ( $targets as $target ) {
			$effects[] = new EffectTarget( 'listing:' . $target->listing_id, $target->base->content_hash, $artifact['content_hash'] );
		}
		$operation_id = 'listing-moderate-' . substr( $identity, 0, 32 );
		$plan = new EffectPlan( $operation_id, $context->actor()->owner()->reference(), 'listing.bulk_apply', '1', $artifact['content_hash'], $effects, $this->run_id( $context ) );
		$review = $this->approvals->create( $context->actor(), new ApprovalSpec( $plan, $artifact['public_id'], 1, $artifact['content_hash'], $environment_hash, (object) array( 'kind' => 'listing_moderation', 'action' => $input->action, 'targets' => $targets ), (object) array( 'max_targets' => 20 ) ) );
		return (object) array( 'artifact' => (object) array( 'id' => $artifact['public_id'], 'version' => 1, 'hash' => $artifact['content_hash'] ), 'operation_id' => $operation_id, 'review' => (object) array( 'id' => $review['id'], 'state' => $review['state'], 'review_hash' => $review['review_hash'], 'expires_at' => $review['expires_at'] ), 'targets' => $targets );
	}

	/**
	 * Load one exact moderation artifact.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 * @return object Proposal and environment.
	 * @throws ContractException Proposal is unavailable.
	 */
	public function proposal( EffectPlan $plan ): object {
		$artifacts = $this->db->table( 'artifacts' );
		$rows = $this->db->rows( "SELECT payload_json,environment_hash FROM $artifacts WHERE owner_ref=%s AND content_hash=%s AND kind='proposal' ORDER BY id DESC LIMIT 2", array( $plan->actor_ref, $plan->proposal_hash ) );
		if ( ! $rows ) {
			throw new ContractException( 'approval_mismatch', 'Moderation proposal is unavailable.' );
		}
		return (object) array( 'proposal' => json_decode( $rows[0]['payload_json'] ), 'environment_hash' => $rows[0]['environment_hash'] );
	}

	/**
	 * Aggregate current directory environment identities for a proposal.
	 *
	 * @param Context $context Current actor context.
	 * @param object  $proposal Moderation proposal.
	 */
	public function environment( Context $context, object $proposal ): string {
		$hashes = array();
		foreach ( $proposal->targets as $target ) {
			$native = $this->inspector->proposal_context( $context, $target->directory_id, $target->listing_id );
			$hashes[] = $native['environment_hash'];
		}
		sort( $hashes, SORT_STRING );
		return hash( 'sha256', wp_json_encode( array_values( array_unique( $hashes ) ) ) );
	}

	/**
	 * Resolve one proposal target.
	 *
	 * @param object $proposal Moderation proposal.
	 * @param int    $listing_id Exact listing ID.
	 * @throws ContractException Target is outside the review.
	 */
	public function target( object $proposal, int $listing_id ): object {
		foreach ( $proposal->targets as $target ) {
			if ( (int) $target->listing_id === $listing_id ) {
				return $target;
			}
		}
		throw new ContractException( 'approval_mismatch', 'Listing is outside the reviewed moderation set.' );
	}

	/**
	 * Resolve context-bound internal run identity.
	 *
	 * @param Context $context Bound workflow context.
	 * @throws ContractException Run is unavailable.
	 */
	private function run_id( Context $context ): int {
		$runs = $this->db->table( 'runs' );
		$id = $this->db->scalar( "SELECT id FROM $runs WHERE public_id=%s AND owner_ref=%s", array( $context->run_id(), $context->actor()->owner()->reference() ) );
		if ( null === $id ) {
			throw new ContractException( 'unauthorized', 'Moderation run is unavailable.' );
		}
		return (int) $id;
	}
}
