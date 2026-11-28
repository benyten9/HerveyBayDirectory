<?php
/**
 * Directorist native listing write and reconciliation adapter.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Validation\ContractException;

/** Full native hydration prevents omission from clearing untouched state. */
final class ListingEffectHandler implements EffectHandler {
	/**
	 * Proposal and hydration service.
	 *
	 * @var ListingProposalService
	 */
	private ListingProposalService $proposals;
	/**
	 * Native inspection adapter.
	 *
	 * @var DirectoristInspector
	 */
	private DirectoristInspector $inspector;

	/**
	 * Bind proposal and native inspection services.
	 *
	 * @param ListingProposalService $proposals Proposal service.
	 * @param DirectoristInspector   $inspector Native inspector.
	 */
	public function __construct( ListingProposalService $proposals, DirectoristInspector $inspector ) {
		$this->proposals = $proposals;
		$this->inspector = $inspector;
	}

	/**
	 * Recheck exact target authority.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 * @param Context    $context Current actor context.
	 */
	public function permitted( EffectPlan $plan, Context $context ): bool {
		if ( 1 !== count( $plan->targets ) || ! preg_match( '/^listing:([1-9][0-9]*)$/D', $plan->targets[0]->key, $match ) ) {
			return false;
		}
		return ! $context->actor()->is_guest() && $context->actor()->owner()->reference() === $plan->actor_ref && $context->actor()->can( 'edit_post', (int) $match[1] );
	}

	/**
	 * Return the current directory dependency identity.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 */
	public function environment_hash( EffectPlan $plan ): string {
		$loaded   = $this->proposals->proposal_for_plan( $plan );
		$proposal = $loaded->proposal;
		$context  = $this->context( $plan );
		$native   = $this->inspector->proposal_context( $context, (int) $proposal->directory_id, (int) $proposal->listing_id );
		return $native['environment_hash'];
	}

	/**
	 * Commit through Directorist's native submission controller.
	 *
	 * @param EffectPlan   $plan Exact reviewed plan.
	 * @param EffectTarget $target Exact listing target.
	 * @param int          $fence Target fence.
	 */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$loaded   = $this->proposals->proposal_for_plan( $plan );
		$proposal = $loaded->proposal;
		if ( 'listing:' . $proposal->listing_id !== $target->key || ! hash_equals( $target->effect_hash, $plan->proposal_hash ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'approval_mismatch' );
		}
		$listing_id = (int) $proposal->listing_id;
		if ( get_post_meta( $listing_id, '_directorist_ai_operation', true ) === $plan->operation_id && hash_equals( (string) get_post_meta( $listing_id, '_directorist_ai_effect_hash', true ), $target->effect_hash ) ) {
			return $this->reconcile( $plan, $target, $fence );
		}
		$context = $this->context( $plan );
		try {
			$prepared = $this->proposals->hydrate_reviewed( $context, $proposal );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		}
		$current = $prepared['inspection']->listings[0]->version->content_hash;
		if ( ! hash_equals( $target->expected_version, $current ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, $current, (object) array(), array(), 'stale_version' );
		}
		$guard = function ( int $post_id ) use ( $listing_id, $proposal, $context, $target ): void {
			if ( $listing_id !== $post_id ) {
				return;
			}
			$now = $this->inspector->inspect( $context, (int) $proposal->directory_id, array( $listing_id ) );
			if ( ! hash_equals( $target->expected_version, $now->listings[0]->version->content_hash ) ) {
				throw new ContractException( 'stale_version', 'Listing changed at the native commit boundary.' );
			}
		};
		add_action( 'pre_post_update', $guard, -9999, 1 );
		add_action( 'pre_post_update', $guard, 9999, 1 );
		$explicit_meta = $this->explicit_scalar_meta( $proposal->changes, $prepared['native']['native_fields'], $prepared['values'] );
		$preserve      = static fn( array $meta ): array => array_merge( $meta, $explicit_meta );
		$restore       = static function ( int $post_id ) use ( $listing_id, $explicit_meta ): void {
			if ( $post_id !== $listing_id ) {
				return;
			}
			foreach ( $explicit_meta as $meta_key => $value ) {
				update_post_meta( $listing_id, $meta_key, $value );
			}
		};
		add_filter( 'atbdp_listing_meta_user_submission', $preserve, PHP_INT_MAX, 1 );
		add_filter( 'atbdp_ultimate_listing_meta_user_submission', $preserve, PHP_INT_MAX, 1 );
		add_action( 'atbdp_listing_updated', $restore, -9999, 1 );
		$previous_user = get_current_user_id();
		wp_set_current_user( $context->actor()->user_id() );
		try {
			$result = \Directorist\AddListingForm\SubmissionController::submit( $prepared['posted'], 'api' );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		} finally {
			wp_set_current_user( $previous_user );
			remove_action( 'pre_post_update', $guard, -9999 );
			remove_action( 'pre_post_update', $guard, 9999 );
			remove_filter( 'atbdp_listing_meta_user_submission', $preserve, PHP_INT_MAX );
			remove_filter( 'atbdp_ultimate_listing_meta_user_submission', $preserve, PHP_INT_MAX );
			remove_action( 'atbdp_listing_updated', $restore, -9999 );
		}
		if ( is_wp_error( $result ) || (int) ( $result['id'] ?? 0 ) !== $listing_id ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'native_save_failed' );
		}
		$after    = $this->inspector->inspect( $context, (int) $proposal->directory_id, array( $listing_id ) );
		$mismatch = $this->reviewed_mismatch( $after, $prepared['values'] );
		if ( null !== $mismatch ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_save_mismatch:' . $mismatch );
		}
		$after_version = $after->listings[0]->version->content_hash;
		update_post_meta( $listing_id, '_directorist_ai_operation', $plan->operation_id );
		update_post_meta( $listing_id, '_directorist_ai_effect_hash', $target->effect_hash );
		update_post_meta( $listing_id, '_directorist_ai_fence', $fence );
		do_action( 'directorist_ai_pro_listing_applied', $listing_id, $plan->operation_id, $fence );
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			$after_version,
			(object) array(
				'listing_id' => $listing_id,
				'status'     => $after->listings[0]->status,
				'diff'       => $proposal->diff,
				'fence'      => $fence,
			),
			array(
				array(
					'kind'  => 'directorist_hooks',
					'state' => 'fired_not_reversible',
				),
			)
		);
	}

	/**
	 * Reconcile only from native markers and current facts.
	 *
	 * @param EffectPlan   $plan Exact reviewed plan.
	 * @param EffectTarget $target Exact listing target.
	 * @param int          $fence Target fence.
	 */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$loaded     = $this->proposals->proposal_for_plan( $plan );
		$proposal   = $loaded->proposal;
		$listing_id = (int) $proposal->listing_id;
		$context    = $this->context( $plan );
		$current    = $this->inspector->inspect( $context, (int) $proposal->directory_id, array( $listing_id ) );
		$version    = $current->listings[0]->version->content_hash;
		if ( get_post_meta( $listing_id, '_directorist_ai_operation', true ) === $plan->operation_id && hash_equals( (string) get_post_meta( $listing_id, '_directorist_ai_effect_hash', true ), $target->effect_hash ) ) {
			return new EffectOutcome(
				'succeeded',
				$target->expected_version,
				$version,
				(object) array(
					'listing_id' => $listing_id,
					'status'     => $current->listings[0]->status,
					'reconciled' => true,
					'fence'      => $fence,
				),
				array(
					array(
						'kind'  => 'directorist_hooks',
						'state' => 'fired_not_reversible',
					),
				)
			);
		}
		if ( hash_equals( $target->expected_version, $version ) ) {
			return new EffectOutcome( 'no_effect', $version, $version, (object) array() );
		}
		return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'changed_without_operation_marker' );
	}

	/**
	 * Build a fresh current-user context for native callbacks/reconciliation.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 * @throws ContractException Listing effects require a WordPress user.
	 */
	private function context( EffectPlan $plan ): Context {
		if ( ! str_starts_with( $plan->actor_ref, 'user:' ) ) {
			throw new ContractException( 'unauthorized', 'Listing effects require an authenticated WordPress user.' );
		}
		return new Context( Actor::user( (int) substr( $plan->actor_ref, 5 ) ), 'external', 'worker' );
	}

	/**
	 * Return the first field whose semantic value differs.
	 *
	 * @param object $inspection Current native inspection.
	 * @param array  $expected Expected complete field values.
	 */
	private function reviewed_mismatch( object $inspection, array $expected ): ?string {
		$actual = array();
		foreach ( $inspection->listings[0]->fields as $field ) {
			$actual[ $field->field_id ] = $field->value;
		}
		foreach ( $expected as $field_id => $value ) {
			if ( ! array_key_exists( $field_id, $actual ) || wp_json_encode( $this->comparable_value( $field_id, $actual[ $field_id ] ) ) !== wp_json_encode( $this->comparable_value( $field_id, $value ) ) ) {
				return $field_id;
			}
		}
		return null;
	}

	/**
	 * Normalize semantically equivalent taxonomy representations.
	 *
	 * @param string $field_id Stable logical field ID.
	 * @param mixed  $value Native or proposed value.
	 * @return mixed Comparable value.
	 */
	private function comparable_value( string $field_id, $value ) {
		if ( 'pricing' === $field_id && is_object( $value ) ) {
			$type  = (string) ( $value->type ?? '' );
			$price = $value->price ?? '';
			return array(
				'type'  => $type,
				'price' => 'price' === $type && is_numeric( $price ) ? number_format( (float) $price, 2, '.', '' ) : (string) $price,
				'range' => (string) ( $value->range ?? '' ),
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
	 * Preserve reviewed zero/false custom scalars across Directorist meta filters.
	 *
	 * @param object[] $changes Reviewed changes.
	 * @param array    $native_fields Eligible native field map.
	 * @param array    $values Complete semantic values.
	 */
	private function explicit_scalar_meta( array $changes, array $native_fields, array $values ): array {
		$result  = array();
		$special = array( 'title', 'description', 'excerpt', 'category', 'location', 'tag', 'pricing', 'map', 'image_upload' );
		foreach ( $changes as $change ) {
			if ( 'set' !== $change->action || ! array_key_exists( $change->field_id, $native_fields ) || ! in_array( $values[ $change->field_id ], array( 0, 0.0, false ), true ) || in_array( $native_fields[ $change->field_id ]['widget_name'], $special, true ) ) {
				continue;
			}
			$result[ '_' . sanitize_key( (string) $native_fields[ $change->field_id ]['field_key'] ) ] = $values[ $change->field_id ];
		}
		return $result;
	}
}
