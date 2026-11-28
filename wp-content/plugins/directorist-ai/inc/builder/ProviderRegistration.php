<?php
/**
 * Trusted integration-provider registration.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Compact registration value object.

use DirectoristAIPro\Validation\ContractException;

/** Declares when a source reader may run; it contains no component inventory. */
final class ProviderRegistration {
	private string $id;
	private string $adapter_id;
	private string $adapter_revision;
	private string $builder;
	private string $native_dependency;
	private string $native_generation;
	private string $native_constraint;
	/** @var array<string,string> */
	private array $requirements;
	/** @var string[] */
	private array $document_kinds;
	/** @var string[] */
	private array $source_providers;

	/**
	 * @param string               $id Provider registration identity.
	 * @param string               $adapter_id Adapter owner identity.
	 * @param string               $adapter_revision Adapter contract revision.
	 * @param string               $builder Builder family.
	 * @param string               $native_dependency Native builder dependency ID.
	 * @param string               $native_generation Supported native generation.
	 * @param string               $native_constraint Supported installed version range.
	 * @param array<string,string> $requirements Additional dependency ranges.
	 * @param string[]             $document_kinds Supported document kinds.
	 * @param string[]             $source_providers Allowed original source owners.
	 * @throws ContractException Invalid registration.
	 */
	public function __construct( string $id, string $adapter_id, string $adapter_revision, string $builder, string $native_dependency, string $native_generation, string $native_constraint, array $requirements, array $document_kinds, array $source_providers ) {
		if ( ! CatalogEnvironment::valid_id( $id ) || ! CatalogEnvironment::valid_id( $adapter_id ) || ! in_array( $builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! CatalogEnvironment::valid_id( $native_dependency ) || ! self::valid_token( $adapter_revision ) || ! self::valid_token( $native_generation ) ) {
			throw new ContractException( 'unsupported_contract', 'Provider registration is invalid.' );
		}
		new VersionConstraint( $native_constraint );
		$this->validate_requirements( $requirements );
		$document_kinds   = self::tokens( $document_kinds, false );
		$source_providers = self::dependency_ids( $source_providers, false );
		$core_source      = 'gutenberg' === $builder ? 'wordpress/core' : $builder . '/core';
		foreach ( $source_providers as $source_provider ) {
			if ( $core_source !== $source_provider && $native_dependency !== $source_provider && ! array_key_exists( $source_provider, $requirements ) ) {
				throw new ContractException( 'unsupported_contract', 'Source provider lacks a negotiated dependency.' );
			}
		}
		$this->id                = $id;
		$this->adapter_id        = $adapter_id;
		$this->adapter_revision  = $adapter_revision;
		$this->builder           = $builder;
		$this->native_dependency = $native_dependency;
		$this->native_generation = $native_generation;
		$this->native_constraint = $native_constraint;
		$this->requirements      = $requirements;
		$this->document_kinds    = $document_kinds;
		$this->source_providers  = $source_providers;
	}

	/** @param array<string,string> $requirements Dependency constraints. */
	private function validate_requirements( array &$requirements ): void {
		if ( count( $requirements ) > 50 ) {
			throw new ContractException( 'unsupported_contract', 'Provider dependency list is too large.' );
		}
		foreach ( $requirements as $id => $constraint ) {
			if ( ! CatalogEnvironment::valid_id( $id ) || ! is_string( $constraint ) ) {
				throw new ContractException( 'unsupported_contract', 'Provider dependency is invalid.' );
			}
			new VersionConstraint( $constraint );
		}
		ksort( $requirements, SORT_STRING );
	}

	/** Validate and sort a bounded token list. @return string[] */
	public static function tokens( array $values, bool $allow_empty = true ): array {
		if ( ( ! $allow_empty && ! $values ) || count( $values ) > 100 ) {
			throw new ContractException( 'unsupported_contract', 'Provider token list is invalid.' );
		}
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! self::valid_token( $value ) ) {
				throw new ContractException( 'unsupported_contract', 'Provider token is invalid.' );
			}
		}
		$values = array_values( array_unique( $values ) );
		sort( $values, SORT_STRING );
		return $values;
	}

	/** Validate and sort source/dependency identities. @return string[] */
	private static function dependency_ids( array $values, bool $allow_empty = true ): array {
		if ( ( ! $allow_empty && ! $values ) || count( $values ) > 100 ) {
			throw new ContractException( 'unsupported_contract', 'Provider identity list is invalid.' );
		}
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! CatalogEnvironment::valid_id( $value ) ) {
				throw new ContractException( 'unsupported_contract', 'Provider identity is invalid.' );
			}
		}
		$values = array_values( array_unique( $values ) );
		sort( $values, SORT_STRING );
		return $values;
	}

	/** Validate compact contract tokens and native identities. */
	public static function valid_token( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/\[\]-]{0,255}$/D', $value );
	}

	public function id(): string {
		return $this->id; }
	public function reader_id(): string {
		return $this->id; }
	public function adapter_id(): string {
		return $this->adapter_id; }
	public function adapter_revision(): string {
		return $this->adapter_revision; }
	public function builder(): string {
		return $this->builder; }
	public function native_dependency(): string {
		return $this->native_dependency; }
	public function native_generation(): string {
		return $this->native_generation; }
	public function native_constraint(): string {
		return $this->native_constraint; }
	public function requirements(): array {
		return $this->requirements; }
	public function document_kinds(): array {
		return $this->document_kinds; }
	public function source_providers(): array {
		return $this->source_providers; }
	public function allows_source( string $provider_id ): bool {
		return in_array( $provider_id, $this->source_providers, true ); }

	/** Return stable registration facts used only for cache invalidation. */
	public function cache_descriptor(): array {
		return array(
			'reader_id'         => $this->reader_id(),
			'adapter_id'        => $this->adapter_id,
			'adapter_revision'  => $this->adapter_revision,
			'builder'           => $this->builder,
			'native_dependency' => $this->native_dependency,
			'native_generation' => $this->native_generation,
			'native_constraint' => $this->native_constraint,
			'requirements'      => $this->requirements,
			'document_kinds'    => $this->document_kinds,
			'source_providers'  => $this->source_providers,
		);
	}
}
