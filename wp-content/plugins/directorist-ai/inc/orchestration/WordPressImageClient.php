<?php
/**
 * WordPress configured provider image adapter, with no plugin credential store.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Domain\MediaSource;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Files\DTO\File;

/** Trust native provider bytes only after checking their actual image MIME and bounds. */
final class WordPressImageClient implements ImageClient {
	/**
	 * Optional run-captured policy; direct readiness checks use listing defaults.
	 *
	 * @var array|null
	 */
	private ?array $ai_route;

	/**
	 * Capture an optional native execution policy.
	 *
	 * @param array|null $ai_route Captured source policy.
	 */
	public function __construct( ?array $ai_route = null ) {
		$this->ai_route = $ai_route;
	}

	/** Return whether WordPress can route an image request without making one. */
	public function available(): bool {
		$route = \DirectoristAIPro\AI\Settings::for_task( $this->ai_route ?? \DirectoristAIPro\AI\Settings::snapshot( 'owner' ), 'image' );
		if ( 'managed' === $route['source'] ) {
			return ( new ManagedImageClient( $route ) )->available(); }
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return false;
		}
		try {
			$builder = \DirectoristAIPro\AI\Routing::bind( wp_ai_client_prompt( 'Image capability check.' ), $this->ai_route ?? \DirectoristAIPro\AI\Settings::snapshot( 'owner' ), 'image' );
			return $builder->is_supported_for_image_generation();
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- Both explicit throw sites emit the same ModelException type.
	/**
	 * Generate one inline image through WordPress's configured provider.
	 *
	 * @param string $brief Bounded image brief.
	 * @throws ModelException When native provider output is unavailable or invalid.
	 */
	public function generate( string $brief ): ImageResponse {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			throw new ModelException( 'provider_unavailable', 'WordPress AI support is unavailable.' );
		}
		$builder = wp_ai_client_prompt( $brief )
			->using_candidate_count( 1 )
			->using_request_options( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => 25.0 ) ) );
		$builder = \DirectoristAIPro\AI\Routing::bind( $builder, $this->ai_route ?? \DirectoristAIPro\AI\Settings::snapshot( 'owner' ), 'image' );
		try {
			$supported = $builder->is_supported_for_image_generation();
		} catch ( \Throwable $error ) {
			throw new ModelException( 'provider_capability_unknown', \DirectoristAIPro\AI\FailureNotice::message( 'provider_capability_unknown', 'wordpress', 'image' ) ); // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
		}
		// Some native wrapper errors return the builder; only an explicit true admits generation.
		if ( true !== $supported ) {
			$code = false === $supported ? 'unsupported_provider_capability' : 'provider_capability_unknown';
			throw new ModelException( $code, \DirectoristAIPro\AI\FailureNotice::message( $code, 'wordpress', 'image' ) ); // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
		}
		$result = $builder->generate_image_result();
		if ( is_wp_error( $result ) ) {
			throw new ModelException( 'provider_unavailable', 'The configured WordPress AI provider could not generate an image.' );
		}
		try {
			if ( 1 !== count( $result->getCandidates() ) ) {
				throw new \RuntimeException( 'Unexpected image count.' );
			}
			$file = $result->toImageFile();
			if ( $file->isInline() ) {
				$encoded = $file->getBase64Data();
				if ( ! is_string( $encoded ) || strlen( $encoded ) > (int) ceil( MediaSource::MAX_BYTES * 4 / 3 ) + 4 ) {
					throw new \RuntimeException( 'Provider image is not bounded inline data.' );
				}
				$bytes  = base64_decode( $encoded, true );
				$image  = false !== $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed provider bytes fail strict validation below.
				$mime   = is_array( $image ) ? (string) ( $image['mime'] ?? '' ) : '';
				$source = MediaSource::from_inline( $encoded, $mime );
				$file   = new File( $encoded, $source['mime'] );
			}
			$usage       = $result->getTokenUsage();
			$provider_id = $result->getProviderMetadata()->getId();
			$model_id    = $result->getModelMetadata()->getId();
			if ( ! is_string( $provider_id ) || ! preg_match( '/^[a-z0-9_-]{1,128}$/D', $provider_id ) || ! is_string( $model_id ) || ! preg_match( '/^[A-Za-z0-9._:\\/@+-]{1,191}$/D', $model_id ) ) {
				$provider_id = null;
				$model_id    = null;
			}
			$response            = new ImageResponse( $file, $usage->getPromptTokens(), $usage->getCompletionTokens(), hash( 'sha256', $result->getId() ), $provider_id, $model_id );
			$response->telemetry = \DirectoristAIPro\AI\Telemetry::result( $result );
			return $response;
		} catch ( \Throwable $error ) {
			throw new ModelException( 'invalid_output', 'The configured WordPress AI provider returned an unsupported image result.' );
		}
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
}
