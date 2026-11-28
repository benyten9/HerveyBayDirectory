<?php
/**
 * One source-derived native component declaration.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Compact source contract value object.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Validates adapter output before it can participate in an effective catalog. */
final class ComponentDeclaration {
	private const OPERATIONS                 = array( 'inspect', 'insert_component', 'set_control', 'move_component', 'remove_component', 'bind_field', 'set_template_variant' );
	private const KEYS                       = array( 'source_provider_id', 'native_id', 'availability', 'document_kinds', 'attributes_schema', 'defaults', 'controls', 'slots', 'declared_context', 'semantic_rule_refs', 'resolver_refs', 'dependencies', 'directory_fields', 'source_fingerprint', 'provenance', 'features', 'unsupported_controls' );
	private const MAX_ATTRIBUTE_CONTRACTS    = 128;
	private const MAX_ATTRIBUTE_SCHEMA_BYTES = 1048576;
	/** Request-local pure schema parsers; never component values or authority. */
	private static array $attribute_contracts  = array();
	private static int $attribute_schema_bytes = 0;

	/** @var array<string,mixed> */
	private array $data;

	/**
	 * @param array<string,mixed> $data Strict source-reader output.
	 * @throws ContractException Invalid declaration.
	 */
	public function __construct( array $data ) {
		$keys = array_keys( $data );
		sort( $keys, SORT_STRING );
		$expected = self::KEYS;
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected || ! is_string( $data['source_provider_id'] ) || ! CatalogEnvironment::valid_id( $data['source_provider_id'] ) || ! is_string( $data['native_id'] ) || ! ProviderRegistration::valid_token( $data['native_id'] ) || ! in_array( $data['availability'], array( 'available', 'editor_only', 'unavailable' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Component declaration is invalid.' );
		}
		$data['document_kinds']       = ProviderRegistration::tokens( $data['document_kinds'], false );
		$data['slots']                = ProviderRegistration::tokens( $data['slots'] );
		$data['declared_context']     = ProviderRegistration::tokens( $data['declared_context'] );
		$data['semantic_rule_refs']   = ProviderRegistration::tokens( $data['semantic_rule_refs'] );
		$data['resolver_refs']        = ProviderRegistration::tokens( $data['resolver_refs'] );
		$data['directory_fields']     = ProviderRegistration::tokens( $data['directory_fields'] );
		$data['unsupported_controls'] = ProviderRegistration::tokens( $data['unsupported_controls'] );
		if ( ! $data['attributes_schema'] instanceof \stdClass || ! $data['defaults'] instanceof \stdClass || ! $data['controls'] instanceof \stdClass || ! is_string( $data['source_fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['source_fingerprint'] ) ) {
			throw new ContractException( 'unsupported_contract', 'Component source contract is invalid.' );
		}
		$decoder = new JsonDecoder( 524288, 24 );
		$decoder->check( $data['attributes_schema'] );
		$decoder->check( $data['defaults'] );
		$decoder->check( $data['controls'] );
		list( $schemas, $validator ) = self::attribute_contract( json_encode( $data['attributes_schema'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		$validator->validate( $data['defaults'], 'builder.component.attributes' );
		$data['attributes_schema'] = $schemas->get( 'builder.component.attributes' );
		$data['defaults']          = self::copy_json( $data['defaults'] );
		$data['controls']          = self::copy_json( $data['controls'] );
		$data['dependencies']      = $this->normalize_dependencies( $data['dependencies'] );
		$data['provenance']        = $this->normalize_provenance( $data['provenance'] );
		$data['features']          = $this->normalize_features( $data['features'], $data['document_kinds'] );
		$this->data                = $data;
	}

	/** Reuse only the parser for exact, freshly checked schema bytes. */
	private static function attribute_contract( string $json ): array {
		$key = hash( 'sha256', $json );
		if ( isset( self::$attribute_contracts[ $key ] ) ) {
			return self::$attribute_contracts[ $key ]['contract'];
		}
		$schemas = new SchemaRegistry();
		$schemas->register( 'builder.component.attributes', $json );
		$contract = array( $schemas, new ContractValidator( $schemas, new JsonDecoder( 524288, 24 ) ) );
		// Local references may expand a short source into a larger parser tree.
		// Bound that retained form as well as the original schema bytes.
		$bytes = strlen( $json ) + strlen( json_encode( $schemas->get( 'builder.component.attributes' ), JSON_THROW_ON_ERROR ) );
		if ( $bytes <= self::MAX_ATTRIBUTE_SCHEMA_BYTES ) {
			while ( self::$attribute_contracts && ( count( self::$attribute_contracts ) >= self::MAX_ATTRIBUTE_CONTRACTS || self::$attribute_schema_bytes + $bytes > self::MAX_ATTRIBUTE_SCHEMA_BYTES ) ) {
				$oldest                        = array_key_first( self::$attribute_contracts );
				self::$attribute_schema_bytes -= self::$attribute_contracts[ $oldest ]['bytes'];
				unset( self::$attribute_contracts[ $oldest ] );
			}
			self::$attribute_contracts[ $key ] = array(
				'contract' => $contract,
				'bytes'    => $bytes,
			);
			self::$attribute_schema_bytes     += $bytes;
		}
		return $contract;
	}

	/** @param mixed $value Valid JSON. */
	private static function copy_json( $value ) {
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 25, JSON_THROW_ON_ERROR );
	}

	/** @param mixed $dependencies Raw constraints. @return array<string,string> */
	private function normalize_dependencies( $dependencies ): array {
		if ( ! is_array( $dependencies ) || count( $dependencies ) > 50 ) {
			throw new ContractException( 'unsupported_contract', 'Component dependencies are invalid.' );
		}
		foreach ( $dependencies as $id => $constraint ) {
			if ( ! is_string( $id ) || ! CatalogEnvironment::valid_id( $id ) || ! is_string( $constraint ) ) {
				throw new ContractException( 'unsupported_contract', 'Component dependency is invalid.' );
			}
			new VersionConstraint( $constraint );
		}
		ksort( $dependencies, SORT_STRING );
		return $dependencies;
	}

	/** @param mixed $provenance Raw provenance. @return array<int,array{kind:string,source_ref:string}> */
	private function normalize_provenance( $provenance ): array {
		if ( ! is_array( $provenance ) || ! $provenance || count( $provenance ) > 50 ) {
			throw new ContractException( 'unsupported_contract', 'Component provenance is invalid.' );
		}
		$result = array();
		foreach ( $provenance as $item ) {
			if ( ! is_array( $item ) || array( 'kind', 'source_ref' ) !== array_keys( $item ) || ! is_string( $item['kind'] ) || ! ProviderRegistration::valid_token( $item['kind'] ) || ! is_string( $item['source_ref'] ) || ! ProviderRegistration::valid_token( $item['source_ref'] ) ) {
				throw new ContractException( 'unsupported_contract', 'Component provenance item is invalid.' );
			}
			$result[] = array(
				'kind'       => $item['kind'],
				'source_ref' => $item['source_ref'],
			);
		}
		usort( $result, static fn( array $left, array $right ): int => ( $left['kind'] . ':' . $left['source_ref'] ) <=> ( $right['kind'] . ':' . $right['source_ref'] ) );
		return array_values( array_unique( $result, SORT_REGULAR ) );
	}

	/** @param mixed $features Raw feature tuples. @param string[] $document_kinds Declared kinds. @return array<int,array{operation:string,document_kind:string,requires_editor:bool}> */
	private function normalize_features( $features, array $document_kinds ): array {
		if ( ! is_array( $features ) || count( $features ) > 100 ) {
			throw new ContractException( 'unsupported_contract', 'Component features are invalid.' );
		}
		$result = array();
		foreach ( $features as $feature ) {
			if ( ! is_array( $feature ) || array( 'operation', 'document_kind', 'requires_editor' ) !== array_keys( $feature ) || ! is_string( $feature['operation'] ) || ! in_array( $feature['operation'], self::OPERATIONS, true ) || ! is_string( $feature['document_kind'] ) || ! in_array( $feature['document_kind'], $document_kinds, true ) || ! is_bool( $feature['requires_editor'] ) ) {
				throw new ContractException( 'unsupported_contract', 'Component feature tuple is invalid.' );
			}
			$result[] = $feature;
		}
		usort( $result, static fn( array $left, array $right ): int => ( $left['document_kind'] . ':' . $left['operation'] . ':' . (int) $left['requires_editor'] ) <=> ( $right['document_kind'] . ':' . $right['operation'] . ':' . (int) $right['requires_editor'] ) );
		return array_values( array_unique( $result, SORT_REGULAR ) );
	}

	public function source_provider_id(): string {
		return $this->data['source_provider_id']; }
	public function native_id(): string {
		return $this->data['native_id']; }
	public function source_fingerprint(): string {
		return $this->data['source_fingerprint']; }
	public function availability(): string {
		return $this->data['availability']; }
	public function document_kinds(): array {
		return $this->data['document_kinds']; }
	public function dependencies(): array {
		return $this->data['dependencies']; }
	public function directory_fields(): array {
		return $this->data['directory_fields']; }
	public function unsupported_controls(): array {
		return $this->data['unsupported_controls']; }
	public function features(): array {
		return $this->data['features']; }

	/** Return a detached base descriptor without adapter-owned effective state. */
	public function descriptor(): array {
		return array_diff_key( $this->data, array_flip( array( 'features', 'unsupported_controls', 'directory_fields' ) ) );
	}
}
