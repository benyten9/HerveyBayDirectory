<?php
/**
 * Managed owned-image adapter; no arbitrary image URLs.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;
use DirectoristAIPro\AI\ManagedService;
use DirectoristAIPro\Domain\MediaSource;
use WordPress\AiClient\Files\DTO\File;
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed artifact adapter.
/** Maintains the closed server-owned boundary. */
final class ManagedImageClient implements ImageClient {
	private array $route;
	private string $request_id;
	private string $operation_id;
	private bool $poll;
	private ?object $submission;
	private bool $resubmit;
	public function __construct( array $route, string $request_id = '', string $operation_id = '', bool $poll = false, ?object $submission = null, bool $resubmit = false ) {
		$this->route        = \DirectoristAIPro\AI\Settings::for_task( $route, 'image' );
		$this->request_id   = $request_id;
		$this->operation_id = $operation_id;
		$this->poll         = $poll;
		$this->submission   = $submission;
		$this->resubmit     = $resubmit;
	}
	public function available(): bool {
		try {
			ManagedService::assert_available( $this->route );
			$availability = ManagedService::status()['task_availability']['image'] ?? null;
			return $this->poll || null === $availability || true === ( $availability['available'] ?? false );
		} catch ( \Throwable $e ) {
			return false; }
	}
	/** Freeze exactly the image request accepted by the same native image handoff. */
	public static function submission_body( string $brief, string $request_id, string $operation_id ): array {
		return array(
			'schema_version' => 1,
			'request_id'     => $request_id,
			'operation_id'   => $operation_id,
			'type'           => 'image',
			'messages'       => array(),
			'image'          => array(
				'prompt' => $brief,
				'count'  => 1,
			),
		);
	}
	public function generate( string $brief ): ImageResponse {
		if ( $this->resubmit && ( ! $this->poll || null === $this->submission ) ) { throw new ModelException( 'managed_submission_invalid', 'Managed image recovery requires its original immutable request.' ); }
		$body = null === $this->submission ? self::submission_body( $brief, $this->request_id, $this->operation_id ) : ManagedSubmission::body( $this->submission, $this->route, $this->request_id, $this->operation_id );
		$r    = ManagedService::inference( $body, $this->route, $this->poll && ! $this->resubmit, $this->submission->body_json ?? null );
		$t    = ManagedService::receipt( $r );
		try {
			$u        = $r['receipt']['usage'] ?? array();
			$reported = isset( $u['input_tokens'], $u['output_tokens'] );
			$input    = ManagedModelClient::tokens( $u['input_tokens'] ?? null );
			$output   = ManagedModelClient::tokens( $u['output_tokens'] ?? null );
			$t        = array_merge(
				$t,
				array(
					'usage_reported' => $reported,
					'input_tokens'   => $reported ? $input : null,
					'output_tokens'  => $reported ? $output : null,
				)
			);
			$images   = $r['result']['images'] ?? array();
			if ( 1 !== count( $images ) ) {
				throw new \UnexpectedValueException( 'Image count.' ); }
			$bytes            = ManagedService::image( $images[0]['url'], $this->route );
			$info             = getimagesizefromstring( $bytes );
			$source           = MediaSource::from_inline( base64_encode( $bytes ), is_array( $info ) ? ( $info['mime'] ?? '' ) : '' );
			$receipt          = $r['receipt'];
			$u                = $receipt['usage'] ?? array();
			$image            = new ImageResponse( new File( base64_encode( $bytes ), $source['mime'] ), $input, $output, hash( 'sha256', $receipt['id'] ) );
			$image->telemetry = array_merge(
				$t,
				array(
					'usage_reported'   => isset( $u['input_tokens'], $u['output_tokens'] ),
					'reported_model'   => null,
					'validated_images' => 1,
				)
			);
			return $image;
		} catch ( \Throwable $e ) {
			throw new ModelException( 'invalid_output', 'The managed image could not be validated. Its receipt remains recorded.', false, $t ); }
	}
}
