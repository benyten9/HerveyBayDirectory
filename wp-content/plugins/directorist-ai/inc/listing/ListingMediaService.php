<?php
/**
 * Exact native form media scopes; trusted provider bytes remain private until review.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;
use DirectoristAIPro\Approvals\{ApprovalService, ApprovalSpec};
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Domain\MediaSource;
use DirectoristAIPro\Effects\{EffectPlan, EffectTarget};
use DirectoristAIPro\Storage\{ArtifactStore, Database};
use DirectoristAIPro\Validation\ContractException;
use WordPress\AiClient\Files\DTO\File;
/** Owned native listing workflow boundary. */
final class ListingMediaService {
	/**
	 * Bind the site runtime and native listing services.
	 *
	 * @param Database           $db Site-scoped runtime database.
	 * @param ListingFormService $forms Native form authority and captures.
	 */
	public function __construct( private Database $db, private ListingFormService $forms ) {}
	/**
	 * Validate and freeze the exact native form capture for a conversation.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @param object  $form Frozen native form capture.
	 * @param object  $media Exact native image scope or service.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return object
	 */
	public static function bind( Context $context, object $form, object $media ): object {
		$keys = array_keys( get_object_vars( $media ) );
		sort( $keys );
		if ( array( 'gallery_ids', 'preview_id', 'replace_id', 'slot' ) !== $keys || ! in_array( $media->slot, array( 'preview', 'gallery' ), true ) || ! is_int( $media->preview_id ) || $media->preview_id < 0 || ! is_array( $media->gallery_ids ) || count( $media->gallery_ids ) > 100 || ! ( null === $media->replace_id || is_int( $media->replace_id ) && $media->replace_id > 0 ) ) {
			throw new ContractException( 'invalid_input', 'The image selection is invalid.' );
		}
		if ( 'preview' === $media->slot && null !== $media->replace_id || 'gallery' === $media->slot && null !== $media->replace_id && ! in_array( $media->replace_id, $media->gallery_ids, true ) ) {
			throw new ContractException( 'scope_mismatch', 'The selected gallery image is unavailable.' );
		}
		foreach ( array_merge( $media->preview_id ? array( $media->preview_id ) : array(), $media->gallery_ids ) as $id ) {
			if ( ! is_int( $id ) || $id < 1 || ! wp_attachment_is_image( $id ) ) {
				throw new ContractException( 'invalid_input', 'An image selection is unavailable.' );
			}
			$saved = $form->listing_id ? array_merge( array( (int) directorist_get_listing_preview_image( $form->listing_id ) ), array_map( 'intval', directorist_get_listing_gallery_images( $form->listing_id ) ) ) : array();
			if ( ! in_array( $id, $saved, true ) && ! $context->actor()->can( 'edit_post', $id ) ) {
				throw new ContractException( 'unauthorized', 'This image is unavailable to the listing owner.' );
			}
		}
		if ( count( array_unique( $media->gallery_ids ) ) !== count( $media->gallery_ids ) ) {
			throw new ContractException( 'invalid_input', 'Gallery images must be unique.' );
		}
		$form->media = $media;
		self::field( $context, $form );
		return $media;
	}
	/**
	 * Recheck native image visibility and gallery count limits.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @param object  $form Frozen native form capture.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return object
	 */
	public static function field( Context $context, object $form ): object {
		$props  = directorist_get_listing_form_field( $form->directory_id, 'image_upload' );
		$posted = get_object_vars( $form->facts ?? new \stdClass() );
		foreach ( get_object_vars( $form->values ) as $id => $value ) {
			$fields                                = ListingFormService::fields( $form->directory_id, $context->actor()->can( 'manage_options' ) );
			$posted[ $fields[ $id ]['field_key'] ] = $value;
		}
		if ( ! $props || ( ! $context->actor()->can( 'manage_options' ) && ! empty( $props['only_for_admin'] ) ) || ! \Directorist\Fields\Conditional_Logic::should_show_field( $props, $posted ) || ! get_directorist_option( 'display_' . ( 'preview' === $form->media->slot ? 'prv' : 'gallery' ) . '_field', 1 ) ) {
			throw new ContractException( 'unauthorized', 'The native image field is unavailable.' );
		}
		$field = \Directorist\Fields\Fields::create( $props );
		$count = count( $form->media->gallery_ids ) + ( 'gallery' === $form->media->slot && null === $form->media->replace_id ? 1 : 0 );
		if ( 'gallery' === $form->media->slot && $field->get_total_upload_limit() > 0 && $count > $field->get_total_upload_limit() ) {
			throw new ContractException( 'invalid_input', 'The gallery has reached its native image limit. Replace an image instead.', array( 'image_gallery_limit' ) );
		}
		return $field;
	}
	/**
	 * Stage trusted provider image bytes for exact human review.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @param File    $file Trusted native provider image.
	 * @param string  $brief Bounded requested image description.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return object
	 */
	public function stage( Context $context, File $file, string $brief ): object {
		$form = $this->forms->bound( $context );
		if ( ! isset( $form->media ) || ! $file->isInline() ) {
			throw new ContractException( 'unsupported_provider_capability', 'A trusted inline image is required.' );
		}
		$source = MediaSource::from_inline( (string) $file->getBase64Data(), $file->getMimeType() );
		$field  = self::field( $context, $form );
		$total  = 0;
		foreach ( $form->media->gallery_ids as $id ) {
			if ( $id === $form->media->replace_id ) {
				continue;
			}
			$path = get_attached_file( $id );
			if ( ! $path || ! is_file( $path ) ) {
				throw new ContractException( 'evidence_stale', 'A gallery image is unavailable.' );
			}
			$total += filesize( $path );
		}
		$provider_hash = $source['hash'];
		$budget        = min( MediaSource::MAX_BYTES, $field->get_per_image_upload_size() );
		if ( 'gallery' === $form->media->slot ) {
			$budget = min( $budget, $field->get_total_upload_size() - $total );
		}
		$file      = ListingImageNormalizer::fit( $file, $budget );
		$source    = MediaSource::from_inline( (string) $file->getBase64Data(), $file->getMimeType() );
		$payload   = (object) array(
			'kind'            => 'image',
			'form'            => $form,
			'listing_id'      => $form->listing_id,
			'directory_id'    => $form->directory_id,
			'slot'            => $form->media->slot,
			'brief'           => sanitize_text_field( $brief ),
			'mime'            => $source['mime'],
			'base64'          => $file->getBase64Data(),
			'sha256'          => $source['hash'],
			'size'            => $source['size'],
			'provider_sha256' => $provider_hash,
		);
		$operation = 'listing-media-' . substr( hash( 'sha256', $context->run_id() . wp_json_encode( $payload ) ), 0, 32 );
		$artifact  = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), $operation, 'media_suggestion', $payload, $form->schema_hash );
		$run       = $this->db->scalar( 'SELECT id FROM ' . $this->db->table( 'runs' ) . ' WHERE public_id=%s AND owner_ref=%s', array( $context->run_id(), $context->actor()->owner()->reference() ) );
		$plan      = new EffectPlan( $operation, $context->actor()->owner()->reference(), 'listing.media_import', '1', $artifact['content_hash'], array( new EffectTarget( 'listingform:' . $context->actor()->user_id() . ':' . $form->directory_id . ':' . $form->listing_id, $form->source_hash, $artifact['content_hash'] ) ), (int) $run );
		$impact    = (object) array(
			'kind'                => 'media_form_import',
			'listing_id'          => $form->listing_id,
			'directory_id'        => $form->directory_id,
			'slot'                => $form->media->slot,
			'replace_id'          => $form->media->replace_id,
			'brief'               => $payload->brief,
			'source_hash'         => $source['hash'],
			'mime'                => $source['mime'],
			'bytes'               => $source['size'],
			'current_preview_id'  => $form->media->preview_id,
			'current_image_url'   => wp_get_attachment_url( 'preview' === $form->media->slot ? $form->media->preview_id : ( $form->media->replace_id ?? 0 ) ),
			'current_gallery_ids' => $form->media->gallery_ids,
			'predicted_status'    => 'unchanged',
		);
		$review    = ( new ApprovalService( $this->db ) )->create(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				1,
				$artifact['content_hash'],
				$form->schema_hash,
				$impact,
				(object) array(
					'max_targets' => 1,
					'max_images'  => 1,
				)
			)
		);
		return (object) array(
			'status'       => 'staged',
			'capability'   => 'listing.media_import',
			'operation_id' => $operation,
			'artifact'     => (object) array(
				'id'      => $artifact['public_id'],
				'version' => 1,
			),
			'review'       => (object) array(
				'id'          => $review['id'],
				'review_hash' => $review['review_hash'],
			),
		);
	}
	/**
	 * Load the trusted image artifact tied to the reviewed operation.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return object
	 */
	public function proposal( EffectPlan $plan ): object {
		$rows    = $this->db->rows( 'SELECT a.payload_json FROM ' . $this->db->table( 'artifacts' ) . ' a INNER JOIN ' . $this->db->table( 'approvals' ) . ' p ON p.artifact_id=a.public_id AND p.artifact_version=a.version WHERE a.public_id=%s AND a.content_hash=%s AND a.owner_ref=%s AND p.actor_ref=a.owner_ref AND p.capability=%s', array( $plan->operation_id, $plan->proposal_hash, $plan->actor_ref, 'listing.media_import' ) );
		$payload = $rows ? json_decode( $rows[0]['payload_json'] ) : null;
		if ( ! $payload || 'image' !== $payload->kind || ! isset( $payload->form->media ) ) {
			throw new ContractException( 'approval_mismatch', 'The reviewed image is unavailable.' );
		}
		return $payload;
	}
}
