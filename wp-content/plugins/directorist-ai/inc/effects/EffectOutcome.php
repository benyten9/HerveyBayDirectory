<?php
/**
 * Independently observed native target outcome.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Effects;

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Native handlers return facts; the ledger derives aggregate operation state. */
final class EffectOutcome {
	/**
	 * Observed status.
	 *
	 * @var string
	 */
	public string $status;
	/**
	 * Native version observed before the effect.
	 *
	 * @var string
	 */
	public string $before_version;
	/**
	 * Native version after the effect, if known.
	 *
	 * @var string|null
	 */
	public ?string $after_version;
	/**
	 * Bounded observed native changes.
	 *
	 * @var object
	 */
	public object $effects;
	/**
	 * Known non-transactional external effects.
	 *
	 * @var array
	 */
	public array $external_effects;
	/**
	 * Safe diagnostic code.
	 *
	 * @var string|null
	 */
	public ?string $diagnostic;

	/**
	 * Initialize a bounded observed result.
	 *
	 * @param string      $status Observed status.
	 * @param string      $before_version Native version before the attempt.
	 * @param string|null $after_version Native version after the attempt.
	 * @param object      $effects Bounded observed changes.
	 * @param array       $external_effects Known external effects.
	 * @param string|null $diagnostic Safe diagnostic code.
	 * @throws ContractException Invalid outcome contract.
	 */
	public function __construct( string $status, string $before_version, ?string $after_version, object $effects, array $external_effects = array(), ?string $diagnostic = null ) {
		if ( ! in_array( $status, array( 'succeeded', 'no_effect', 'failed', 'unknown' ), true ) || '' === $before_version || ( 'succeeded' === $status && ( null === $after_version || '' === $after_version ) ) || count( $external_effects ) > 20 || ( null !== $diagnostic && strlen( $diagnostic ) > 256 ) ) {
			throw new ContractException( 'invalid_output', 'Invalid native effect outcome.' );
		}
		( new JsonDecoder( 1048576, 32 ) )->check( $effects );
		foreach ( $external_effects as $effect ) {
			if ( ! is_array( $effect ) || array_keys( $effect ) !== array( 'kind', 'state' ) || ! is_string( $effect['kind'] ) || ! is_string( $effect['state'] ) ) {
				throw new ContractException( 'invalid_output', 'Invalid external effect receipt.' );
			}
		}
		$this->status           = $status;
		$this->before_version   = $before_version;
		$this->after_version    = $after_version;
		$this->effects          = $effects;
		$this->external_effects = $external_effects;
		$this->diagnostic       = $diagnostic;
	}

	/** Return JSON-safe receipt data. */
	public function data(): array {
		return array(
			'status'           => $this->status,
			'before_version'   => $this->before_version,
			'after_version'    => $this->after_version,
			'effects'          => $this->effects,
			'external_effects' => $this->external_effects,
			'diagnostic'       => $this->diagnostic,
		);
	}
}
