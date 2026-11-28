<?php
/**
 * One trusted definition per operation identity.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Capabilities;

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Registry mutation is server bootstrap work, never an exposed tool. */
final class Registry {
	/**
	 * Immutable schema registry.
	 *
	 * @var SchemaRegistry
	 */
	private SchemaRegistry $schemas;
	/**
	 * Trusted operation definitions.
	 *
	 * @var array
	 */
	private array $operations = array();

	/**
	 * Bind schemas used by operation definitions.
	 *
	 * @param SchemaRegistry $schemas Trusted schema registry.
	 */
	public function __construct( SchemaRegistry $schemas ) {
		$this->schemas = $schemas;
	}

	/**
	 * Register a unique operation with known object-shaped input/output schemas.
	 *
	 * @param Operation $operation Reviewed definition.
	 * @return void
	 * @throws ContractException Duplicate or invalid registration.
	 */
	public function register( Operation $operation ): void {
		$metadata = $operation->metadata();
		$id       = $metadata['id'];
		if ( isset( $this->operations[ $id ] ) ) {
			throw new ContractException( 'unsupported_contract', 'Operation identity is already registered.' );
		}
		foreach ( array( 'input_schema', 'output_schema' ) as $key ) {
			$schema = $this->schemas->get( $metadata[ $key ] );
			if ( ! $schema instanceof \stdClass || ( $schema->type ?? null ) !== 'object' ) {
				throw new ContractException( 'unsupported_contract', 'Operation envelopes require object schemas.' );
			}
		}
		$this->operations[ $id ] = $operation;
	}

	/**
	 * Resolve an exact registered identity, never a callable string.
	 *
	 * @param string $id Operation identity.
	 * @return Operation
	 * @throws ContractException Unknown operation.
	 */
	public function get( string $id ): Operation {
		if ( ! isset( $this->operations[ $id ] ) ) {
			throw new ContractException( 'unsupported_operation', 'Operation is unavailable.' );
		}
		return $this->operations[ $id ];
	}

	/**
	 * Return definitions to trusted bootstrap/bridge code.
	 *
	 * @return array
	 */
	public function all(): array {
		return $this->operations;
	}

	/**
	 * Return a detached schema to a trusted descriptor publisher.
	 *
	 * @param string $id Registered schema identity.
	 * @return object|bool
	 */
	public function schema( string $id ) {
		return $this->schemas->get( $id );
	}
}
