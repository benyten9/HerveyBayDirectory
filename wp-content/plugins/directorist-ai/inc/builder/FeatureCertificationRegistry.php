<?php
/**
 * Server-owned allowlist for certified builder feature handlers.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Compact trusted certification registry.

use DirectoristAIPro\Validation\ContractException;

/** Provider declarations cannot enable mutations without a matching certification. */
final class FeatureCertificationRegistry {
	/** @var array<string,bool> */
	private array $certifications = array();
	private bool $frozen          = false;

	/** Register one source/version/document/operation tuple from trusted bootstrap code. */
	public function certify( string $adapter_id, string $adapter_revision, string $source_provider_id, string $native_id, string $source_fingerprint, string $native_version, string $native_generation, string $document_kind, string $operation ): void {
		if ( $this->frozen ) {
			throw new ContractException( 'unsupported_contract', 'Builder feature certifications are frozen.' );
		}
		foreach ( array( $adapter_id, $adapter_revision, $source_provider_id, $native_id, $native_version, $native_generation, $document_kind, $operation ) as $token ) {
			if ( ! ProviderRegistration::valid_token( $token ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder feature certification is invalid.' );
			}
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $source_fingerprint ) || 'inspect' === $operation ) {
			throw new ContractException( 'unsupported_contract', 'Builder feature certification is invalid.' );
		}
		$key = implode( '|', array( $adapter_id, $adapter_revision, $source_provider_id, $native_id, $source_fingerprint, $native_version, $native_generation, $document_kind, $operation ) );
		if ( isset( $this->certifications[ $key ] ) ) {
			throw new ContractException( 'unsupported_contract', 'Builder feature certification is already registered.' );
		}
		$this->certifications[ $key ] = true;
	}

	/** Freeze after the one trusted certification action completes. */
	public function freeze(): void {
		$this->frozen = true;
	}

	/** Check an exact immutable tuple; provider claims alone never satisfy it. */
	public function allows( ProviderRegistration $registration, ProviderSnapshot $snapshot, ComponentDeclaration $component, array $feature ): bool {
		if ( 'inspect' === $feature['operation'] ) {
			return true;
		}
		$key = implode( '|', array( $registration->adapter_id(), $registration->adapter_revision(), $component->source_provider_id(), $component->native_id(), $component->source_fingerprint(), $snapshot->native_version(), $snapshot->native_generation(), $feature['document_kind'], $feature['operation'] ) );
		return isset( $this->certifications[ $key ] );
	}

	/** Hash the exact frozen mutation enablement set. */
	public function fingerprint(): string {
		$keys = array_keys( $this->certifications );
		sort( $keys, SORT_STRING );
		return hash( 'sha256', json_encode( $keys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}
}
