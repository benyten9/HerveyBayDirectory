<?php
/**
 * One exact versioned native effect target.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Effects;

use DirectoristAIPro\Validation\ContractException;

/** Target keys are logical adapter identities, never SQL/table notation. */
final class EffectTarget {
	/**
	 * Logical adapter target key.
	 *
	 * @var string
	 */
	public string $key;
	/**
	 * Exact native version expected at commit.
	 *
	 * @var string
	 */
	public string $expected_version;
	/**
	 * Hash of the reviewed target effect.
	 *
	 * @var string
	 */
	public string $effect_hash;

	/**
	 * Build a reviewed target precondition.
	 *
	 * @param string $key Logical adapter target key.
	 * @param string $expected_version Exact native version expected at commit.
	 * @param string $effect_hash Hash of the reviewed target effect.
	 * @throws ContractException Invalid target contract.
	 */
	public function __construct( string $key, string $expected_version, string $effect_hash ) {
		if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,31}:[a-zA-Z0-9_.:-]{1,95}$/D', $key ) || '' === $expected_version || strlen( $expected_version ) > 128 || ! preg_match( '/^[a-f0-9]{64}$/D', $effect_hash ) ) {
			throw new ContractException( 'invalid_input', 'Invalid effect target.' );
		}
		$this->key              = $key;
		$this->expected_version = $expected_version;
		$this->effect_hash      = $effect_hash;
	}

	/** Return the immutable receipt representation. */
	public function data(): array {
		return array(
			'key'              => $this->key,
			'expected_version' => $this->expected_version,
			'effect_hash'      => $this->effect_hash,
		);
	}
}
