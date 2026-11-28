<?php
/**
 * Exact reviewed native attachment import; never fetches a model URL.
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

/** Ledger fencing, attachment marker and reviewed hash prevent duplicate imports. */
final class MediaImportHandler implements EffectHandler {
	/**
	 * Suggestions.
	 *
	 * @var MediaSuggestionService
	 */
	private MediaSuggestionService $suggestions;
	/**
	 * Inspector.
	 *
	 * @var DirectoristInspector
	 */
	private DirectoristInspector $inspector;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param MediaSuggestionService $suggestions Reviewed input.
	 * @param DirectoristInspector   $inspector Reviewed input.
	 */
	public function __construct( MediaSuggestionService $suggestions, DirectoristInspector $inspector ) {
		$this->suggestions = $suggestions;
		$this->inspector   = $inspector;
	}

	/**
	 * Check whether the reviewed effect remains authorized.
	 *
	 * @param EffectPlan $plan Reviewed input.
	 * @param Context    $context Reviewed input.
	 */
	public function permitted( EffectPlan $plan, Context $context ): bool {
		if ( 1 !== count( $plan->targets ) || ! preg_match( '/^listing:([1-9][0-9]*)$/D', $plan->targets[0]->key, $match ) ) {
			return false;
		}
		return ! $context->actor()->is_guest() && $context->actor()->owner()->reference() === $plan->actor_ref && $context->actor()->can( 'edit_post', (int) $match[1] );
	}

	/**
	 * Return the current native environment hash.
	 *
	 * @param EffectPlan $plan Reviewed input.
	 */
	public function environment_hash( EffectPlan $plan ): string {
		$proposal = $this->suggestions->proposal_for_plan( $plan )->proposal;
		return $this->inspector->proposal_context( $this->context( $plan ), (int) $proposal->directory_id, (int) $proposal->listing_id )['environment_hash'];
	}

	/**
	 * Commit the reviewed native effect.
	 *
	 * @param EffectPlan   $plan Reviewed input.
	 * @param EffectTarget $target Reviewed input.
	 * @param int          $fence Reviewed input.
	 */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$proposal = $this->suggestions->proposal_for_plan( $plan )->proposal;
		$context  = $this->context( $plan );
		if ( 'image' !== ( $proposal->kind ?? null ) || 'images' !== ( $proposal->field_id ?? null ) || ! in_array( $proposal->slot ?? null, array( 'preview', 'gallery' ), true ) || 'listing:' . $proposal->listing_id !== $target->key || ! hash_equals( $plan->proposal_hash, $target->effect_hash ) ) {
			return $this->outcome( 'failed', $target, 'approval_mismatch' );
		}
		if ( $this->marked_attachment( $plan, $proposal ) ) {
			return $this->reconcile( $plan, $target, $fence );
		}
		try {
			$source = MediaSource::from_inline( (string) $proposal->base64, (string) $proposal->mime, (string) $proposal->sha256 );
			$field  = directorist_get_listing_form_field( (int) $proposal->directory_id, 'image_upload' );
			if ( ! $field ) {
				return $this->outcome( 'failed', $target, 'native_media_field_unavailable' );
			}
			$native_field = \Directorist\Fields\Fields::create( $field );
			if ( $source['size'] > min( MediaSource::MAX_BYTES, (int) $native_field->get_per_image_upload_size() ) ) {
				return $this->outcome( 'failed', $target, 'native_media_size_limit' );
			}
		} catch ( ContractException $error ) {
			return $this->outcome( 'failed', $target, $error->error_code() );
		} catch ( \Throwable $error ) {
			return $this->outcome( 'failed', $target, 'native_media_field_unavailable' );
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->outcome( 'unknown', $target, 'native_transaction_unavailable' );
		}
		$path = '';
		try {
			$wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE", (int) $proposal->listing_id ) );
			if ( ! $context->actor()->can( 'edit_post', (int) $proposal->listing_id ) || (int) directorist_get_listing_directory( (int) $proposal->listing_id ) !== (int) $proposal->directory_id ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->outcome( 'failed', $target, 'unauthorized' );
			}
			$inspection = $this->inspector->inspect( $context, (int) $proposal->directory_id, array( (int) $proposal->listing_id ) );
			if ( ! hash_equals( $target->expected_version, $inspection->listings[0]->version->content_hash ) || ! hash_equals( $target->expected_version, (string) $proposal->expected_version ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->outcome( 'failed', $target, 'stale_version' );
			}
			$preview = (int) directorist_get_listing_preview_image( (int) $proposal->listing_id );
			$gallery = array_values( array_map( 'intval', directorist_get_listing_gallery_images( (int) $proposal->listing_id ) ) );
			$count   = ( $preview ? 1 : 0 ) + count( $gallery );
			$total   = $source['size'];
			foreach ( array_filter( array_merge( array( $preview ), $gallery ) ) as $existing_id ) {
				$existing_file = get_attached_file( $existing_id );
				$total        += is_string( $existing_file ) && is_file( $existing_file ) ? (int) filesize( $existing_file ) : 0;
			}
			$new_count = $count + ( 'preview' === $proposal->slot && $preview ? 0 : 1 );
			$max_count = (int) $native_field->get_total_upload_limit();
			if ( ( $max_count > 0 && $new_count > $max_count ) || $total > (int) $native_field->get_total_upload_size() ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->outcome( 'failed', $target, 'native_media_limit' );
			}
			$name     = 'directorist-ai-' . substr( $source['hash'], 0, 20 ) . '.' . $source['extension'];
			$uploaded = wp_upload_bits( $name, null, $source['bytes'] );
			if ( ! is_array( $uploaded ) || ! empty( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				if ( is_array( $uploaded ) && ! empty( $uploaded['file'] ) && is_file( $uploaded['file'] ) ) {
					wp_delete_file( $uploaded['file'] );
				}
				return $this->outcome( 'failed', $target, 'native_upload_failed' );
			}
			$path       = $uploaded['file'];
			$attachment = wp_insert_attachment(
				array(
					'post_mime_type' => $source['mime'],
					'post_title'     => sanitize_file_name( $name ),
					'post_parent'    => (int) $proposal->listing_id,
					'post_author'    => $context->actor()->user_id(),
					'post_status'    => 'inherit',
					'guid'           => $uploaded['url'],
					'meta_input'     => array(
						'_directorist_ai_media_operation' => $plan->operation_id,
						'_directorist_ai_media_hash'      => $source['hash'],
						'_directorist_ai_media_effect_hash' => $target->effect_hash
					)
				),
				$path,
				(int) $proposal->listing_id,
				true
			);
			if ( is_wp_error( $attachment ) ) {
				$wpdb->query( 'ROLLBACK' );
				wp_delete_file( $path );
				return $this->outcome( 'failed', $target, 'native_attachment_failed' );
			}
			$id = (int) $attachment;
			if ( ! $id || get_post_meta( $id, '_directorist_ai_media_operation', true ) !== $plan->operation_id || ! hash_equals( (string) get_post_meta( $id, '_directorist_ai_media_hash', true ), $source['hash'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->outcome( 'unknown', $target, 'attachment_marker_unavailable' );
			}
			if ( 'preview' === $proposal->slot ) {
				update_post_meta( (int) $proposal->listing_id, '_listing_prv_img', $id );
				set_post_thumbnail( (int) $proposal->listing_id, $id );
			} else {
				$gallery[] = $id;
				update_post_meta( (int) $proposal->listing_id, '_listing_img', $gallery );
			}
			$native_status = (string) directorist_get_listing_edit_status( (int) $proposal->directory_id, (int) $proposal->listing_id );
			if ( $native_status !== $inspection->listings[0]->status ) {
				$updated = wp_update_post(
					array(
						'ID'          => (int) $proposal->listing_id,
						'post_status' => $native_status
					),
					true
				);
				if ( is_wp_error( $updated ) ) {
					$wpdb->query( 'ROLLBACK' );
					return $this->outcome( 'unknown', $target, 'native_moderation_uncertain' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return $this->outcome( 'unknown', $target, 'native_commit_uncertain' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->outcome( 'unknown', $target, 'native_media_uncertain' );
		}
		try {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $id, $path );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $id, $metadata );
			}
		} catch ( \Throwable $error ) {
			// The original attachment and native mapping are committed and replayable.
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
		$proposal = $this->suggestions->proposal_for_plan( $plan )->proposal;
		$id       = $this->marked_attachment( $plan, $proposal );
		if ( ! $id ) {
			return $this->outcome( 'unknown', $target, 'attachment_marker_missing_no_retry' );
		}
		$attachment = get_post( $id );
		$file       = get_attached_file( $id );
		if ( ! $attachment instanceof \WP_Post || (int) $attachment->post_parent !== (int) $proposal->listing_id || (int) $attachment->post_author !== $this->context( $plan )->actor()->user_id() || ! is_string( $file ) || ! is_file( $file ) || ! hash_equals( (string) $proposal->sha256, (string) hash_file( 'sha256', $file ) ) ) {
			return $this->outcome( 'unknown', $target, 'attachment_source_changed' );
		}
		$mapped = 'preview' === $proposal->slot ? (int) directorist_get_listing_preview_image( (int) $proposal->listing_id ) === $id : in_array( $id, array_map( 'intval', directorist_get_listing_gallery_images( (int) $proposal->listing_id ) ), true );
		if ( ! $mapped || ! get_post( (int) $proposal->listing_id ) || ! $this->context( $plan )->actor()->can( 'edit_post', (int) $proposal->listing_id ) ) {
			return $this->outcome( 'unknown', $target, 'attachment_mapping_changed' );
		}
		$inspection = $this->inspector->inspect( $this->context( $plan ), (int) $proposal->directory_id, array( (int) $proposal->listing_id ) );
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			$inspection->listings[0]->version->content_hash,
			(object) array(
				'attachment_id' => $id,
				'listing_id'    => (int) $proposal->listing_id,
				'sha256'        => $proposal->sha256,
				'slot'          => $proposal->slot,
				'status'        => $inspection->listings[0]->status,
				'fence'         => $fence,
				'reconciled'    => true
			),
			array()
		);
	}

	/**
	 * Find the attachment bearing the exact operation marker.
	 *
	 * @param EffectPlan $plan Reviewed input.
	 * @param object     $proposal Reviewed input.
	 */
	private function marked_attachment( EffectPlan $plan, object $proposal ): int {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => (int) $proposal->listing_id,
				'numberposts' => 2,
				'fields'      => 'ids',
				'meta_key'    => '_directorist_ai_media_operation',
				'meta_value'  => $plan->operation_id
			)
		);
		if ( 1 !== count( $ids ) ) {
			return 0;
		}
		$id = (int) $ids[0];
		return hash_equals( (string) get_post_meta( $id, '_directorist_ai_media_hash', true ), (string) $proposal->sha256 ) && hash_equals( (string) get_post_meta( $id, '_directorist_ai_media_effect_hash', true ), $plan->proposal_hash ) ? $id : 0;
	}

	/**
	 * Reconstruct the approved actor context.
	 *
	 * @param EffectPlan $plan Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When reviewed native state is invalid.
	 */
	private function context( EffectPlan $plan ): Context {
		if ( ! str_starts_with( $plan->actor_ref, 'user:' ) ) {
			throw new ContractException( 'unauthorized', 'Media import requires an authenticated owner.' );
		}
		return new Context( Actor::user( (int) substr( $plan->actor_ref, 5 ) ), 'external', 'worker' );
	}

	/**
	 * Build a bounded effect outcome.
	 *
	 * @param string       $state Reviewed input.
	 * @param EffectTarget $target Reviewed input.
	 * @param string       $code Reviewed input.
	 */
	private function outcome( string $state, EffectTarget $target, string $code ): EffectOutcome {
		return new EffectOutcome( $state, $target->expected_version, 'unknown' === $state ? null : $target->expected_version, (object) array(), array(), $code );
	}
}
