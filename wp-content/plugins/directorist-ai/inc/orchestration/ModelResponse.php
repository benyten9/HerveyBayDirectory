<?php
/**
 * Safe content-channel response with provider usage.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Validation\ContractException;

/** Hidden reasoning is deliberately absent from this value object. */
final class ModelResponse {
	/**
	 * Optional safe provider-reported metadata, distinct from SDK identities.
	 *
	 * @var array
	 */
	public array $telemetry = array();
	/**
	 * Visible content text.
	 *
	 * @var string|null
	 */
	public ?string $text;
	/**
	 * Content-channel calls.
	 *
	 * @var ModelToolCall[]
	 */
	public array $tool_calls;
	/**
	 * Provider-reported input tokens.
	 *
	 * @var int
	 */
	public int $input_tokens;
	/**
	 * Provider-reported output tokens.
	 *
	 * @var int
	 */
	public int $output_tokens;
	/**
	 * Non-secret provider result correlation.
	 *
	 * @var string
	 */
	public string $provider_ref;
	/**
	 * Native result provider identity, when returned by WordPress.
	 *
	 * @var string|null
	 */
	public ?string $provider_id;
	/**
	 * Native result model identity, when returned by WordPress.
	 *
	 * @var string|null
	 */
	public ?string $model_id;

	/**
	 * Validate one provider result.
	 *
	 * @param string|null     $text Visible content text.
	 * @param ModelToolCall[] $tool_calls Content-channel tool calls.
	 * @param int             $input_tokens Provider-reported input usage.
	 * @param int             $output_tokens Provider-reported output usage.
	 * @param string          $provider_ref Non-secret result correlation hash.
	 * @param string|null     $provider_id Native result provider ID, if available.
	 * @param string|null     $model_id Native result model ID, if available.
	 * @throws ContractException Malformed provider response.
	 */
	public function __construct( ?string $text, array $tool_calls, int $input_tokens, int $output_tokens, string $provider_ref, ?string $provider_id = null, ?string $model_id = null ) {
		if ( ( null === $text || '' === trim( $text ) ) && ! $tool_calls ) {
			throw new ContractException( 'invalid_output', 'Model returned no usable content or tool call.' );
		}
		if ( null !== $text && strlen( $text ) > 65536 || count( $tool_calls ) > 8 || $input_tokens < 0 || $output_tokens < 0 || ! preg_match( '/^[a-f0-9]{64}$/D', $provider_ref ) || ( null === $provider_id ) !== ( null === $model_id ) || ( null !== $provider_id && ! preg_match( '/^[a-z0-9_-]{1,128}$/D', $provider_id ) ) || ( null !== $model_id && ! preg_match( '/^[A-Za-z0-9._:\/@+-]{1,191}$/D', $model_id ) ) ) {
			throw new ContractException( 'invalid_output', 'Model returned an invalid bounded response.' );
		}
		foreach ( $tool_calls as $call ) {
			if ( ! $call instanceof ModelToolCall ) {
				throw new ContractException( 'invalid_output', 'Model tool response is malformed.' );
			}
		}
		$this->text          = null === $text ? null : trim( $text );
		$this->tool_calls    = $tool_calls;
		$this->input_tokens  = $input_tokens;
		$this->output_tokens = $output_tokens;
		$this->provider_ref  = $provider_ref;
		$this->provider_id   = $provider_id;
		$this->model_id      = $model_id;
	}
}
