<?php
/**
 * Trusted builder integration registration registry.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Compact trusted registry.

/** Registration is bootstrap-only; one authoritative adapter snapshot owns each builder. */
final class IntegrationRegistry {
	/** @var array<string,array{provider:CatalogProvider,registration:ProviderRegistration}> */
	private array $providers = array();
	/** @var array<string,string> */
	private array $adapter_owners = array();
	/** @var array<int,array{code:string,builder:string,reader_id:string,adapter_id:string}> */
	private array $conflicts = array();
	private bool $frozen     = false;

	/** Register one unique provider and adapter identity. */
	public function register( CatalogProvider $provider ): void {
		try {
			$registration = $provider->registration();
		} catch ( \Throwable $error ) {
			$this->conflicts[] = array(
				'code'       => 'invalid_registration',
				'builder'    => '*',
				'reader_id'  => 'invalid-reader',
				'adapter_id' => 'unknown-adapter',
			);
			return;
		}
		$id          = $registration->reader_id();
		$adapter_key = $registration->builder() . ':' . $registration->adapter_id();
		if ( $this->frozen || isset( $this->providers[ $id ] ) || isset( $this->adapter_owners[ $adapter_key ] ) ) {
			$this->conflicts[] = array(
				'code'       => $this->frozen ? 'late_registration' : 'registration_identity_conflict',
				'builder'    => $registration->builder(),
				'reader_id'  => $id,
				'adapter_id' => $registration->adapter_id(),
			);
			return;
		}
		$this->providers[ $id ]               = array(
			'provider'     => $provider,
			'registration' => $registration,
		);
		$this->adapter_owners[ $adapter_key ] = $id;
	}

	/** Return frozen provider registrations in stable registration-ID order. */
	public function for_builder( string $builder ): array {
		$this->freeze();
		$providers = array_filter(
			$this->providers,
			static fn( array $provider ): bool => $builder === $provider['registration']->builder()
		);
		ksort( $providers, SORT_STRING );
		return array_values( $providers );
	}

	/** Freeze after the one trusted registration action completes. */
	public function freeze(): void {
		$this->frozen = true;
	}

	/** Return deterministic conflicts without executing a rejected provider. */
	public function conflicts( string $builder ): array {
		$conflicts = array_values( array_filter( $this->conflicts, static fn( array $conflict ): bool => '*' === $conflict['builder'] || $builder === $conflict['builder'] ) );
		usort( $conflicts, static fn( array $left, array $right ): int => ( $left['code'] . ':' . $left['reader_id'] . ':' . $left['adapter_id'] ) <=> ( $right['code'] . ':' . $right['reader_id'] . ':' . $right['adapter_id'] ) );
		return $conflicts;
	}

	/** Return only version IDs required by frozen registrations. @return string[] */
	public function dependency_ids(): array {
		$ids = array( 'wordpress' );
		foreach ( $this->providers as $registered ) {
			$registration = $registered['registration'];
			$ids[]        = $registration->native_dependency();
			$ids          = array_merge( $ids, array_keys( $registration->requirements() ) );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_STRING );
		return $ids;
	}

	/**
	 * Read only cheap context fingerprints; full source readers remain untouched.
	 *
	 * @return array{native:string,integration:string,rules:string,design:string,cacheable:bool,dynamic_reasons:string[]}
	 */
	public function cache_fingerprints( string $builder, string $document_kind, ?string $editor_fingerprint, CatalogEnvironment $environment, ?object $directory_facts, ?EditorBinding $editor_binding = null ): array {
		$native      = array();
		$integration = array();
		$rules       = array();
		$design      = array();
		$cacheable   = true;
		$dynamic     = array();
		foreach ( $this->for_builder( $builder ) as $registered ) {
			$registration = $registered['registration'];
			$provider     = $registered['provider'];
			$descriptor   = $registration->cache_descriptor();
			$native[]     = array(
				'reader'     => $registration->reader_id(),
				'dependency' => $registration->native_dependency(),
				'version'    => $environment->version( $registration->native_dependency() ),
				'generation' => $registration->native_generation(),
			);
			$versions     = array();
			foreach ( $registration->requirements() as $id => $constraint ) {
				$versions[ $id ] = array(
					'constraint' => $constraint,
					'version'    => $environment->version( $id ),
				);
			}
			$integration[] = array(
				'registration' => $descriptor,
				'versions'     => $versions,
			);
			$rules[]       = array(
				'reader'   => $registration->reader_id(),
				'adapter'  => $registration->adapter_id(),
				'revision' => $registration->adapter_revision(),
			);
			if ( ! $provider instanceof CatalogFingerprintProvider ) {
				$cacheable = false;
				$dynamic[] = 'reader_unkeyable:' . $registration->reader_id();
				continue;
			}
			try {
				$fingerprint = $provider instanceof EditorAwareCatalogFingerprintProvider && $editor_binding instanceof CatalogEditorSnapshot
					? $provider->cache_fingerprint_with_editor( $document_kind, $editor_binding, $environment, $directory_facts )
					: $provider->cache_fingerprint( $document_kind, $editor_fingerprint, $environment, $directory_facts );
			} catch ( \Throwable $error ) {
				$cacheable = false;
				$dynamic[] = 'fingerprint_unavailable:' . $registration->reader_id();
				continue;
			}
			$native[]      = array(
				'reader' => $registration->reader_id(),
				'source' => $fingerprint->native(),
			);
			$integration[] = array(
				'reader' => $registration->reader_id(),
				'source' => $fingerprint->integration(),
			);
			$rules[]       = array(
				'reader' => $registration->reader_id(),
				'source' => $fingerprint->rules(),
			);
			$design[]      = array(
				'reader' => $registration->reader_id(),
				'source' => $fingerprint->design(),
			);
			if ( ! $fingerprint->cacheable() ) {
				$cacheable = false;
				foreach ( $fingerprint->dynamic_reasons() as $reason ) {
					$dynamic[] = $registration->reader_id() . ':' . $reason;
				}
			}
		}
		foreach ( $this->conflicts( $builder ) as $conflict ) {
			$integration[] = array( 'conflict' => $conflict );
		}
		$dynamic = array_values( array_unique( $dynamic ) );
		sort( $dynamic, SORT_STRING );
		return array(
			'native'          => $this->fingerprint( $native ),
			'integration'     => $this->fingerprint( $integration ),
			'rules'           => $this->fingerprint( $rules ),
			'design'          => $this->fingerprint( $design ),
			'cacheable'       => $cacheable,
			'dynamic_reasons' => $dynamic,
		);
	}

	/** Hash canonical lists whose insertion order is already registration-sorted. */
	private function fingerprint( array $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
}
