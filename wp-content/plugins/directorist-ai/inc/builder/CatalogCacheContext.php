<?php
/**
 * Exact actor/context/source identity for one catalog cache generation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Compact immutable cache identity.

use DirectoristAIPro\Validation\ContractException;

/** Full fingerprints are hashes; raw private facts never become cache indexes. */
final class CatalogCacheContext {
	/** @var array<string,mixed> */
	private array $data;

	/** @param array<string,mixed> $data Trusted resolved context. */
	public function __construct( array $data ) {
		$required = array( 'site_id', 'scope_hash', 'builder', 'document_kind', 'directory_id', 'directory_revision', 'editor_fingerprint', 'native_fingerprint', 'integration_fingerprint', 'rule_fingerprint', 'schema_fingerprint', 'directory_fingerprint', 'design_fingerprint', 'invalidation_fingerprint', 'cacheable', 'dynamic_reasons' );
		$keys     = array_keys( $data );
		sort( $required, SORT_STRING );
		sort( $keys, SORT_STRING );
		if ( $keys !== $required || ! is_int( $data['site_id'] ) || $data['site_id'] < 1 || ! in_array( $data['builder'], array( 'gutenberg', 'elementor', 'divi' ), true ) || ! ProviderRegistration::valid_token( $data['document_kind'] ) || ( null !== $data['directory_id'] && ( ! is_int( $data['directory_id'] ) || $data['directory_id'] < 1 ) ) || ! is_bool( $data['cacheable'] ) ) {
			throw new ContractException( 'unsupported_contract', 'Catalog cache context is invalid.' );
		}
		foreach ( array( 'scope_hash', 'native_fingerprint', 'integration_fingerprint', 'rule_fingerprint', 'schema_fingerprint', 'directory_fingerprint', 'design_fingerprint', 'invalidation_fingerprint' ) as $field ) {
			if ( ! is_string( $data[ $field ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data[ $field ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Catalog cache fingerprint is invalid.' );
			}
		}
		if ( null !== $data['editor_fingerprint'] && ( ! is_string( $data['editor_fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['editor_fingerprint'] ) ) ) {
			throw new ContractException( 'unsupported_contract', 'Catalog editor fingerprint is invalid.' );
		}
		if ( null !== $data['directory_revision'] && ( ! is_string( $data['directory_revision'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['directory_revision'] ) ) ) {
			throw new ContractException( 'unsupported_contract', 'Catalog directory revision is invalid.' );
		}
		$data['dynamic_reasons'] = ProviderRegistration::tokens( $data['dynamic_reasons'] );
		if ( $data['cacheable'] && $data['dynamic_reasons'] ) {
			throw new ContractException( 'unsupported_contract', 'Cacheable context cannot declare dynamic reasons.' );
		}
		ksort( $data, SORT_STRING );
		$this->data = $data;
	}

	public function context_key(): string {
		return $this->hash(
			array(
				'site_id'       => $this->data['site_id'],
				'scope_hash'    => $this->data['scope_hash'],
				'builder'       => $this->data['builder'],
				'document_kind' => $this->data['document_kind'],
				'directory_id'  => $this->data['directory_id'],
				'editor_mode'   => null !== $this->data['editor_fingerprint'],
			)
		);
	}

	public function key_hash(): string {
		return $this->hash( $this->data );
	}

	public function scope_hash(): string {
		return $this->data['scope_hash']; }
	public function site_id(): int {
		return $this->data['site_id']; }
	public function builder(): string {
		return $this->data['builder']; }
	public function document_kind(): string {
		return $this->data['document_kind']; }
	public function directory_id(): ?int {
		return $this->data['directory_id']; }
	public function directory_fingerprint(): string {
		return $this->data['directory_fingerprint']; }
	public function directory_revision(): ?string {
		return $this->data['directory_revision']; }
	public function editor_fingerprint(): ?string {
		return $this->data['editor_fingerprint']; }
	public function invalidation_fingerprint(): string {
		return $this->data['invalidation_fingerprint']; }
	public function editor_mode(): bool {
		return null !== $this->data['editor_fingerprint']; }
	public function cacheable(): bool {
		return $this->data['cacheable']; }
	public function dynamic_reasons(): array {
		return $this->data['dynamic_reasons']; }

	/** Return non-secret fingerprint evidence for tests/diagnostics. */
	public function trace(): object {
		return (object) $this->data;
	}

	private function hash( array $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
}
