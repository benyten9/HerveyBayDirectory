<?php
/**
 * Owner-only review preview for trusted inline image suggestions.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Domain\MediaSource;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;

/** Owner-only trusted image review preview API. */
final class MediaPreviewApi {
	/**
	 * Register the REST routes.
	 *
	 * @param Database $db Reviewed input.
	 */
	public static function register_routes( Database $db ): void {
		register_rest_route(
			'directorist-ai/v1',
			'/media/reviews/(?P<id>[a-zA-Z0-9_.:-]{1,128})/preview',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => static function ( \WP_REST_Request $request ) use ( $db ) {
					try {
						$owner = Actor::user( get_current_user_id() );
						$review = ( new ApprovalService( $db ) )->get( $owner, (string) $request['id'] );
						$body = $review['review'] ?? null;
						if ( ! in_array( $body->plan->capability ?? null, array( 'media.import', 'listing.media_import' ), true ) || ! in_array( $body->impact->kind ?? null, array( 'media_import', 'media_form_import' ), true ) ) {
							return new \WP_Error( 'unauthorized', 'Media review is unavailable.', array( 'status' => 403 ) );
						}
						$artifact = ( new ArtifactStore( $db ) )->get( $owner->owner(), (string) $body->artifact->id, (int) $body->artifact->version );
						if ( 'media_suggestion' !== $artifact['kind'] || ! hash_equals( $body->artifact->hash, $artifact['content_hash'] ) ) {
							return new \WP_Error( 'approval_mismatch', 'Media artifact changed.', array( 'status' => 409 ) );
						}
						$payload = json_decode( $artifact['payload_json'] );
						if ( 'image' !== ( $payload->kind ?? null ) || (int) $payload->listing_id !== (int) $body->impact->listing_id || (string) $payload->sha256 !== (string) $body->impact->source_hash ) {
							return new \WP_Error( 'approval_mismatch', 'Media artifact is not the reviewed source.', array( 'status' => 409 ) );
						}
						$source = MediaSource::from_inline( (string) $payload->base64, (string) $payload->mime, (string) $payload->sha256 );
						$response = new \WP_REST_Response(
							array(
								'data_url' => 'data:' . $source['mime'] . ';base64,' . $payload->base64,
								'mime'     => $source['mime'],
								'sha256'   => $source['hash'],
								'bytes'    => $source['size']
							)
						);
						$response->header( 'Cache-Control', 'private, no-store' );
						return $response;
					} catch ( \Throwable $error ) {
						return new \WP_Error( 'media_unavailable', 'Media review is unavailable.', array( 'status' => 403 ) );
					}
				}
			)
		);
	}
}
