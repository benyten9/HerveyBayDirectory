<?php
/**
 * Owner/run binding for one effective catalog generation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact immutable authority DTO.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** Immutable trusted wrapper around the closed effective-catalog contract. */
final class BuilderTaskContextCatalogSnapshot {
	private int $site_id;
	private string $owner_ref;
	private string $run_id;
	private int $generation;
	private object $catalog;
	private string $binding_revision;

	public function __construct( int $site_id, string $owner_ref, string $run_id, int $generation, object $catalog, ?EffectiveCatalogContract $contract = null ) {
		if ( $site_id < 1 || $generation < 1 ) {
			throw new ContractException( 'unsupported_contract', 'Task-context catalog authority is invalid.' );
		}
		GraphVersion::identifier( $owner_ref );
		GraphVersion::identifier( $run_id );
		$this->catalog          = ( $contract ?? new EffectiveCatalogContract() )->validate( $catalog );
		$this->site_id          = $site_id;
		$this->owner_ref        = $owner_ref;
		$this->run_id           = $run_id;
		$this->generation       = $generation;
		$this->binding_revision = hash(
			'sha256',
			( new JsonCanonicalizer() )->encode(
				(object) array(
					'site_id'          => $site_id,
					'owner_ref'        => $owner_ref,
					'run_id'           => $run_id,
					'generation'       => $generation,
					'catalog_revision' => $this->catalog->revision,
				)
			)
		);
	}

	public function site_id(): int {
		return $this->site_id;
	}
	public function owner_ref(): string {
		return $this->owner_ref;
	}
	public function run_id(): string {
		return $this->run_id;
	}
	public function generation(): int {
		return $this->generation;
	}
	public function catalog(): object {
		return json_decode( json_encode( $this->catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
	public function revision(): string {
		return $this->binding_revision;
	}
}
