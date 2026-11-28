<?php
/**
 * Exact native/editor/environment fence for one extracted graph.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Compact immutable graph identity.

use DirectoristAIPro\Validation\ContractException;

/** Saved and dirty-editor snapshots are distinct authority states. */
final class GraphVersion {
	/** @var array<string,mixed> */
	private array $data;

	/** @param array<string,mixed> $data Trusted adapter observation. */
	public function __construct( array $data ) {
		$required = array( 'source_hash', 'editor_epoch', 'editor_revision', 'environment_hash', 'directory_schema_hash', 'adapter_revision', 'normalization_revision' );
		$keys     = array_keys( $data );
		sort( $required, SORT_STRING );
		sort( $keys, SORT_STRING );
		if ( $keys !== $required || ( null === $data['editor_epoch'] ) !== ( null === $data['editor_revision'] ) ) {
			throw new ContractException( 'unsupported_contract', 'Graph version shape is invalid.' );
		}
		foreach ( array( 'source_hash', 'environment_hash', 'directory_schema_hash' ) as $field ) {
			if ( ! is_string( $data[ $field ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data[ $field ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Graph version hash is invalid.' );
			}
		}
		foreach ( array( 'adapter_revision', 'normalization_revision' ) as $field ) {
			self::identifier( $data[ $field ] );
		}
		if ( null !== $data['editor_epoch'] ) {
			self::identifier( $data['editor_epoch'] );
			self::identifier( $data['editor_revision'] );
		}
		ksort( $data, SORT_STRING );
		$this->data = $data;
	}

	public function hash(): string {
		return hash( 'sha256', json_encode( $this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public function source_hash(): string {
		return $this->data['source_hash']; }
	public function editor_epoch(): ?string {
		return $this->data['editor_epoch']; }
	public function editor_revision(): ?string {
		return $this->data['editor_revision']; }
	public function environment_hash(): string {
		return $this->data['environment_hash']; }
	public function directory_schema_hash(): string {
		return $this->data['directory_schema_hash']; }
	public function adapter_revision(): string {
		return $this->data['adapter_revision']; }
	public function normalization_revision(): string {
		return $this->data['normalization_revision']; }
	public function editor_bound(): bool {
		return null !== $this->data['editor_epoch']; }
	public function value(): object {
		return (object) $this->data; }
	public function json(): string {
		return json_encode( $this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ); }

	/** Validate an opaque identity used in persisted graph coordinates. */
	public static function identifier( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9_.:/-]{0,127}$#D', $value ) ) {
			throw new ContractException( 'unsupported_contract', 'Graph identity is invalid.' );
		}
		return $value;
	}
}
