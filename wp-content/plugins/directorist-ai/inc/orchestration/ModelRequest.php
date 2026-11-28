<?php
/**
 * Closed request passed to the configured WordPress AI provider.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Validation\ContractException;

/** Provider selection and credentials remain owned by WordPress. */
final class ModelRequest {
	/** Complete trusted policies still pass the independent serialized input budget. */
	public const MAX_SYSTEM_BYTES = 24576;
	/**
	 * Captured administrator policy, independent of model-provided content.
	 *
	 * @var array|null
	 */
	public ?array $ai_route = null;
	/**
	 * Durable managed provider attempt identity.
	 *
	 * @var string
	 */
	public string $managed_request_id = '';
	/**
	 * Logical operation identity, never visitor billing identity.
	 *
	 * @var string
	 */
	public string $managed_operation_id = '';
	/**
	 * Poll only after a possibly accepted provider attempt.
	 *
	 * @var bool
	 */
	public bool $managed_poll = false;
	/** Private original request, never public model telemetry or a newly assembled retry. */
	public ?object $managed_submission = null;
	/** Same identity/body POST after a missing receipt; still the original logical call. */
	public bool $managed_resubmit = false;
	/**
	 * Capability being requested, for separate text and image accounting.
	 *
	 * @var string
	 */
	public string $task = 'text';
	/**
	 * Exact native declaration snapshots used for reservation and transport.
	 *
	 * @var \WordPress\AiClient\Tools\DTO\FunctionDeclaration[]|null
	 */
	public ?array $function_declarations = null;
	/**
	 * Exact visitor function using the compact, server-expanded plan contract.
	 *
	 * @var string|null
	 */
	public ?string $visitor_plan_function = null;
	/**
	 * Final visitor response phase.
	 *
	 * @var bool
	 */
	public bool $visitor_response = false;
	/** Unconfirmed visitors classify the question language in the same plan call. */
	public bool $visitor_language_gate = false;
	/**
	 * Server-selected syntax restriction for an explicit new directory layout.
	 *
	 * @var bool
	 */
	public bool $builder_initial_layout = false;
	/**
	 * Optional model-only operation restriction; native gateway schemas stay exact.
	 *
	 * @var string|null
	 */
	public ?string $builder_change_operation = null;
	/**
	 * Exact server-owned initial factory; frozen with the admitted model step.
	 *
	 * @var object|null
	 */
	public ?object $builder_initial_factory = null;
	/** Private current native read map, never model-owned authority. */
	public ?object $builder_read_targets = null;
	/**
	 * Current native style targets admitted for this request.
	 *
	 * @var object|null
	 */
	public ?object $builder_style_targets = null;
	/**
	 * Whether the current Gutenberg edit uses direct native arguments.
	 *
	 * @var bool
	 */
	public bool $builder_editor_direct = false;
	/**
	 * Opaque admitted native style target bytes for a pending result.
	 *
	 * @var string|null
	 */
	public ?string $builder_style_targets_json = null;
	/** Opaque admitted read-map bytes restored only for an existing paid result. */
	public ?string $builder_read_targets_json = null;
	/** Current owner/site/run/turn/capture binding independently recomputed by runtime. */
	public string $builder_read_authority_hash = '';
	/**
	 * Opaque admitted snapshot bytes; never a new factory source.
	 *
	 * @var string|null
	 */
	public ?string $builder_initial_factory_json = null;
	/**
	 * Native prose length for the final response phase.
	 *
	 * @var int
	 */
	public int $visitor_response_max_length = 1800;
	/**
	 * Delimited task context.
	 *
	 * @var string
	 */
	public string $prompt;
	/**
	 * Stable server policy.
	 *
	 * @var string
	 */
	public string $system_instruction;
	/**
	 * Explicit native ability names.
	 *
	 * @var string[]
	 */
	public array $ability_names;
	/**
	 * Maximum generated tokens.
	 *
	 * @var int
	 */
	public int $max_tokens;
	/**
	 * HTTP timeout in seconds.
	 *
	 * @var float
	 */
	public float $timeout;
	/** Conservative serialized-input admission, independently bounded from output. */
	public int $input_limit = 64000;
	public bool $context_capacity_verified = false;

	/**
	 * Validate one bounded provider request.
	 *
	 * @param string   $prompt Delimited task context.
	 * @param string   $system_instruction Stable policy instruction.
	 * @param string[] $ability_names Exact native ability allowlist.
	 * @param int      $max_tokens Maximum generated tokens.
	 * @param float    $timeout Request timeout seconds.
	 * @throws ContractException Invalid provider request.
	 */
	public function __construct( string $prompt, string $system_instruction, array $ability_names, int $max_tokens, float $timeout ) {
		$bounds = array_filter(
			array(
				'model_prompt_bound'        => '' === trim( $prompt ) || strlen( $prompt ) > 262144,
				'model_instruction_bound'   => '' === trim( $system_instruction ) || strlen( $system_instruction ) > self::MAX_SYSTEM_BYTES,
				'model_ability_count_bound' => count( $ability_names ) > 32,
				'model_output_bound'        => $max_tokens < 1 || $max_tokens > 16384,
				'model_timeout_bound'       => $timeout < 1 || $timeout > 60,
			)
		);
		if ( $bounds ) {
			throw new ContractException( 'invalid_input', 'Invalid bounded model request.', array_keys( $bounds ) );
		}
		$unique = array();
		foreach ( $ability_names as $name ) {
			if ( ! is_string( $name ) ) {
				throw new ContractException( 'invalid_input', 'Invalid model ability allowlist.' );
			}
			ConversationStore::identifier( str_replace( '/', ':', $name ) );
			$unique[ $name ] = true;
		}
		$this->prompt             = $prompt;
		$this->system_instruction = $system_instruction;
		$this->ability_names      = array_keys( $unique );
		$this->max_tokens         = $max_tokens;
		$this->timeout            = $timeout;
	}
}
