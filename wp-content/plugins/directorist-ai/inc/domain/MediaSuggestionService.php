<?php
/**
 * Trusted inline provider-image staging into an exact owner review.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Domain;

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Orchestration\WordPressImageClient;
use WordPress\AiClient\Files\DTO\File;

/** Only a trusted WordPress provider File can create a media source artifact. */
final class MediaSuggestionService {
	/**
	 * Db.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Inspector.
	 *
	 * @var DirectoristInspector
	 */
	private DirectoristInspector $inspector;
	/**
	 * Approvals.
	 *
	 * @var ApprovalService
	 */
	private ApprovalService $approvals;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param Database             $db Reviewed input.
	 * @param DirectoristInspector $inspector Reviewed input.
	 * @param ApprovalService      $approvals Reviewed input.
	 */
	public function __construct( Database $db, DirectoristInspector $inspector, ApprovalService $approvals ) {
		$this->db        = $db;
		$this->inspector = $inspector;
		$this->approvals = $approvals;
	}

	/** Capability discovery makes no paid provider request. */
	public static function image_provider_available(): bool {
		return ( new WordPressImageClient() )->available();
	}

	/**
	 * Recheck the exact native image field before reserving provider usage.
	 *
	 * @param DirectoristInspector $inspector Reviewed input.
	 * @param Context              $context Reviewed input.
	 * @param int                  $directory_id Reviewed input.
	 * @param int                  $listing_id Reviewed input.
	 * @param string               $slot Reviewed input.
	 */
	public static function permitted( DirectoristInspector $inspector, Context $context, int $directory_id, int $listing_id, string $slot ): bool {
		if ( $context->actor()->is_guest() || ! in_array( $slot, array( 'preview', 'gallery' ), true ) || ! $inspector->can_inspect( $context, $directory_id, array( $listing_id ) ) || ! directorist_get_listing_form_field( $directory_id, 'image_upload' ) ) {
			return false;
		}
		try {
			$native = $inspector->proposal_context( $context, $directory_id, $listing_id );
			if ( ! $native['inspection']->complete || ! isset( $native['native_fields']['images'] ) ) {
				return false;
			}
			foreach ( $native['descriptors'] as $descriptor ) {
				if ( 'images' === $descriptor->field_id ) {
					return $descriptor->editable && ( ! $descriptor->admin_only || $context->actor()->can( 'manage_options' ) );
				}
			}
		} catch ( \Throwable $error ) {
			return false;
		}
		return false;
	}

	/**
	 * Stage provider bytes; remote File objects are deliberately unsupported.
	 *
	 * @param Context $context Reviewed input.
	 * @param int     $directory_id Reviewed input.
	 * @param int     $listing_id Reviewed input.
	 * @param string  $slot Reviewed input.
	 * @param File    $file Reviewed input.
	 * @param string  $brief Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When native validation fails.
	 */
	public function stage_trusted_image( Context $context, int $directory_id, int $listing_id, string $slot, File $file, string $brief ): object {
		if ( ! $file->isInline() ) {
			throw new ContractException( 'unsupported_provider_capability', 'The provider returned a remote image URL, which cannot be imported.' );
		}
		if ( '' === trim( $brief ) || strlen( $brief ) > 400 || null === $context->run_id() || null === $context->conversation_id() || ! self::permitted( $this->inspector, $context, $directory_id, $listing_id, $slot ) ) {
			throw new ContractException( 'unauthorized', 'Trusted image suggestion is unavailable for this listing.' );
		}
		$encoded = $file->getBase64Data();
		if ( ! is_string( $encoded ) ) {
			throw new ContractException( 'invalid_input', 'Provider did not return inline image bytes.' );
		}
		$source      = MediaSource::from_inline( $encoded, $file->getMimeType() );
		$native      = $this->inspector->proposal_context( $context, $directory_id, $listing_id );
		$media_field = null;
		foreach ( $native['descriptors'] as $descriptor ) {
			if ( 'images' === $descriptor->field_id ) {
				$media_field = $descriptor;
				break;
			}
		}
		if ( ! $native['inspection']->complete || ! $media_field || ! $media_field->editable || ( $media_field->admin_only && ! $context->actor()->can( 'manage_options' ) ) || ! isset( $native['native_fields']['images'] ) ) {
			throw new ContractException( 'unauthorized', 'Native image field is unavailable to this owner.' );
		}
		$listing   = $native['inspection']->listings[0];
		$payload   = (object) array(
			'kind'             => 'image',
			'directory_id'     => $directory_id,
			'listing_id'       => $listing_id,
			'field_id'         => 'images',
			'slot'             => $slot,
			'brief'            => sanitize_text_field( $brief ),
			'mime'             => $source['mime'],
			'base64'           => $encoded,
			'sha256'           => $source['hash'],
			'size'             => $source['size'],
			'expected_version' => $listing->version->content_hash
		);
		$identity  = hash( 'sha256', $context->run_id() . "\0" . $listing_id . "\0" . $slot . "\0" . $source['hash'] );
		$artifact  = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), 'media-' . substr( $identity, 0, 32 ), 'media_suggestion', $payload, $native['environment_hash'] );
		$operation = 'media-' . substr( $identity, 0, 32 );
		$plan      = new EffectPlan( $operation, $context->actor()->owner()->reference(), 'media.import', '1', $artifact['content_hash'], array( new EffectTarget( 'listing:' . $listing_id, $listing->version->content_hash, $artifact['content_hash'] ) ), $this->run_internal_id( $context ) );
		$review    = $this->approvals->create(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				(int) $artifact['version'],
				$artifact['content_hash'],
				$native['environment_hash'],
				(object) array(
					'kind'                => 'media_import',
					'listing_id'          => $listing_id,
					'directory_id'        => $directory_id,
					'field_id'            => 'images',
					'slot'                => $slot,
					'brief'               => sanitize_text_field( $brief ),
					'source_hash'         => $source['hash'],
					'mime'                => $source['mime'],
					'bytes'               => $source['size'],
					'current_status'      => $listing->status,
					'predicted_status'    => (string) directorist_get_listing_edit_status( $directory_id, $listing_id ),
					'current_preview_id'  => (int) directorist_get_listing_preview_image( $listing_id ),
					'current_gallery_ids' => array_values( array_map( 'intval', directorist_get_listing_gallery_images( $listing_id ) ) )
				),
				(object) array(
					'max_targets' => 1,
					'max_images'  => 1
				)
			)
		);
		return (object) array(
			'status'       => 'staged',
			'capability'   => 'media.import',
			'artifact'     => (object) array(
				'id'      => $artifact['public_id'],
				'version' => (int) $artifact['version'],
				'hash'    => $artifact['content_hash']
			),
			'operation_id' => $operation,
			'review'       => (object) array(
				'id'          => $review['id'],
				'state'       => $review['state'],
				'review_hash' => $review['review_hash'],
				'expires_at'  => $review['expires_at']
			)
		);
	}

	/**
	 * Read only the artifact tied to the reserved approval or exact unreserved hash.
	 *
	 * @param EffectPlan $plan Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When native validation fails.
	 */
	public function proposal_for_plan( EffectPlan $plan ): object {
		$artifacts = $this->db->table( 'artifacts' );
		if ( null === $plan->approval_id ) {
			$rows = $this->db->rows( "SELECT payload_json,content_hash,environment_hash FROM $artifacts WHERE owner_ref=%s AND kind='media_suggestion' AND public_id=%s AND content_hash=%s AND version=1", array( $plan->actor_ref, $plan->operation_id, $plan->proposal_hash ) );
		} else {
			$approvals = $this->db->table( 'approvals' );
			$rows      = $this->db->rows( "SELECT a.payload_json,a.content_hash,a.environment_hash FROM $approvals p INNER JOIN $artifacts a ON a.public_id=p.artifact_id AND a.version=p.artifact_version WHERE p.id=%d AND p.actor_ref=%s AND p.capability='media.import' AND a.kind='media_suggestion'", array( $plan->approval_id, $plan->actor_ref ) );
		}
		if ( 1 !== count( $rows ) || ! hash_equals( $rows[0]['content_hash'], $plan->proposal_hash ) ) {
			throw new ContractException( 'approval_mismatch', 'Approved media artifact is unavailable.' );
		}
		return (object) array(
			'proposal'         => json_decode( $rows[0]['payload_json'] ),
			'environment_hash' => $rows[0]['environment_hash']
		);
	}

	/**
	 * Resolve the internal run identifier.
	 *
	 * @param Context $context Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When the bound run is unavailable.
	 */
	private function run_internal_id( Context $context ): int {
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$id            = $this->db->scalar( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.public_id=%s", array( $context->run_id(), $context->actor()->owner()->reference(), $context->conversation_id() ) );
		if ( null === $id ) {
			throw new ContractException( 'unauthorized', 'Bound media run is unavailable.' );
		}
		return (int) $id;
	}
}
