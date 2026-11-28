<?php
/**
 * Immutable human-review content prepared by trusted domain code.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Approvals;

use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Display wording and every effect-bearing value share one review hash. */
final class ApprovalSpec {
	/**
	 * Exact proposed operation.
	 *
	 * @var EffectPlan
	 */
	public EffectPlan $plan;
	/**
	 * Immutable proposal artifact ID.
	 *
	 * @var string
	 */
	public string $artifact_id;
	/**
	 * Immutable proposal artifact version.
	 *
	 * @var int
	 */
	public int $artifact_version;
	/**
	 * Proposal artifact content hash.
	 *
	 * @var string
	 */
	public string $artifact_hash;
	/**
	 * Dependency and schema environment hash.
	 *
	 * @var string
	 */
	public string $environment_hash;
	/**
	 * Human-readable structured impact.
	 *
	 * @var object
	 */
	public object $impact;
	/**
	 * Exact server-computed execution limits.
	 *
	 * @var object
	 */
	public object $limits;

	/**
	 * Initialize exact review data before it reaches the approval table.
	 *
	 * @param EffectPlan $plan Exact proposed operation.
	 * @param string     $artifact_id Immutable artifact ID.
	 * @param int        $artifact_version Immutable artifact version.
	 * @param string     $artifact_hash Artifact content hash.
	 * @param string     $environment_hash Dependency and schema fingerprint.
	 * @param object     $impact Human-readable structured impact.
	 * @param object     $limits Server-computed execution limits.
	 * @throws ContractException Review specification is malformed.
	 */
	public function __construct( EffectPlan $plan, string $artifact_id, int $artifact_version, string $artifact_hash, string $environment_hash, object $impact, object $limits ) {
		if ( $artifact_version < 1 || ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $artifact_id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $artifact_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $environment_hash ) ) {
			throw new ContractException( 'invalid_input', 'Invalid approval review specification.' );
		}
		( new JsonDecoder( 1048576, 32 ) )->check( $impact );
		( new JsonDecoder( 1048576, 32 ) )->check( $limits );
		$this->plan             = $plan;
		$this->artifact_id      = $artifact_id;
		$this->artifact_version = $artifact_version;
		$this->artifact_hash    = $artifact_hash;
		$this->environment_hash = $environment_hash;
		$this->impact           = $impact;
		$this->limits           = $limits;
	}

	/** Return the exact immutable review body. */
	public function data(): array {
		return array(
			'plan'             => $this->plan->data(),
			'artifact'         => array(
				'id'      => $this->artifact_id,
				'version' => $this->artifact_version,
				'hash'    => $this->artifact_hash,
			),
			'environment_hash' => $this->environment_hash,
			'impact'           => $this->impact,
			'limits'           => $this->limits,
		);
	}

	/** Stable review hash shown to and returned by the trusted UI. */
	public function hash(): string {
		return hash( 'sha256', json_encode( $this->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}
}
