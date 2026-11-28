<?php
/**
 * Reviewed native new-listing submission with uncertain-write reconciliation.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Domain;

use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Validation\ContractException;

/** Native post, meta marker and taxonomy writes share one WordPress DB transaction. */
final class ListingCreationHandler implements EffectHandler {
	/**
	 * Proposals.
	 *
	 * @var ListingProposalService
	 */
	private ListingProposalService $proposals;
	/**
	 * Inspector.
	 *
	 * @var DirectoristInspector
	 */
	private DirectoristInspector $inspector;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param ListingProposalService $proposals Reviewed input.
	 * @param DirectoristInspector   $inspector Reviewed input.
	 */
	public function __construct( ListingProposalService $proposals, DirectoristInspector $inspector ) {
		$this->proposals = $proposals;
		$this->inspector = $inspector;
	}

	/**
	 * Check whether the reviewed effect remains authorized.
	 *
	 * @param EffectPlan $plan Reviewed input.
	 * @param Context    $context Reviewed input.
	 */
	public function permitted( EffectPlan $plan, Context $context ): bool {
		if ( 1 !== count( $plan->targets ) || ! preg_match( '/^listingcreate:([1-9][0-9]*):([1-9][0-9]*)$/D', $plan->targets[0]->key, $match ) || $context->actor()->is_guest() || $context->actor()->user_id() !== (int) $match[1] || $context->actor()->owner()->reference() !== $plan->actor_ref ) {
			return false;
		}
		try {
			$eligibility = $this->inspector->creation_eligibility( $context, (int) $match[2] );
			return hash_equals( $plan->targets[0]->expected_version, $eligibility->version );
		} catch ( ContractException $error ) {
			return false;
		}
	}

	/**
	 * Return the current native environment hash.
	 *
	 * @param EffectPlan $plan Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When reviewed native state is invalid.
	 */
	public function environment_hash( EffectPlan $plan ): string {
		$proposal = $this->proposals->proposal_for_plan( $plan )->proposal;
		if ( 'listing_creation' !== ( $proposal->kind ?? null ) ) {
			throw new ContractException( 'approval_mismatch', 'Approved creation artifact is invalid.' );
		}
		return $this->inspector->creation_context( $this->context( $plan ), (int) $proposal->directory_id )['environment_hash'];
	}

	/**
	 * Commit the reviewed native effect.
	 *
	 * @param EffectPlan   $plan Reviewed input.
	 * @param EffectTarget $target Reviewed input.
	 * @param int          $fence Reviewed input.
	 */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$proposal = $this->proposals->proposal_for_plan( $plan )->proposal;
		$context  = $this->context( $plan );
		if ( 'listing_creation' !== ( $proposal->kind ?? null ) || 'listingcreate:' . $context->actor()->user_id() . ':' . $proposal->directory_id !== $target->key || ! hash_equals( $target->effect_hash, $plan->proposal_hash ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'approval_mismatch' );
		}
		$existing = $this->marked_listing( $plan, $target );
		if ( $existing ) {
			return $this->reconcile( $plan, $target, $fence );
		}
		try {
			$prepared = $this->proposals->hydrate_creation( $context, $proposal );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_transaction_unavailable' );
		}
		$stamp = static function ( array $meta ) use ( $plan, $target, $fence ): array {
			$meta['_directorist_ai_operation']   = $plan->operation_id;
			$meta['_directorist_ai_effect_hash'] = $target->effect_hash;
			$meta['_directorist_ai_fence']       = $fence;
			return $meta;
		};
		add_filter( 'atbdp_listing_meta_user_submission', $stamp, PHP_INT_MAX );
		add_filter( 'atbdp_ultimate_listing_meta_user_submission', $stamp, PHP_INT_MAX );
		$committed     = false;
		$listing_id    = 0;
		$previous_user = get_current_user_id();
		wp_set_current_user( $context->actor()->user_id() );
		try {
			$result = \Directorist\AddListingForm\SubmissionController::submit( $prepared['posted'], 'api' );
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'native_validation_failed' );
			}
			$listing_id = (int) ( $result['id'] ?? 0 );
			$post       = $listing_id ? get_post( $listing_id ) : null;
			if ( ! $post instanceof \WP_Post || ATBDP_POST_TYPE !== $post->post_type || (int) $post->post_author !== $context->actor()->user_id() || (int) directorist_get_listing_directory( $listing_id ) !== (int) $proposal->directory_id || get_post_meta( $listing_id, '_directorist_ai_operation', true ) !== $plan->operation_id || ! hash_equals( (string) get_post_meta( $listing_id, '_directorist_ai_effect_hash', true ), $target->effect_hash ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_creation_marker_unavailable' );
			}
			$inspection = $this->inspector->inspect( $context, (int) $proposal->directory_id, array( $listing_id ) );
			if ( ! $this->matches_review( $inspection, $proposal ) || $post->post_status !== $proposal->predicted_status ) {
				$wpdb->query( 'ROLLBACK' );
				return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_creation_mismatch' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_commit_uncertain' );
			}
			$committed = true;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_creation_uncertain' );
		} finally {
			wp_set_current_user( $previous_user );
			remove_filter( 'atbdp_listing_meta_user_submission', $stamp, PHP_INT_MAX );
			remove_filter( 'atbdp_ultimate_listing_meta_user_submission', $stamp, PHP_INT_MAX );
			if ( ! $committed && $listing_id > 0 ) {
				clean_post_cache( $listing_id );
			}
		}
		return $this->reconcile( $plan, $target, $fence );
	}

	/**
	 * Read back the reviewed native effect.
	 *
	 * @param EffectPlan   $plan Reviewed input.
	 * @param EffectTarget $target Reviewed input.
	 * @param int          $fence Reviewed input.
	 */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$proposal   = $this->proposals->proposal_for_plan( $plan )->proposal;
		$listing_id = $this->marked_listing( $plan, $target );
		if ( ! $listing_id ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'creation_marker_missing_no_retry' );
		}
		$post    = get_post( $listing_id );
		$context = $this->context( $plan );
		if ( ! $post instanceof \WP_Post || (int) $post->post_author !== $context->actor()->user_id() || (int) directorist_get_listing_directory( $listing_id ) !== (int) $proposal->directory_id ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'created_listing_changed' );
		}
		try {
			$inspection = $this->inspector->inspect( $context, (int) $proposal->directory_id, array( $listing_id ) );
		} catch ( \Throwable $error ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'created_listing_unavailable' );
		}
		if ( ! $this->matches_review( $inspection, $proposal ) || $post->post_status !== $proposal->predicted_status ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'created_listing_changed' );
		}
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			$inspection->listings[0]->version->content_hash,
			(object) array(
				'listing_id' => $listing_id,
				'status'     => $post->post_status,
				'diff'       => $proposal->diff,
				'fence'      => $fence,
				'reconciled' => true
			),
			array(
				array(
					'kind'  => 'directorist_hooks',
					'state' => 'fired_not_reversible'
				)
			)
		);
	}

	/**
	 * Compare reviewed semantic values after Directorist validation and hooks.
	 *
	 * @param object $inspection Reviewed input.
	 * @param object $proposal Reviewed input.
	 */
	private function matches_review( object $inspection, object $proposal ): bool {
		$actual = array();
		foreach ( $inspection->listings[0]->fields as $field ) {
			$actual[ $field->field_id ] = $field->value;
		}
		foreach ( $proposal->changes as $change ) {
			if ( ! array_key_exists( $change->field_id, $actual ) ) {
				return false;
			}
			$expected = $this->comparable_value( $change->field_id, 'clear' === $change->action ? null : $change->value );
			$value    = $this->comparable_value( $change->field_id, $actual[ $change->field_id ] );
			if ( wp_json_encode( $expected ) !== wp_json_encode( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Use the same native semantic normalization as saved-listing reconciliation.
	 *
	 * @param string $field_id Reviewed input.
	 * @param mixed  $value Reviewed input.
	 */
	private function comparable_value( string $field_id, $value ) {
		if ( 'pricing' === $field_id && is_object( $value ) ) {
			$type  = (string) ( $value->type ?? '' );
			$price = $value->price ?? '';
			return array(
				'type'  => $type,
				'price' => 'price' === $type && is_numeric( $price ) ? number_format( (float) $price, 2, '.', '' ) : (string) $price,
				'range' => (string) ( $value->range ?? '' )
			);
		}
		if ( ! in_array( $field_id, array( 'categories', 'locations', 'tags' ), true ) || ! is_array( $value ) ) {
			return $value;
		}
		$ids = array_map( static fn( $term ): int => is_object( $term ) ? (int) $term->id : (int) $term, $value );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Marker is written in native meta_input before the listing-inserted hooks run.
	 *
	 * @param EffectPlan   $plan Reviewed input.
	 * @param EffectTarget $target Reviewed input.
	 */
	private function marked_listing( EffectPlan $plan, EffectTarget $target ): int {
		$ids = get_posts(
			array(
				'post_type'        => ATBDP_POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => 2,
				'fields'           => 'ids',
				'meta_key'         => '_directorist_ai_operation',
				'meta_value'       => $plan->operation_id,
				'suppress_filters' => false
			)
		);
		if ( 1 !== count( $ids ) ) {
			return 0;
		}
		$id = (int) $ids[0];
		return hash_equals( (string) get_post_meta( $id, '_directorist_ai_effect_hash', true ), $target->effect_hash ) ? $id : 0;
	}

	/**
	 * Reconstruct the approved actor context.
	 *
	 * @param EffectPlan $plan Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When reviewed native state is invalid.
	 */
	private function context( EffectPlan $plan ): Context {
		if ( ! str_starts_with( $plan->actor_ref, 'user:' ) ) {
			throw new ContractException( 'unauthorized', 'Creation requires an authenticated WordPress user.' );
		}
		return new Context( Actor::user( (int) substr( $plan->actor_ref, 5 ) ), 'external', 'worker' );
	}
}
