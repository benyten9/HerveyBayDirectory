<?php
/**
 * Strict effective-catalog schema, canonical encoding and revision ownership.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact closed output schema.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Compiler, runtime gateway and cache validate the same closed contract. */
final class EffectiveCatalogContract {
	private ContractValidator $validator;
	private string $normalization_revision;

	public function __construct( string $normalization_revision = '2' ) {
		if ( ! ProviderRegistration::valid_token( $normalization_revision ) ) {
			throw new ContractException( 'unsupported_contract', 'Catalog normalization revision is invalid.' );
		}
		$this->normalization_revision = $normalization_revision;
		$schemas = new SchemaRegistry();
		$schemas->register( 'builder.effective-catalog', json_encode( $this->schema(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
		$this->validator = new ContractValidator( $schemas, new JsonDecoder( 5242880, 32 ) );
	}

	/** Hash the actual closed schema together with canonicalization semantics. */
	public function fingerprint(): string {
		return hash( 'sha256', json_encode( $this->canonical( $this->schema() ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) . ':' . $this->normalization_revision );
	}

	/** Canonicalize, assign the only valid revision and validate the result. */
	public function finalize( array $payload ): object {
		unset( $payload['revision'] );
		$payload             = $this->canonical( $payload );
		$payload['revision'] = $this->revision_for( $payload, true );
		$catalog             = json_decode( json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 64, JSON_THROW_ON_ERROR );
		// These bytes and their revision were produced together above. Still
		// validate the closed output, without sorting the same native metadata
		// twice more merely to recompute the revision we just assigned.
		$this->validator->validate( $catalog, 'builder.effective-catalog' );
		return $catalog;
	}

	/** Strictly validate structure and recomputed semantic revision. */
	public function validate( object $catalog ): object {
		$this->validator->validate( $catalog, 'builder.effective-catalog' );
		if ( ! hash_equals( $catalog->revision, $this->revision_for( $catalog ) ) ) {
			throw new ContractException( 'invalid_output', 'Effective catalog revision is invalid.' );
		}
		return $catalog;
	}

	/** Return exact canonical bytes after strict validation. */
	public function encode( object $catalog ): string {
		$this->validator->validate( $catalog, 'builder.effective-catalog' );
		$canonical = $this->canonical( $catalog );
		if ( ! hash_equals( $catalog->revision, $this->revision_for( $canonical, true ) ) ) {
			throw new ContractException( 'invalid_output', 'Effective catalog revision is invalid.' );
		}
		return json_encode( $canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}

	/** Public schema used by the guarded operation output validator. */
	public function schema(): array {
		$token          = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 256 );
		$hash           = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		$nullable_token = array( 'type' => array( 'string', 'null' ), 'maxLength' => 256 );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'contract'                => array( 'const' => 'directorist-ai/effective-catalog-v1' ),
				'builder'                 => array( 'enum' => array( 'gutenberg', 'elementor', 'divi' ) ),
				'document_kind'           => $token,
				'directory_id'            => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ),
				'directory_revision'      => array( 'type' => array( 'string', 'null' ), 'pattern' => '^[a-f0-9]{64}$' ),
				'editor_fingerprint'      => array( 'type' => array( 'string', 'null' ), 'pattern' => '^[a-f0-9]{64}$' ),
				'environment_fingerprint' => $hash,
				'providers'               => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'object', 'properties' => array( 'reader_id' => $token, 'adapter_id' => $token, 'adapter_revision' => $token, 'native_generation' => $token, 'status' => array( 'enum' => array( 'available', 'missing', 'unsupported', 'failed', 'conflict', 'incomplete' ) ), 'source_fingerprint' => array( 'type' => array( 'string', 'null' ), 'pattern' => '^[a-f0-9]{64}$' ) ), 'required' => array( 'reader_id', 'adapter_id', 'adapter_revision', 'native_generation', 'status', 'source_fingerprint' ), 'additionalProperties' => false ) ),
				'components'              => array( 'type' => 'array', 'maxItems' => 500, 'items' => $this->component_schema( $token, $hash, $nullable_token ) ),
				'diagnostics'             => array( 'type' => 'array', 'maxItems' => 500, 'items' => array( 'type' => 'object', 'properties' => array( 'code' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$' ), 'reader_id' => $nullable_token, 'native_id' => $nullable_token, 'message' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 256 ) ), 'required' => array( 'code', 'reader_id', 'native_id', 'message' ), 'additionalProperties' => false ) ),
				'revision'                => $hash,
			),
			'required'             => array( 'contract', 'builder', 'document_kind', 'directory_id', 'directory_revision', 'editor_fingerprint', 'environment_fingerprint', 'providers', 'components', 'diagnostics', 'revision' ),
			'additionalProperties' => false,
		);
	}

	private function component_schema( array $token, array $hash, array $nullable_token ): array {
		$list = array( 'type' => 'array', 'maxItems' => 100, 'items' => $token, 'uniqueItems' => true );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_provider_id' => $token,
				'native_id'          => $token,
				'adapter_id'         => $token,
				'adapter_revision'   => $token,
				'native_version'     => $token,
				'availability'       => array( 'enum' => array( 'available', 'read_only', 'unavailable', 'unsupported', 'conflict' ) ),
				'document_kinds'     => $list,
				'attributes_schema'  => array( 'type' => 'object' ),
				'defaults'           => array( 'type' => 'object' ),
				'controls'           => array( 'type' => 'object' ),
				'slots'              => $list,
				'declared_context'   => $list,
				'semantic_rule_refs' => $list,
				'resolver_refs'      => $list,
				'dependencies'       => array( 'type' => 'array', 'maxItems' => 150, 'items' => array( 'type' => 'object', 'properties' => array( 'type' => array( 'enum' => array( 'plugin', 'directory_field' ) ), 'id' => $token, 'constraint' => $nullable_token, 'version' => $nullable_token, 'status' => array( 'enum' => array( 'available', 'missing', 'unsupported' ) ) ), 'required' => array( 'type', 'id', 'constraint', 'version', 'status' ), 'additionalProperties' => false ) ),
				'source_fingerprint' => $hash,
				'provenance'         => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => array( 'type' => 'object', 'properties' => array( 'kind' => $token, 'source_ref' => $token ), 'required' => array( 'kind', 'source_ref' ), 'additionalProperties' => false ) ),
				'features'           => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'object', 'properties' => array( 'operation' => array( 'enum' => array( 'inspect', 'insert_component', 'set_control', 'move_component', 'remove_component', 'bind_field', 'set_template_variant' ) ), 'document_kind' => $token, 'requires_editor' => array( 'type' => 'boolean' ) ), 'required' => array( 'operation', 'document_kind', 'requires_editor' ), 'additionalProperties' => false ) ),
			),
			'required'             => array( 'source_provider_id', 'native_id', 'adapter_id', 'adapter_revision', 'native_version', 'availability', 'document_kinds', 'attributes_schema', 'defaults', 'controls', 'slots', 'declared_context', 'semantic_rule_refs', 'resolver_refs', 'dependencies', 'source_fingerprint', 'provenance', 'features' ),
			'additionalProperties' => false,
		);
	}

	/** Compute revision from canonical payload excluding the revision itself. */
	private function revision_for( $catalog, bool $already_canonical = false ): string {
		$data = $catalog instanceof \stdClass ? get_object_vars( $catalog ) : $catalog;
		unset( $data['revision'] );
		return hash( 'sha256', json_encode( $already_canonical ? $data : $this->canonical( $data ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	private function canonical( $value ) {
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			ksort( $properties, SORT_STRING );
			foreach ( $properties as $key => $child ) {
				$properties[ $key ] = $this->canonical( $child );
			}
			return (object) $properties;
		}
		if ( is_array( $value ) ) {
			$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $child ) {
				$value[ $key ] = $this->canonical( $child );
			}
		}
		return $value;
	}
}
