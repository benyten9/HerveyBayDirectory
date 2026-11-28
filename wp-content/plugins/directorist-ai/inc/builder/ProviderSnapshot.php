<?php
/**
 * Bounded effective source snapshot from one integration provider.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Compact source snapshot value object.

use DirectoristAIPro\Validation\ContractException;

/** Carries runtime source observations without granting availability. */
final class ProviderSnapshot {
	private string $native_version;
	private string $native_generation;
	private string $completeness;
	/** @var array<string,string> */
	private array $observed_versions;
	private string $source_fingerprint;
	/** @var ComponentDeclaration[] */
	private array $components;
	/** @var array<int,array{code:string,native_id:?string}> */
	private array $diagnostics;
	/** @var array<string,bool> */
	private array $write_blocks = array();

	/**
	 * @param string                 $native_version Observed native builder version.
	 * @param string                 $native_generation Observed native generation.
	 * @param string                 $completeness Complete, partial or unknown source state.
	 * @param array<string,string>   $observed_versions Source-observed dependency versions.
	 * @param string                 $source_fingerprint Snapshot fingerprint.
	 * @param ComponentDeclaration[] $components Effective component outputs.
	 * @param array                  $diagnostics Safe reader diagnostics.
	 */
	public function __construct( string $native_version, string $native_generation, string $completeness, array $observed_versions, string $source_fingerprint, array $components, array $diagnostics = array() ) {
		if ( ! preg_match( '/^[0-9][0-9A-Za-z.+-]{0,63}$/D', $native_version ) || ! ProviderRegistration::valid_token( $native_generation ) || ! in_array( $completeness, array( 'complete', 'partial', 'unknown' ), true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $source_fingerprint ) || count( $components ) > 500 || count( $diagnostics ) > 200 ) {
			throw new ContractException( 'unsupported_contract', 'Provider snapshot is invalid.' );
		}
		foreach ( $observed_versions as $id => $version ) {
			if ( ! is_string( $id ) || ! CatalogEnvironment::valid_id( $id ) || ! is_string( $version ) || ! preg_match( '/^[0-9][0-9A-Za-z.+-]{0,63}$/D', $version ) ) {
				throw new ContractException( 'unsupported_contract', 'Provider version evidence is invalid.' );
			}
		}
		foreach ( $components as $component ) {
			if ( ! $component instanceof ComponentDeclaration ) {
				throw new ContractException( 'unsupported_contract', 'Provider component output is invalid.' );
			}
		}
		$normalized = array();
		foreach ( $diagnostics as $diagnostic ) {
			$blocking = array( 'component_incomplete', 'control_mapping_incomplete', 'context_unresolved' );
			if ( ! is_array( $diagnostic ) || array( 'code', 'native_id' ) !== array_keys( $diagnostic ) || ! is_string( $diagnostic['code'] ) || ! in_array( $diagnostic['code'], array_merge( array( 'source_notice' ), $blocking ), true ) || ( null !== $diagnostic['native_id'] && ( ! is_string( $diagnostic['native_id'] ) || ! ProviderRegistration::valid_token( $diagnostic['native_id'] ) ) ) || ( in_array( $diagnostic['code'], $blocking, true ) && null === $diagnostic['native_id'] ) ) {
				throw new ContractException( 'unsupported_contract', 'Provider diagnostic is invalid.' );
			}
			$normalized[] = $diagnostic;
			if ( in_array( $diagnostic['code'], $blocking, true ) ) {
				$this->write_blocks[ $diagnostic['native_id'] ] = true;
			}
		}
		ksort( $observed_versions, SORT_STRING );
		$this->native_version     = $native_version;
		$this->native_generation  = $native_generation;
		$this->completeness       = $completeness;
		$this->observed_versions  = $observed_versions;
		$this->source_fingerprint = $source_fingerprint;
		$this->components         = array_values( $components );
		$this->diagnostics        = $normalized;
	}

	public function native_version(): string {
		return $this->native_version; }
	public function native_generation(): string {
		return $this->native_generation; }
	public function completeness(): string {
		return $this->completeness; }
	public function observed_versions(): array {
		return $this->observed_versions; }
	public function source_fingerprint(): string {
		return $this->source_fingerprint; }
	public function components(): array {
		return $this->components; }
	public function diagnostics(): array {
		return $this->diagnostics; }
	public function blocks_writes( string $native_id ): bool {
		return isset( $this->write_blocks[ $native_id ] ); }
}
