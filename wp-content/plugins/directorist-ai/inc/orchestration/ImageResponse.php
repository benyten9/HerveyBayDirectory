<?php
/**
 * Trusted WordPress provider image output and measured usage.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use WordPress\AiClient\Files\DTO\File;

/** No remote download occurs in the result or import boundary. */
final class ImageResponse {
	/**
	 * Optional safe response metadata, excluding generated image data.
	 *
	 * @var array
	 */
	public array $telemetry = array();
	/**
	 * File.
	 *
	 * @var File
	 */
	public File $file;
	/**
	 * Input tokens.
	 *
	 * @var int
	 */
	public int $input_tokens;
	/**
	 * Output tokens.
	 *
	 * @var int
	 */
	public int $output_tokens;
	/**
	 * Provider ref.
	 *
	 * @var string
	 */
	public string $provider_ref;
	/**
	 * Provider id.
	 *
	 * @var ?string
	 */
	public ?string $provider_id;
	/**
	 * Model id.
	 *
	 * @var ?string
	 */
	public ?string $model_id;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param File    $file Reviewed input.
	 * @param int     $input_tokens Reviewed input.
	 * @param int     $output_tokens Reviewed input.
	 * @param string  $provider_ref Reviewed input.
	 * @param ?string $provider_id Reviewed input.
	 * @param ?string $model_id Reviewed input.
	 */
	public function __construct( File $file, int $input_tokens, int $output_tokens, string $provider_ref, ?string $provider_id = null, ?string $model_id = null ) {
		$this->file          = $file;
		$this->input_tokens  = $input_tokens;
		$this->output_tokens = $output_tokens;
		$this->provider_ref  = $provider_ref;
		$this->provider_id   = $provider_id;
		$this->model_id      = $model_id;
	}
}
