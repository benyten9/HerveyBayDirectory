<?php
/**
 * Import accepted media only. Native listing mapping remains an unsaved form operation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;
use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\{Actor, Context};
use DirectoristAIPro\Domain\MediaSource;
use DirectoristAIPro\Effects\{EffectPlan, EffectTarget, EffectOutcome};
use DirectoristAIPro\Storage\Database;
/** Native listing integration using the shared runtime. */
final class ListingMediaEffect implements EffectHandler {
	/**
	 * Bind the site runtime and native listing services.
	 *
	 * @param Database            $db Site-scoped runtime database.
	 * @param ListingFormService  $forms Native form authority and captures.
	 * @param ListingMediaService $media Exact native image scope or service.
	 */
	public function __construct( private Database $db, private ListingFormService $forms, private ListingMediaService $media ) {}
	/**
	 * Resolve the original actor and conversation from the persisted run.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @return Context
	 */
	private function context( EffectPlan $plan ): Context {
		$row = $this->db->rows( 'SELECT c.public_id,c.profile,r.public_id AS run_id FROM ' . $this->db->table( 'runs' ) . ' r INNER JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.id=%d AND r.owner_ref=%s', array( $plan->run_id, $plan->actor_ref ) )[0];
		return new Context( Actor::user( (int) substr( $plan->actor_ref, 5 ) ), $row['profile'], 'worker', $row['public_id'], $row['run_id'] );
	}
	/**
	 * Recheck the actor and exact native effect target.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @param Context    $context Server-resolved actor and workflow authority.
	 * @return bool
	 */
	public function permitted( EffectPlan $plan, Context $context ): bool {
		try {
			$p = $this->media->proposal( $plan );
			$this->forms->assert_form( $context, $p->form );
			ListingMediaService::field( $context, $p->form );
			$key = 'listingform:' . $context->actor()->user_id() . ':' . $p->directory_id . ':' . $p->listing_id;
			return 'listing.media_import' === $plan->capability && $context->actor()->owner()->reference() === $plan->actor_ref && 1 === count( $plan->targets ) && $plan->targets[0]->key === $key;
		} catch ( \Throwable $e ) {
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
		return ListingFormService::schema_hash( $this->media->proposal( $plan )->directory_id );
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
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
				'numberposts' => 2,
				'meta_key'    => '_directorist_ai_form_media_operation',
				'meta_value'  => $plan->operation_id,
			)
		);
		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}
	/**
	 * Execute the reviewed effect without submitting or publishing a listing.
	 *
	 * @param EffectPlan   $plan Exact reviewed effect plan.
	 * @param EffectTarget $target Fenced native effect target.
	 * @param int          $fence Current execution fence.
	 * @return EffectOutcome
	 */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$p       = $this->media->proposal( $plan );
		$context = $this->context( $plan );
		$this->forms->assert_form( $context, $p->form );
		if ( $this->marked( $plan ) ) {
			return $this->reconcile( $plan, $target, $fence );
		}
		$source = MediaSource::from_inline( $p->base64, $p->mime, $p->sha256 );
		$field  = ListingMediaService::field( $context, $p->form );
		$total  = $source['size'];
		foreach ( $p->form->media->gallery_ids as $id ) {
			if ( $id === $p->form->media->replace_id ) {
				continue;
			}
			$file = get_attached_file( $id );
			if ( ! $file || ! is_file( $file ) ) {
				return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'native_gallery_unavailable' );
			}
			$total += filesize( $file );
		}
		if ( $source['size'] > $field->get_per_image_upload_size() || ( 'gallery' === $p->slot && $total > $field->get_total_upload_size() ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'native_media_limit' );
		}
		$upload = wp_upload_bits( 'directorist-ai-' . substr( $source['hash'], 0, 16 ) . '.' . $source['extension'], null, $source['bytes'] );
		if ( ! empty( $upload['error'] ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'native_upload_failed' );
		}
		$id = wp_insert_attachment(
			wp_slash(
				array(
					'post_title'     => sanitize_text_field( $p->brief ),
					'post_parent'    => $p->listing_id,
					'post_author'    => $context->actor()->user_id(),
					'post_mime_type' => $source['mime'],
					'post_status'    => 'inherit',
					'guid'           => $upload['url'],
					'meta_input'     => array(
						'_directorist_ai_form_media_operation' => $plan->operation_id,
						'_directorist_ai_form_media_hash' => $source['hash'],
						'_directorist_ai_form_media_effect' => $plan->proposal_hash,
					),
				)
			),
			$upload['file'],
			$p->listing_id,
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_delete_file( $upload['file'] );
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'native_attachment_failed' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		try {
			$metadata = wp_generate_attachment_metadata( $id, $upload['file'] );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $id, $metadata );
			}
		} catch ( \Throwable $e ) {
			/* Original imported image remains usable. */
		}
		return $this->reconcile( $plan, $target, $fence );
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
		$p    = $this->media->proposal( $plan );
		$id   = $this->marked( $plan );
		$post = $id ? get_post( $id ) : null;
		$file = $id ? wp_get_original_image_path( $id ) : false;
		if ( ! $post || (int) substr( $plan->actor_ref, 5 ) !== (int) $post->post_author || (int) $post->post_parent !== $p->listing_id || get_post_meta( $id, '_directorist_ai_form_media_effect', true ) !== $plan->proposal_hash || ! $file || ! is_file( $file ) || ! hash_equals( $p->sha256, hash_file( 'sha256', $file ) ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_image_readback_uncertain' );
		}
		// Never write _listing_prv_img, _thumbnail_id, _listing_img or listing moderation here.
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			ListingFormService::source_hash( $p->listing_id ),
			(object) array(
				'attachment_id' => $id,
				'url'           => wp_get_attachment_url( $id ),
				'listing_id'    => $p->listing_id,
				'slot'          => $p->slot,
				'replace_id'    => $p->form->media->replace_id,
				'sha256'        => $p->sha256,
				'saved'         => false,
			)
		);
	}
}
