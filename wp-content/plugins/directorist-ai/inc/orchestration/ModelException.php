<?php
/**
 * Redacted provider boundary failure.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Raw provider messages and credentials never enter durable state. */
final class ModelException extends \RuntimeException {
	/**
	 * Safe stable error code.
	 *
	 * @var string
	 */
	private string $error_code;
	/**
	 * Whether a bounded retry may help.
	 *
	 * @var bool
	 */
	private bool $retryable;
	/**
	 * Safe optional managed receipt, never credentials.
	 *
	 * @var array
	 */
	public array $telemetry;

	/**
	 * Private model-owned repair data, separate from public receipt telemetry.
	 *
	 * @var object|null
	 */
	private ?object $builder_factory_repair_data = null;
	private ?object $builder_read_repair_data = null;
	private ?string $builder_rejected_output = null;

	/** Record only a semantic fingerprint, never submitted values or private content. */
	public function set_builder_rejected_output( string $function, string $arguments ): void {
		$this->builder_rejected_output = GenerationProgress::output_fingerprint( $function, $arguments );
	}

	public function builder_rejected_output(): ?string {
		return $this->builder_rejected_output;
	}

	/** Private bounded read diagnostics never enter public receipt telemetry. */
	public function set_builder_read_feedback( ?object $feedback ): void {
		try {
			( new \DirectoristAIPro\Validation\JsonDecoder( 4096, 16 ) )->check( $feedback );
			$this->builder_read_repair_data = null === $feedback ? null : json_decode( json_encode( $feedback, JSON_THROW_ON_ERROR ), false, 17, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) { $this->builder_read_repair_data = null; }
	}

	/** Detached repair data belongs only to the exact admitted builder request. */
	public function builder_read_feedback(): ?object {
		return null === $this->builder_read_repair_data ? null : json_decode( json_encode( $this->builder_read_repair_data, JSON_THROW_ON_ERROR ), false, 17, JSON_THROW_ON_ERROR );
	}

	/**
	 * Carry bounded advisory data separately from all public diagnostics.
	 *
	 * @param object|null $candidate Strict bounded candidate or no candidate.
	 * @return void
	 */
	public function set_builder_factory_repair_candidate( ?object $candidate ): void {
		try {
			( new \DirectoristAIPro\Validation\JsonDecoder( 17408, 36 ) )->check( $candidate );
			$this->builder_factory_repair_data = null === $candidate ? null : json_decode( json_encode( $candidate, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 37, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			$this->builder_factory_repair_data = null;
		}
	}

	/** Return a detached private copy for the same run's fenced repair coordinator. */
	public function builder_factory_repair_candidate(): ?object {
		return null === $this->builder_factory_repair_data ? null : json_decode( json_encode( $this->builder_factory_repair_data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 37, JSON_THROW_ON_ERROR );
	}

	/**
	 * Construct one redacted failure.
	 *
	 * @param string $error_code Stable public code.
	 * @param string $message Safe diagnostic.
	 * @param bool   $retryable Whether retry is allowed.
	 * @param array  $telemetry Safe receipt metadata.
	 */
	public function __construct( string $error_code, string $message, bool $retryable = false, array $telemetry = array() ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->retryable  = $retryable;
		$this->telemetry  = $telemetry;
	}

	/** Return the stable redacted code. */
	public function error_code(): string {
		return $this->error_code;
	}

	/** Whether the coordinator may schedule a bounded retry. */
	public function retryable(): bool {
		return $this->retryable;
	}
}
