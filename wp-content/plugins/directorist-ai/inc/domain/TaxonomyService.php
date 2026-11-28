<?php
/**
 * Narrow Directorist category/location proposal service.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.ControlStructures.ControlSignature,Generic.Formatting.DisallowMultipleStatements -- Compact immutable term reviews.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Server chooses taxonomy and native functions from a closed enum. */
final class TaxonomyService {
	private Database $db;
	private ApprovalService $approvals;

	/** Bind storage and approval lifecycle. */
	public function __construct( Database $db, ApprovalService $approvals ) {
		$this->db = $db;
		$this->approvals = $approvals;
	}

	/** Check workflow identity and native taxonomy authority. */
	public function permitted( Context $context, object $input ): bool {
		$taxonomy = $this->taxonomy( $input->taxonomy );
		$object = get_taxonomy( $taxonomy );
		return null !== $context->conversation_id() && null !== $context->run_id() && directorist_is_directory( $input->directory_id ) && $object && $context->actor()->can( $object->cap->manage_terms );
	}

	/** Create one exact taxonomy review. */
	public function propose( Context $context, object $input ): object {
		if ( ! $this->permitted( $context, $input ) ) {
			throw new ContractException( 'unauthorized', 'Taxonomy proposal is unavailable.' );
		}
		$taxonomy = $this->taxonomy( $input->taxonomy );
		$before = null;
		$target_id = null;
		if ( 'create' === $input->action ) {
			if ( null !== $input->term_id || ! is_string( $input->name ) || '' === trim( $input->name ) ) {
				throw new ContractException( 'invalid_input', 'Create requires a name and no term ID.' );
			}
			$this->assert_parent( $taxonomy, $input->directory_id, (int) ( $input->parent_id ?? 0 ), 0 );
			if ( term_exists( $input->name, $taxonomy, (int) ( $input->parent_id ?? 0 ) ) ) {
				throw new ContractException( 'relationship_invalid', 'A matching term already exists.' );
			}
			$target_id = 'new-' . substr( hash( 'sha256', strtolower( trim( $input->name ) ) . '|' . (int) ( $input->parent_id ?? 0 ) ), 0, 24 );
		} else {
			if ( ! is_int( $input->term_id ) || $input->term_id < 1 ) {
				throw new ContractException( 'invalid_input', 'Existing taxonomy action requires a term ID.' );
			}
			$term = $this->owned_term( $taxonomy, $input->directory_id, $input->term_id );
			$before = $this->snapshot( $term );
			if ( ! is_object( $input->base ) || ! hash_equals( $before->content_hash, $input->base->content_hash ) ) {
				throw new ContractException( 'stale_version', 'Term changed before proposal.' );
			}
			$target_id = (string) $term->term_id;
			if ( 'rename' === $input->action && ( ! is_string( $input->name ) || '' === trim( $input->name ) || null !== $input->parent_id ) ) {
				throw new ContractException( 'invalid_input', 'Rename requires only a name.' );
			}
			if ( 'reparent' === $input->action ) {
				if ( null !== $input->name || ! is_int( $input->parent_id ) || $input->parent_id < 0 ) {
					throw new ContractException( 'invalid_input', 'Reparent requires only a parent ID.' );
				}
				$this->assert_parent( $taxonomy, $input->directory_id, $input->parent_id, $term->term_id );
			}
		}
		$after = (object) array( 'name' => 'rename' === $input->action || 'create' === $input->action ? trim( (string) $input->name ) : $before->name, 'parent_id' => 'reparent' === $input->action || 'create' === $input->action ? (int) ( $input->parent_id ?? 0 ) : $before->parent_id );
		$payload = (object) array( 'kind' => 'taxonomy_change', 'directory_id' => $input->directory_id, 'taxonomy' => $input->taxonomy, 'native_taxonomy' => $taxonomy, 'action' => $input->action, 'term_id' => $input->term_id, 'target_id' => $target_id, 'before' => $before, 'after' => $after );
		$environment = $this->environment( $payload );
		$identity = hash( 'sha256', $context->run_id() . "\0" . wp_json_encode( $payload ) );
		$artifact = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), 'taxonomy-proposal-' . substr( $identity, 0, 32 ), 'proposal', $payload, $environment );
		$operation_id = 'taxonomy-apply-' . substr( $identity, 0, 32 );
		$expected = $before ? $before->content_hash : 'absent:' . substr( $identity, 0, 32 );
		$plan = new EffectPlan( $operation_id, $context->actor()->owner()->reference(), 'taxonomy.apply', '1', $artifact['content_hash'], array( new EffectTarget( 'term:' . $input->taxonomy . ':' . $target_id, $expected, $artifact['content_hash'] ) ), $this->run_id( $context ) );
		$review = $this->approvals->create( $context->actor(), new ApprovalSpec( $plan, $artifact['public_id'], 1, $artifact['content_hash'], $environment, (object) array( 'kind' => 'taxonomy_change', 'directory_id' => $input->directory_id, 'taxonomy' => $input->taxonomy, 'action' => $input->action, 'term_id' => $input->term_id, 'before' => $before, 'after' => $after ), (object) array( 'max_targets' => 1 ) ) );
		return (object) array( 'artifact' => (object) array( 'id' => $artifact['public_id'], 'version' => 1, 'hash' => $artifact['content_hash'] ), 'operation_id' => $operation_id, 'review' => (object) array( 'id' => $review['id'], 'state' => $review['state'], 'review_hash' => $review['review_hash'], 'expires_at' => $review['expires_at'] ), 'before' => $before, 'after' => $after );
	}

	/** Load exact taxonomy proposal. */
	public function proposal( EffectPlan $plan ): object {
		$table = $this->db->table( 'artifacts' );
		$rows = $this->db->rows( "SELECT payload_json,environment_hash FROM $table WHERE owner_ref=%s AND content_hash=%s AND kind='proposal' ORDER BY id DESC LIMIT 1", array( $plan->actor_ref, $plan->proposal_hash ) );
		if ( ! $rows ) {
			throw new ContractException( 'approval_mismatch', 'Taxonomy proposal is unavailable.' );
		}
		return (object) array( 'proposal' => json_decode( $rows[0]['payload_json'] ), 'environment_hash' => $rows[0]['environment_hash'] );
	}

	/** Stable term version with all directory consumers. */
	public function snapshot( \WP_Term $term ): object {
		$directories = array_values( array_map( 'intval', directorist_get_term_directory( $term->term_id ) ) );
		sort( $directories, SORT_NUMERIC );
		$data = (object) array( 'term_id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent_id' => (int) $term->parent, 'directory_ids' => $directories );
		$data->content_hash = hash( 'sha256', wp_json_encode( $data ) );
		return $data;
	}

	/** Current environment identity. */
	public function environment( object $proposal ): string {
		return hash( 'sha256', wp_json_encode( array( get_bloginfo( 'version' ), ATBDP_VERSION, $proposal->native_taxonomy, $proposal->directory_id ) ) );
	}

	/** Resolve a directory-owned term. */
	public function owned_term( string $taxonomy, int $directory_id, int $term_id ): \WP_Term {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || ! in_array( $directory_id, directorist_get_term_directory( $term_id ), true ) ) {
			throw new ContractException( 'unauthorized', 'Term is unavailable in this directory.' );
		}
		return $term;
	}

	/** Map the closed logical taxonomy enum. */
	public function taxonomy( string $taxonomy ): string {
		if ( 'category' === $taxonomy ) { return ATBDP_CATEGORY; }
		if ( 'location' === $taxonomy ) { return ATBDP_LOCATION; }
		throw new ContractException( 'invalid_input', 'Unsupported taxonomy.' );
	}

	/** Reject missing, foreign or cyclic parents. */
	private function assert_parent( string $taxonomy, int $directory_id, int $parent_id, int $term_id ): void {
		if ( 0 === $parent_id ) { return; }
		$parent = $this->owned_term( $taxonomy, $directory_id, $parent_id );
		$cursor = $parent;
		while ( $cursor instanceof \WP_Term ) {
			if ( (int) $cursor->term_id === $term_id ) {
				throw new ContractException( 'relationship_invalid', 'Term parent would create a cycle.' );
			}
			if ( ! $cursor->parent ) { break; }
			$cursor = get_term( $cursor->parent, $taxonomy );
		}
	}

	/** Resolve context-bound internal run. */
	private function run_id( Context $context ): int {
		$table = $this->db->table( 'runs' );
		$id = $this->db->scalar( "SELECT id FROM $table WHERE public_id=%s AND owner_ref=%s", array( $context->run_id(), $context->actor()->owner()->reference() ) );
		if ( null === $id ) { throw new ContractException( 'unauthorized', 'Taxonomy run is unavailable.' ); }
		return (int) $id;
	}
}
