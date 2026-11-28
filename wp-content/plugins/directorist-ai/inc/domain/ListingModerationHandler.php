<?php
/**
 * Per-target Directorist moderation effect adapter.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.VariableComment -- Compact per-target receipts.

use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Validation\ContractException;

/** Each target has its own native permission, version and receipt. */
final class ListingModerationHandler implements EffectHandler {
	private ListingModerationService $moderation;
	private DirectoristInspector $inspector;

	/**
	 * Bind moderation proposal and inspection services.
	 *
	 * @param ListingModerationService $moderation Moderation proposal service.
	 * @param DirectoristInspector     $inspector Native inspector.
	 */
	public function __construct( ListingModerationService $moderation, DirectoristInspector $inspector ) {
		$this->moderation = $moderation;
		$this->inspector = $inspector;
	}

	/**
	 * Recheck every target's native authority.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 * @param Context    $context Current actor context.
	 */
	public function permitted( EffectPlan $plan, Context $context ): bool {
		$post_type = get_post_type_object( ATBDP_POST_TYPE );
		$publish = $post_type && isset( $post_type->cap->publish_posts ) ? $post_type->cap->publish_posts : 'publish_posts';
		if ( $context->actor()->is_guest() || ! $context->actor()->can( $publish ) ) {
			return false;
		}
		foreach ( $plan->targets as $target ) {
			if ( ! preg_match( '/^listing:([1-9][0-9]*)$/D', $target->key, $match ) || ! $context->actor()->can( 'edit_post', (int) $match[1] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Return the aggregate current environment.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 */
	public function environment_hash( EffectPlan $plan ): string {
		$loaded = $this->moderation->proposal( $plan );
		return $this->moderation->environment( $this->context( $plan ), $loaded->proposal );
	}

	/**
	 * Apply one target from the reviewed batch.
	 *
	 * @param EffectPlan   $plan Exact reviewed plan.
	 * @param EffectTarget $target Exact current target.
	 * @param int          $fence Target fence.
	 */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$loaded = $this->moderation->proposal( $plan );
		$proposal = $loaded->proposal;
		$listing_id = (int) substr( $target->key, 8 );
		$reviewed = $this->moderation->target( $proposal, $listing_id );
		$context = $this->context( $plan );
		$current = $this->inspector->inspect( $context, $reviewed->directory_id, array( $listing_id ) );
		$version = $current->listings[0]->version->content_hash;
		if ( ! hash_equals( $target->expected_version, $version ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, $version, (object) array(), array(), 'stale_version' );
		}
		if ( get_post_meta( $listing_id, '_directorist_ai_operation', true ) === $plan->operation_id && hash_equals( (string) get_post_meta( $listing_id, '_directorist_ai_effect_hash', true ), $target->effect_hash ) ) {
			return $this->reconcile( $plan, $target, $fence );
		}
		$guard = function ( int $post_id ) use ( $listing_id, $context, $reviewed, $target ): void {
			if ( $listing_id !== $post_id ) {
				return;
			}
			$now = $this->inspector->inspect( $context, $reviewed->directory_id, array( $listing_id ) );
			if ( ! hash_equals( $target->expected_version, $now->listings[0]->version->content_hash ) ) {
				throw new ContractException( 'stale_version', 'Listing changed at moderation commit.' );
			}
		};
		add_action( 'pre_post_update', $guard, -9999, 1 );
		add_action( 'pre_post_update', $guard, 9999, 1 );
		try {
			$result = wp_update_post( array( 'ID' => $listing_id, 'post_status' => $reviewed->after_status ), true );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		} finally {
			remove_action( 'pre_post_update', $guard, -9999 );
			remove_action( 'pre_post_update', $guard, 9999 );
		}
		if ( is_wp_error( $result ) || get_post_status( $listing_id ) !== $reviewed->after_status ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'native_save_failed' );
		}
		$after = $this->inspector->inspect( $context, $reviewed->directory_id, array( $listing_id ) );
		$after_version = $after->listings[0]->version->content_hash;
		update_post_meta( $listing_id, '_directorist_ai_operation', $plan->operation_id );
		update_post_meta( $listing_id, '_directorist_ai_effect_hash', $target->effect_hash );
		update_post_meta( $listing_id, '_directorist_ai_fence', $fence );
		return new EffectOutcome( 'succeeded', $target->expected_version, $after_version, (object) array( 'listing_id' => $listing_id, 'before_status' => $reviewed->before_status, 'after_status' => $reviewed->after_status, 'fence' => $fence ), array( array( 'kind' => 'wordpress_status_hooks', 'state' => 'fired_not_reversible' ) ) );
	}

	/**
	 * Reconcile one target from native markers.
	 *
	 * @param EffectPlan   $plan Exact reviewed plan.
	 * @param EffectTarget $target Exact current target.
	 * @param int          $fence Target fence.
	 */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$loaded = $this->moderation->proposal( $plan );
		$listing_id = (int) substr( $target->key, 8 );
		$reviewed = $this->moderation->target( $loaded->proposal, $listing_id );
		$current = $this->inspector->inspect( $this->context( $plan ), $reviewed->directory_id, array( $listing_id ) );
		$version = $current->listings[0]->version->content_hash;
		if ( get_post_meta( $listing_id, '_directorist_ai_operation', true ) === $plan->operation_id && hash_equals( (string) get_post_meta( $listing_id, '_directorist_ai_effect_hash', true ), $target->effect_hash ) ) {
			return new EffectOutcome( 'succeeded', $target->expected_version, $version, (object) array( 'listing_id' => $listing_id, 'after_status' => get_post_status( $listing_id ), 'reconciled' => true, 'fence' => $fence ), array( array( 'kind' => 'wordpress_status_hooks', 'state' => 'fired_not_reversible' ) ) );
		}
		return hash_equals( $target->expected_version, $version ) ? new EffectOutcome( 'no_effect', $version, $version, (object) array() ) : new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'changed_without_operation_marker' );
	}

	/**
	 * Build current user context.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 * @throws ContractException Moderation requires a WordPress user.
	 */
	private function context( EffectPlan $plan ): Context {
		if ( ! str_starts_with( $plan->actor_ref, 'user:' ) ) {
			throw new ContractException( 'unauthorized', 'Moderation requires a WordPress user.' );
		}
		return new Context( Actor::user( (int) substr( $plan->actor_ref, 5 ) ), 'external', 'worker' );
	}
}
