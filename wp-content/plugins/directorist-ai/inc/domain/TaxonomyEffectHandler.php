<?php
/**
 * Guarded Directorist taxonomy native effect adapter.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionComment,Squiz.ControlStructures.ControlSignature,Generic.Formatting.DisallowMultipleStatements,Squiz.Functions.MultiLineFunctionDeclaration -- Compact native adapter.

use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Validation\ContractException;

/** Stable operation markers make create/update retries inspectable. */
final class TaxonomyEffectHandler implements EffectHandler {
	private TaxonomyService $service;
	public function __construct( TaxonomyService $service ) { $this->service = $service; }
	public function permitted( EffectPlan $plan, Context $context ): bool {
		$loaded = $this->service->proposal( $plan );
		$taxonomy = $this->service->taxonomy( $loaded->proposal->taxonomy );
		$object = get_taxonomy( $taxonomy );
		return ! $context->actor()->is_guest() && $object && $context->actor()->can( $object->cap->manage_terms );
	}
	public function environment_hash( EffectPlan $plan ): string { return $this->service->environment( $this->service->proposal( $plan )->proposal ); }
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$proposal = $this->service->proposal( $plan )->proposal;
		$taxonomy = $this->service->taxonomy( $proposal->taxonomy );
		$term = null;
		if ( 'create' !== $proposal->action ) {
			$term = $this->service->owned_term( $taxonomy, $proposal->directory_id, $proposal->term_id );
			$current = $this->service->snapshot( $term );
			if ( ! hash_equals( $target->expected_version, $current->content_hash ) ) { return new EffectOutcome( 'failed', $target->expected_version, $current->content_hash, (object) array(), array(), 'stale_version' ); }
		} else {
			$existing = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'meta_key' => '_directorist_ai_operation', 'meta_value' => $plan->operation_id, 'number' => 1 ) );
			if ( ! is_wp_error( $existing ) && $existing ) { return $this->reconcile( $plan, $target, $fence ); }
		}
		if ( 'create' === $proposal->action ) {
			$result = wp_insert_term( $proposal->after->name, $taxonomy, array( 'parent' => $proposal->after->parent_id ) );
			if ( is_wp_error( $result ) ) { return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'relationship_invalid' ); }
			$term_id = (int) $result['term_id'];
			if ( 'category' === $proposal->taxonomy ) { directorist_update_category_directory( $term_id, array( $proposal->directory_id ) ); } else { directorist_update_location_directory( $term_id, array( $proposal->directory_id ) ); }
		} else {
			$args = 'rename' === $proposal->action ? array( 'name' => $proposal->after->name ) : array( 'parent' => $proposal->after->parent_id );
			$result = wp_update_term( $proposal->term_id, $taxonomy, $args );
			if ( is_wp_error( $result ) ) { return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'relationship_invalid' ); }
			$term_id = (int) $proposal->term_id;
		}
		update_term_meta( $term_id, '_directorist_ai_operation', $plan->operation_id );
		update_term_meta( $term_id, '_directorist_ai_effect_hash', $target->effect_hash );
		update_term_meta( $term_id, '_directorist_ai_fence', $fence );
		$after = $this->service->snapshot( get_term( $term_id, $taxonomy ) );
		return new EffectOutcome( 'succeeded', $target->expected_version, $after->content_hash, (object) array( 'term_id' => $term_id, 'name' => $after->name, 'parent_id' => $after->parent_id, 'directory_ids' => $after->directory_ids, 'fence' => $fence ), array( array( 'kind' => 'wordpress_term_hooks', 'state' => 'fired_not_reversible' ) ) );
	}
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$proposal = $this->service->proposal( $plan )->proposal;
		$taxonomy = $this->service->taxonomy( $proposal->taxonomy );
		if ( 'create' === $proposal->action ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'meta_key' => '_directorist_ai_operation', 'meta_value' => $plan->operation_id, 'number' => 1 ) );
			$term = ! is_wp_error( $terms ) && $terms ? $terms[0] : null;
		} else { $term = get_term( $proposal->term_id, $taxonomy ); }
		if ( $term instanceof \WP_Term && get_term_meta( $term->term_id, '_directorist_ai_operation', true ) === $plan->operation_id ) {
			$after = $this->service->snapshot( $term );
			return new EffectOutcome( 'succeeded', $target->expected_version, $after->content_hash, (object) array( 'term_id' => (int) $term->term_id, 'reconciled' => true, 'fence' => $fence ), array( array( 'kind' => 'wordpress_term_hooks', 'state' => 'fired_not_reversible' ) ) );
		}
		if ( $term instanceof \WP_Term && 'create' !== $proposal->action ) {
			$current = $this->service->snapshot( $term );
			if ( hash_equals( $target->expected_version, $current->content_hash ) ) { return new EffectOutcome( 'no_effect', $current->content_hash, $current->content_hash, (object) array() ); }
		}
		return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'term_outcome_unavailable' );
	}
}
