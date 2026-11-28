<?php
/**
 * Deterministic source-derived builder catalog compiler.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal compiler invariants are documented at each boundary.

use DirectoristAIPro\Validation\ContractException;

/** Resolves provider, dependency, context and identity conflicts fail-closed. */
final class EffectiveCatalogCompiler {
	private const WRITE_OPERATIONS = array( 'insert_component', 'set_control', 'move_component', 'remove_component', 'bind_field', 'set_template_variant' );

	private IntegrationRegistry $registry;
	private ?DirectoryFactsReader $directory_facts;
	private FeatureCertificationRegistry $certifications;
	private EffectiveCatalogContract $contract;

	public function __construct( IntegrationRegistry $registry, ?DirectoryFactsReader $directory_facts = null, ?FeatureCertificationRegistry $certifications = null, ?EffectiveCatalogContract $contract = null ) {
		$this->registry        = $registry;
		$this->directory_facts = $directory_facts;
		$this->certifications  = $certifications ?? new FeatureCertificationRegistry();
		$this->contract        = $contract ?? new EffectiveCatalogContract();
	}

	/** Compile one current, permission-filtered catalog without retaining it. */
	public function compile( CatalogRequest $request, CatalogEnvironment $environment ): object {
		$request->context()->assert_current();
		$editor_fingerprint = $this->editor_fingerprint( $request );
		$directory          = $this->directory( $request );
		$diagnostics        = array();
		$providers          = array();
		$components         = array();
		$registrations      = $this->registry->for_builder( $request->builder() );
		foreach ( $this->registry->conflicts( $request->builder() ) as $conflict ) {
			$diagnostics[] = $this->diagnostic( $conflict['code'], $conflict['reader_id'], null, 'Integration registration conflict was rejected during trusted bootstrap.' );
		}
		if ( ! $registrations ) {
			$diagnostics[] = $this->diagnostic( 'provider_missing', null, null, 'No compatible integration provider is registered.' );
		}

		foreach ( $registrations as $registered ) {
			$provider     = $registered['provider'];
			$registration = $registered['registration'];
			$state        = array(
				'reader_id'          => $registration->reader_id(),
				'adapter_id'         => $registration->adapter_id(),
				'adapter_revision'   => $registration->adapter_revision(),
				'native_generation'  => $registration->native_generation(),
				'status'             => 'available',
				'source_fingerprint' => null,
			);
			if ( ! in_array( $request->document_kind(), $registration->document_kinds(), true ) ) {
				$state['status'] = 'unsupported';
				$providers[]     = $state;
				$diagnostics[]   = $this->diagnostic( 'document_kind_unsupported', $registration->reader_id(), null, 'Provider does not support this document kind.' );
				continue;
			}
			$negotiation = $this->negotiate( $registration, $environment );
			if ( null !== $negotiation ) {
				$state['status'] = $negotiation['status'];
				$providers[]     = $state;
				$diagnostics[]   = $this->diagnostic( $negotiation['code'], $registration->reader_id(), null, $negotiation['message'] );
				continue;
			}
			try {
				$binding  = $request->editor_binding();
				$snapshot = $provider instanceof EditorAwareCatalogProvider && $binding instanceof CatalogEditorSnapshot
					? $provider->read_with_editor( $request->document_kind(), $binding, $environment, $this->copy( $directory ) )
					: $provider->read( $request->document_kind(), $editor_fingerprint, $environment, $this->copy( $directory ) );
			} catch ( \Throwable $error ) {
				$state['status'] = 'failed';
				$providers[]     = $state;
				$diagnostics[]   = $this->diagnostic( 'provider_read_failed', $registration->reader_id(), null, 'Integration provider could not produce a valid snapshot.' );
				continue;
			}
			$state['source_fingerprint'] = $snapshot->source_fingerprint();
			$version_conflict            = $this->version_conflict( $registration, $snapshot, $environment );
			$incomplete                  = 'complete' !== $snapshot->completeness();
			$source_conflict             = false;
			if ( $version_conflict ) {
				$state['status'] = 'conflict';
				$diagnostics[]   = $this->diagnostic( 'version_evidence_conflict', $registration->reader_id(), null, 'Runtime and source version evidence differ.' );
			}
			if ( $incomplete ) {
				$state['status'] = 'incomplete';
				$diagnostics[]   = $this->diagnostic( 'provider_snapshot_incomplete', $registration->reader_id(), null, 'Incomplete or unknown source state keeps mutation features unavailable.' );
			}
			foreach ( $snapshot->diagnostics() as $item ) {
				$diagnostics[] = $this->diagnostic( $item['code'], $registration->reader_id(), $item['native_id'], $this->provider_diagnostic_message( $item['code'] ) );
			}
			foreach ( $snapshot->components() as $component ) {
				if ( ! $registration->allows_source( $component->source_provider_id() ) ) {
					$source_conflict = true;
					$diagnostics[]   = $this->diagnostic( 'source_provider_untrusted', $registration->reader_id(), $component->native_id(), 'Component source provider is not allowed by the frozen registration.' );
					continue;
				}
				if ( ! in_array( $request->document_kind(), $component->document_kinds(), true ) ) {
					continue;
				}
				$result       = $this->component( $registration, $snapshot, $component, $request, $environment, $directory, $editor_fingerprint, $version_conflict, $incomplete || $snapshot->blocks_writes( $component->native_id() ) );
				$components[] = $result['descriptor'];
				$diagnostics  = array_merge( $diagnostics, $result['diagnostics'] );
			}
			if ( $source_conflict ) {
				$state['status'] = 'conflict';
			}
			$providers[] = $state;
		}

		$resolved    = $this->resolve_conflicts( $components, $diagnostics, $providers );
		$components  = $resolved['components'];
		$diagnostics = $resolved['diagnostics'];
		$providers   = $resolved['providers'];
		usort( $providers, static fn( array $left, array $right ): int => $left['reader_id'] <=> $right['reader_id'] );
		usort( $components, fn( array $left, array $right ): int => $this->descriptor_key( $left ) <=> $this->descriptor_key( $right ) );
		usort( $diagnostics, static fn( array $left, array $right ): int => ( $left['code'] . ':' . ( $left['reader_id'] ?? '' ) . ':' . ( $left['native_id'] ?? '' ) . ':' . $left['message'] ) <=> ( $right['code'] . ':' . ( $right['reader_id'] ?? '' ) . ':' . ( $right['native_id'] ?? '' ) . ':' . $right['message'] ) );
		$diagnostics = array_values( array_unique( $diagnostics, SORT_REGULAR ) );
		$payload     = array(
			'contract'                => 'directorist-ai/effective-catalog-v1',
			'builder'                 => $request->builder(),
			'document_kind'           => $request->document_kind(),
			'directory_id'            => $request->directory_id(),
			'directory_revision'      => null === $directory ? null : $directory->revision,
			'editor_fingerprint'      => $editor_fingerprint,
			'environment_fingerprint' => $environment->fingerprint(),
			'providers'               => $providers,
			'components'              => $components,
			'diagnostics'             => $diagnostics,
		);
		return $this->contract->finalize( $payload );
	}

	/** Resolve and validate server-issued editor evidence before provider execution. */
	private function editor_fingerprint( CatalogRequest $request ): ?string {
		$binding = $request->editor_binding();
		if ( null === $binding ) {
			return null;
		}
		$binding->assert_current( $request->context(), $request->builder() );
		$fingerprint = $binding->fingerprint();
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			throw new ContractException( 'evidence_stale', 'Editor binding evidence is invalid.' );
		}
		return $fingerprint;
	}

	/** Resolve actor-visible directory facts before any integration reader executes. */
	private function directory( CatalogRequest $request ): ?object {
		if ( null === $request->directory_id() ) {
			return null;
		}
		if ( null === $this->directory_facts ) {
			throw new ContractException( 'unsupported_dependency', 'Directory facts reader is unavailable.' );
		}
		$facts = ( new DirectoryFactsValidator() )->normalize( $this->directory_facts->read( $request->context(), $request->directory_id() ), $request->directory_id() );
		return $this->copy( $facts );
	}

	/** Return the first registration dependency failure. */
	private function negotiate( ProviderRegistration $registration, CatalogEnvironment $environment ): ?array {
		$requirements = array( $registration->native_dependency() => $registration->native_constraint() ) + $registration->requirements();
		foreach ( $requirements as $id => $constraint ) {
			$version = $environment->version( $id );
			if ( null === $version ) {
				return array(
					'status'  => 'missing',
					'code'    => 'dependency_missing',
					'message' => 'A required integration dependency is absent.',
				);
			}
			if ( ! ( new VersionConstraint( $constraint ) )->matches( $version ) ) {
				return array(
					'status'  => 'unsupported',
					'code'    => 'dependency_version_unsupported',
					'message' => 'An installed dependency version is unsupported.',
				);
			}
		}
		return null;
	}

	/** Detect header/runtime source-version disagreement after the reader runs. */
	private function version_conflict( ProviderRegistration $registration, ProviderSnapshot $snapshot, CatalogEnvironment $environment ): bool {
		$observed = $snapshot->observed_versions();
		$required = array_values( array_unique( array_merge( array( $registration->native_dependency() ), array_keys( $registration->requirements() ) ) ) );
		$native   = $observed[ $registration->native_dependency() ] ?? null;
		if ( null === $native || $native !== $snapshot->native_version() || $registration->native_generation() !== $snapshot->native_generation() ) {
			return true;
		}
		foreach ( $required as $id ) {
			if ( ! array_key_exists( $id, $observed ) || $environment->version( $id ) !== $observed[ $id ] ) {
				return true;
			}
		}
		foreach ( $observed as $id => $version ) {
			if ( $environment->version( $id ) !== $version ) {
				return true;
			}
		}
		return false;
	}

	/** Compile one descriptor and suppress unsafe feature tuples. */
	private function component( ProviderRegistration $registration, ProviderSnapshot $snapshot, ComponentDeclaration $component, CatalogRequest $request, CatalogEnvironment $environment, ?object $directory, ?string $editor_fingerprint, bool $version_conflict, bool $incomplete ): array {
		$descriptor      = $component->descriptor();
		$diagnostics     = array();
		$available       = 'unavailable' !== $component->availability();
		$suppress_writes = $incomplete;
		$dependencies    = array();
		foreach ( $component->dependencies() as $id => $constraint ) {
			$version        = $environment->version( $id );
			$status         = null === $version ? 'missing' : ( ( new VersionConstraint( $constraint ) )->matches( $version ) ? 'available' : 'unsupported' );
			$dependencies[] = array(
				'type'       => 'plugin',
				'id'         => $id,
				'constraint' => $constraint,
				'version'    => $version,
				'status'     => $status,
			);
			if ( 'available' !== $status ) {
				$available     = false;
				$diagnostics[] = $this->diagnostic( 'component_dependency_' . $status, $registration->reader_id(), $component->native_id(), 'A component dependency is unavailable.' );
			}
		}
		foreach ( $component->directory_fields() as $field_id ) {
			$status         = null !== $directory && $directory->complete && in_array( $field_id, $directory->field_ids, true ) ? 'available' : 'missing';
			$dependencies[] = array(
				'type'       => 'directory_field',
				'id'         => $field_id,
				'constraint' => null,
				'version'    => null,
				'status'     => $status,
			);
			if ( 'available' !== $status ) {
				$available     = false;
				$diagnostics[] = $this->diagnostic( 'directory_field_unavailable', $registration->reader_id(), $component->native_id(), 'A required authorized directory field is unavailable.' );
			}
		}
		if ( 'editor_only' === $component->availability() && null === $editor_fingerprint ) {
			$available     = false;
			$diagnostics[] = $this->diagnostic( 'editor_context_required', $registration->reader_id(), $component->native_id(), 'Component is available only in a live editor context.' );
		}
		if ( $component->unsupported_controls() ) {
			$suppress_writes = true;
			$diagnostics[]   = $this->diagnostic( 'unsupported_control_mapping', $registration->reader_id(), $component->native_id(), 'Unmapped native controls keep mutation features unavailable.' );
		}
		foreach ( $component->features() as $feature ) {
			if ( in_array( $feature['operation'], self::WRITE_OPERATIONS, true ) && ! $this->certifications->allows( $registration, $snapshot, $component, $feature ) ) {
				$diagnostics[] = $this->diagnostic( 'feature_uncertified', $registration->reader_id(), $component->native_id(), 'Mutation feature lacks an exact registered handler certification.' );
				break;
			}
		}
		if ( $version_conflict ) {
			$available = false;
		}
		$features                       = array_values(
			array_filter(
				$component->features(),
				function ( array $feature ) use ( $registration, $snapshot, $component, $request, $available, $suppress_writes, $editor_fingerprint ): bool {
					return $available && $request->document_kind() === $feature['document_kind'] && ( ! $feature['requires_editor'] || null !== $editor_fingerprint ) && ( ! $suppress_writes || ! in_array( $feature['operation'], self::WRITE_OPERATIONS, true ) ) && $this->certifications->allows( $registration, $snapshot, $component, $feature );
				}
			)
		);
		$descriptor['adapter_id']       = $registration->adapter_id();
		$descriptor['adapter_revision'] = $registration->adapter_revision();
		$descriptor['native_version']   = $snapshot->native_version();
		$descriptor['availability']     = $version_conflict ? 'unsupported' : ( $available ? ( $suppress_writes ? 'read_only' : 'available' ) : 'unavailable' );
		$descriptor['dependencies']     = $dependencies;
		$descriptor['features']         = $features;
		return array(
			'descriptor'  => $descriptor,
			'diagnostics' => $diagnostics,
		);
	}

	/** Resolve duplicate identities without selecting an arbitrary provider. */
	private function resolve_conflicts( array $components, array $diagnostics, array $providers ): array {
		$groups = array();
		foreach ( $components as $component ) {
			$groups[ $component['native_id'] ][] = $component;
		}
		$resolved = array();
		foreach ( $groups as $native_id => $items ) {
			if ( 1 === count( $items ) ) {
				$resolved[] = $items[0];
				continue;
			}
			// One negotiated reader may expose distinct contextual source identities for
			// the same native widget. Callers must still use the exact provider tuple.
			// Different readers or conflicting declarations of one tuple stay blocked.
			$owners  = array_map( static fn( array $item ): string => json_encode( array( $item['adapter_id'], $item['adapter_revision'], $item['native_version'] ), JSON_THROW_ON_ERROR ), $items );
			$sources = array_column( $items, 'source_provider_id' );
			if ( 1 === count( array_unique( $owners ) ) && count( $items ) === count( array_unique( $sources ) ) ) {
				array_push( $resolved, ...$items );
				continue;
			}
			$encoded = array_map( fn( array $item ): string => json_encode( $this->canonical( $item ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), $items );
			if ( 1 === count( array_unique( $encoded ) ) ) {
				$resolved[]    = $items[0];
				$diagnostics[] = $this->diagnostic( 'duplicate_declaration', null, $native_id, 'Identical duplicate native declarations were deduplicated.' );
				continue;
			}
			$diagnostics[] = $this->diagnostic( 'native_identity_conflict', null, $native_id, 'Conflicting native declarations are not eligible for operations.' );
			foreach ( $items as $item ) {
				$item['availability'] = 'conflict';
				$item['features']     = array();
				$resolved[]           = $item;
				foreach ( $providers as &$provider ) {
					if ( $provider['adapter_id'] === $item['adapter_id'] ) {
						$provider['status'] = 'conflict';
					}
				}
				unset( $provider );
			}
		}
		return array(
			'components'  => $resolved,
			'diagnostics' => $diagnostics,
			'providers'   => $providers,
		);
	}

	/** Sort same-identity conflicts by their complete canonical descriptor hash. */
	private function descriptor_key( array $descriptor ): string {
		$encoded = json_encode( $this->canonical( $descriptor ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		return $descriptor['native_id'] . ':' . hash( 'sha256', $encoded );
	}

	/** Build one stable, non-sensitive diagnostic. */
	private function diagnostic( string $code, ?string $reader_id, ?string $native_id, string $message ): array {
		return array(
			'code'      => $code,
			'reader_id' => $reader_id,
			'native_id' => $native_id,
			'message'   => $message,
		);
	}

	/** Map closed provider codes to compiler-owned public text. */
	private function provider_diagnostic_message( string $code ): string {
		$messages = array(
			'source_notice'              => 'Provider reported a source notice.',
			'component_incomplete'       => 'Component source state is incomplete. Mutation features are unavailable.',
			'control_mapping_incomplete' => 'Component control mapping is incomplete. Mutation features are unavailable.',
			'context_unresolved'         => 'Component context could not be resolved. Mutation features are unavailable.',
		);
		return $messages[ $code ];
	}

	/** Canonicalize object keys while retaining list order and scalar types. */
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

	/** Return detached JSON-compatible data, preserving object/list identity. */
	private function copy( $value ) {
		if ( null === $value ) {
			return null;
		}
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 64, JSON_THROW_ON_ERROR );
	}
}
