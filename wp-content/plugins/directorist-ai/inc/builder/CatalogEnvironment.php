<?php
/**
 * Trusted installed-version evidence for catalog compilation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment -- Compact trusted environment value object.

use DirectoristAIPro\Validation\ContractException;

/** Keeps runtime version facts separate from provider declarations. */
final class CatalogEnvironment {
	/** @var array<string,string> */
	private array $versions;

	/**
	 * Bind versions observed by trusted WordPress bootstrap code.
	 *
	 * @param array<string,string> $versions Dependency ID to installed version.
	 * @throws ContractException Invalid environment.
	 */
	public function __construct( array $versions ) {
		if ( ! $versions || count( $versions ) > 100 ) {
			throw new ContractException( 'unsupported_contract', 'Catalog environment is invalid.' );
		}
		foreach ( $versions as $id => $version ) {
			if ( ! self::valid_id( $id ) || ! is_string( $version ) || ! preg_match( '/^[0-9][0-9A-Za-z.+-]{0,63}$/D', $version ) ) {
				throw new ContractException( 'unsupported_contract', 'Catalog environment contains invalid version evidence.' );
			}
		}
		ksort( $versions, SORT_STRING );
		$this->versions = $versions;
	}

	/** Return an installed version, or null when the dependency is absent. */
	public function version( string $id ): ?string {
		return $this->versions[ $id ] ?? null;
	}

	/** Return a detached deterministic version map. */
	public function versions(): array {
		return $this->versions;
	}

	/** Hash exact installed-version evidence. */
	public function fingerprint(): string {
		return hash( 'sha256', json_encode( $this->versions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}

	/** Validate stable local dependency/provider identities. */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._\/-]{0,127}$/D', $id );
	}
}
