<?php
/**
 * Approved draft persistence; approved form suggestions never save an existing listing.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Native listing integration using the shared runtime. */
final class ListingFormEffect implements EffectHandler {

	/**
	 * Bind the site runtime and native listing services.
	 *
	 * @param Database           $db Site-scoped runtime database.
	 * @param ListingFormService $service Native form service.
	 */
	public function __construct( private Database $db, private ListingFormService $service ) {}

	/**
	 * Recheck the actor and exact native effect target.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @param Context    $context Server-resolved actor and workflow authority.
	 * @return bool
	 */
	public function permitted( EffectPlan $plan, Context $context ): bool {
		try {
			if ( $context->actor()->owner()->reference() !== $plan->actor_ref || 1 !== count( $plan->targets ) ) {
				return false;
			}
			$payload = $this->service->proposal_for_plan( $plan );
			$this->service->assert_form( $context, $payload->form );
			$expected = 'create_draft' === $payload->form->mode ? 'listing.draft_create' : ( isset( $payload->form->term_field ) ? 'listing.term_fill' : 'listing.form_fill' );
			if ( isset( $payload->form->term_field ) ) {
				ListingTerms::field( $context, $payload->form );
			}
			$key = 'listingform:' . $context->actor()->user_id() . ':' . $payload->form->directory_id . ':' . $payload->form->listing_id;
			return $expected === $plan->capability && $plan->targets[0]->key === $key;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Return the native schema identity required by approval.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @return string
	 */
	public function environment_hash( EffectPlan $plan ): string {
		return ListingFormService::schema_hash( $this->service->proposal_for_plan( $plan )->form->directory_id );
	}

	/**
	 * Resolve the original actor and conversation from the persisted run.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return Context
	 */
	private function context( EffectPlan $plan ): Context {
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT c.profile,c.public_id,r.public_id AS run_id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.id=%d AND r.owner_ref=%s", array( $plan->run_id, $plan->actor_ref ) );
		if ( ! $rows ) {
			throw new ContractException( 'unauthorized', 'The listing run is unavailable.' );
		}
		return new Context( Actor::user( (int) substr( $plan->actor_ref, 5 ) ), $rows[0]['profile'], 'worker', $rows[0]['public_id'], $rows[0]['run_id'] );
	}

	/**
	 * Execute the reviewed effect without submitting or publishing a listing.
	 *
	 * @param EffectPlan   $plan Exact reviewed effect plan.
	 * @param EffectTarget $target Fenced native effect target.
	 * @param int          $fence Current execution fence.
	 * @throws \RuntimeException When the native workflow cannot be verified.
	 * @return EffectOutcome
	 */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$payload = $this->service->proposal_for_plan( $plan );
		$context = $this->context( $plan );
		$this->service->assert_form( $context, $payload->form );
		if ( 'listing.term_fill' === $plan->capability ) {
			try {
				$diff = ListingTerms::resolve( $context, $payload, $plan, true );
				return new EffectOutcome(
					'succeeded',
					$target->expected_version,
					$target->expected_version,
					(object) array(
						'diff'       => $diff,
						'listing_id' => $payload->form->listing_id,
						'saved'      => false,
					)
				);
			} catch ( \Throwable $error ) {
				return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_terms_readback_uncertain' );
			}
		}
		if ( 'listing.form_fill' === $plan->capability ) {
			return new EffectOutcome(
				'succeeded',
				$target->expected_version,
				$target->expected_version,
				(object) array(
					'diff'       => $payload->diff,
					'listing_id' => $payload->form->listing_id,
					'saved'      => false,
				)
			);
		}
		$existing = $this->marked( $plan );
		if ( $existing ) {
			return $this->reconcile( $plan, $target, $fence );
		}
		$fields     = ListingFormService::fields( $payload->form->directory_id, $context->actor()->can( 'manage_options' ) );
		$taxonomies = array();
		$data       = array(
			'post_type'   => ATBDP_POST_TYPE,
			'post_status' => 'draft',
			'post_author' => $context->actor()->user_id(),
			'post_title'  => __( 'Untitled listing', 'directorist-ai' ),
			'meta_input'  => array(
				'_directorist_ai_form_operation' => $plan->operation_id,
				'_directorist_ai_form_hash'      => $plan->proposal_hash,
			),
		);
		foreach ( get_object_vars( $payload->values ) as $id => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$value    = ListingFormService::sanitize_value( $fields[ $id ], $value, $payload->form->directory_id, $context );
			$key      = $fields[ $id ]['field_key'];
			$taxonomy = ListingFieldValues::taxonomy( $fields[ $id ] );
			if ( null !== $taxonomy ) {
				$taxonomies[ $taxonomy ] = ListingFieldValues::decode( $fields[ $id ], $value );
				continue;
			}
			$native_value = ListingFieldValues::decode( $fields[ $id ], $value );
			if ( 'pricing' === $fields[ $id ]['widget_name'] ) {
				$data['meta_input']['_atbd_listing_pricing'] = $native_value->type;
				$data['meta_input']['_price']                = $native_value->price;
				$data['meta_input']['_price_range']          = $native_value->range;
			} elseif ( 'title' === $id ) {
				$data['post_title'] = $value;
			} elseif ( 'description' === $id ) {
				$data['post_content'] = $value;
			} elseif ( 'excerpt' === $id ) {
				$data['post_excerpt']           = $value;
				$data['meta_input']['_excerpt'] = $value;
			} else {
				$data['meta_input'][ '_' . $key ] = $native_value;
			}
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_transaction_unavailable' );
		}
		$id = 0;
		try {
			$id = wp_insert_post( wp_slash( $data ), true );
			if ( is_wp_error( $id ) || ! $id ) {
				throw new \RuntimeException( 'Native draft insert failed.' );
			}
			directorist_set_listing_directory( $id, $payload->form->directory_id );
			foreach ( $taxonomies as $taxonomy => $terms ) {
				if ( is_wp_error( wp_set_object_terms( $id, $terms, $taxonomy, false ) ) ) {
					throw new \RuntimeException( 'Native taxonomy assignment failed.' ); }
			}
			if ( 'draft' !== get_post_status( $id ) || (int) directorist_get_listing_directory( $id ) !== $payload->form->directory_id || ! hash_equals( $plan->proposal_hash, (string) get_post_meta( $id, '_directorist_ai_form_hash', true ) ) ) {
				throw new \RuntimeException( 'Native draft readback failed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_commit_uncertain' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( is_int( $id ) && $id > 0 ) {
				clean_post_cache( $id );
			}
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_draft_uncertain' );
		}
		return $this->reconcile( $plan, $target, $fence );
	}

	/**
	 * Find the native object carrying this exact operation marker.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @return int
	 */
	private function marked( EffectPlan $plan ): int {
		$ids = get_posts(
			array(
				'post_type'   => ATBDP_POST_TYPE,
				'post_status' => 'any',
				'fields'      => 'ids',
				'numberposts' => 2,
				'meta_key'    => '_directorist_ai_form_operation',
				'meta_value'  => $plan->operation_id,
			)
		);
		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Read back the exact native outcome without repeating a write.
	 *
	 * @param EffectPlan   $plan Exact reviewed effect plan.
	 * @param EffectTarget $target Fenced native effect target.
	 * @param int          $fence Current execution fence.
	 * @return EffectOutcome
	 */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$payload = $this->service->proposal_for_plan( $plan );
		if ( 'listing.term_fill' === $plan->capability ) {
			try {
				$context = $this->context( $plan );
				$this->service->assert_form( $context, $payload->form );
				$diff = ListingTerms::resolve( $context, $payload, $plan, false );
				return new EffectOutcome(
					'succeeded',
					$target->expected_version,
					$target->expected_version,
					(object) array(
						'diff'       => $diff,
						'listing_id' => $payload->form->listing_id,
						'saved'      => false,
					)
				);
			} catch ( \Throwable $error ) {
				return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_terms_readback_uncertain' );
			}
		}
		if ( 'listing.form_fill' === $plan->capability ) {
			$this->service->assert_form( $this->context( $plan ), $payload->form );
			return new EffectOutcome(
				'succeeded',
				$target->expected_version,
				$target->expected_version,
				(object) array(
					'diff'       => $payload->diff,
					'listing_id' => $payload->form->listing_id,
					'saved'      => false,
				)
			);
		}
		$id   = $this->marked( $plan );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'draft' !== $post->post_status || (int) substr( $plan->actor_ref, 5 ) !== (int) $post->post_author || (int) directorist_get_listing_directory( $id ) !== $payload->form->directory_id || ! hash_equals( $plan->proposal_hash, (string) get_post_meta( $id, '_directorist_ai_form_hash', true ) ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_draft_marker_unavailable' );
		}
		$fields = ListingFormService::fields( $payload->form->directory_id, $this->context( $plan )->actor()->can( 'manage_options' ) );
		foreach ( get_object_vars( $payload->values ) as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$expected = ListingFormService::sanitize_value( $fields[ $key ], $value, $payload->form->directory_id, $this->context( $plan ) );
			$actual   = 'title' === $key ? $post->post_title : ( 'description' === $key ? $post->post_content : ( 'excerpt' === $key ? $post->post_excerpt : get_post_meta( $id, '_' . $fields[ $key ]['field_key'], true ) ) );
			$taxonomy = ListingFieldValues::taxonomy( $fields[ $key ] );
			if ( 'pricing' === $fields[ $key ]['widget_name'] ) {
				$actual = ListingPricingValues::read( $id );
			}
			if ( null !== $taxonomy ) {
				$actual = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) ); }
			if ( is_wp_error( $actual ) || ListingFieldValues::wire( $fields[ $key ], $actual ) !== $expected ) {
				return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_draft_readback_mismatch' );
			}
		}
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			ListingFormService::source_hash( $id ),
			(object) array(
				'listing_id'     => $id,
				'status'         => 'draft',
				'missing_fields' => $payload->missing_fields,
			)
		);
	}
}
