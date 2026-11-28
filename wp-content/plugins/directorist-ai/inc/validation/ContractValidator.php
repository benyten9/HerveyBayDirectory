<?php
/**
 * Strict validation through a private, standards-only schema engine.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Validation;

use DirectoristAIPro\Dependencies\Opis\JsonSchema\CompliantValidator;
use DirectoristAIPro\Dependencies\Opis\JsonSchema\SchemaLoader;

/**
 * Pure validation service, independent of permissive transport validation.
 */
final class ContractValidator {
	/**
	 * Trusted schema registry.
	 *
	 * @var SchemaRegistry
	 */
	private SchemaRegistry $registry;
	/**
	 * Bounded decoder.
	 *
	 * @var JsonDecoder
	 */
	private JsonDecoder $decoder;
	/**
	 * Private standards-only validator.
	 *
	 * @var CompliantValidator
	 */
	private CompliantValidator $validator;

	/**
	 * Private detached immutable schemas, reused by the parser by object identity.
	 *
	 * @var array<string,object|bool>
	 */
	private array $schemas = array();

	/**
	 * Build a validator without any file or network schema resolver.
	 *
	 * @param SchemaRegistry   $registry Trusted schemas.
	 * @param JsonDecoder|null $decoder Optional tighter input bounds.
	 */
	public function __construct( SchemaRegistry $registry, ?JsonDecoder $decoder = null ) {
		$this->registry  = $registry;
		$this->decoder   = $decoder ?? new JsonDecoder();
		$this->validator = new CompliantValidator( new SchemaLoader( null, null ), 1, true );
	}

	/**
	 * Decode and validate original transport input without coercion.
	 *
	 * @param string $json Original JSON bytes.
	 * @param string $schema_id Trusted schema identity.
	 * @return mixed Validated value.
	 */
	public function decode( string $json, string $schema_id ) {
		return $this->validate( $this->decoder->decode( $json ), $schema_id );
	}

	/**
	 * Validate an already typed PHP/ability value.
	 *
	 * @param mixed  $value Original typed value.
	 * @param string $schema_id Trusted schema identity.
	 * @return mixed Validated original value, without added defaults/coercion.
	 * @throws ContractException Rejected value or unavailable schema.
	 */
	public function validate( $value, string $schema_id ) {
		$this->decoder->check( $value );
		// IDs cannot be registered twice. Keep one private detached object per ID
		// instead of leaking a new parsed-schema pointer on every validation.
		if ( ! array_key_exists( $schema_id, $this->schemas ) ) {
			$this->schemas[ $schema_id ] = $this->registry->get( $schema_id );
		}
		$schema = $this->schemas[ $schema_id ];
		try {
			$error = $this->validator->dataValidation( $value, $schema, null, null, null, '07' );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'unsupported_contract', 'Schema could not be evaluated.' );
		}
		if ( null !== $error ) {
			throw new ContractException( 'invalid_input', 'Input does not satisfy the registered contract.' );
		}
		return $value;
	}
}
