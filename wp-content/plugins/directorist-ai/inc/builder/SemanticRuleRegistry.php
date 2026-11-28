<?php
/**
 * Trusted declarative semantic rules for builder graph validation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed rule invariants remain compact and auditable.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Rules are registered by trusted PHP during bootstrap and become immutable. */
final class SemanticRuleRegistry {
	/** Frozen tuple results only; returned declarations are still detached. */
	private array $relationship_lookups = array();
	private const MAX_RELATIONSHIP_LOOKUPS = 1024;
	private const MAX_COMPONENTS    = 2000;
	private const MAX_RELATIONSHIPS = 5000;
	/** @var array<string,object> */
	private array $components = array();
	/** @var array<string,string> */
	private array $component_keys = array();
	/** @var array<string,object> */
	private array $relationships = array();
	private bool $frozen = false;
	/** @var string|null Exact revision of immutable frozen declarations. */
	private ?string $revision_hash = null;
	private JsonCanonicalizer $canonical;
	/** @var \Closure|null Trusted callbacks captured during bootstrap. */
	private ?\Closure $loader = null;
	private bool $loading = false;
	private bool $load_failed = false;

	public function __construct( ?\Closure $loader = null ) {
		$this->canonical = new JsonCanonicalizer();
		$this->loader = $loader;
	}

	/** Register one exact component rule from installed trusted code. */
	public function register_component( object $rule ): void {
		$this->mutable();
		if ( count( $this->components ) >= self::MAX_COMPONENTS ) {
			throw new ContractException( 'needs_expansion', 'Semantic component rule limit is exceeded.' );
		}
		( new JsonDecoder( 262144, 24 ) )->check( $rule );
		$this->closed( $rule, array( 'id', 'revision', 'provider_id', 'native_id', 'allowed_views', 'allowed_breakpoints', 'allowed_interactions', 'requires_contexts', 'requires_dependencies', 'conditional_controls', 'opaque_context_safe' ) );
		foreach ( array( 'id', 'revision', 'provider_id', 'native_id' ) as $field ) {
			GraphVersion::identifier( $rule->{$field} );
		}
		$this->tokens( $rule->allowed_views, array( 'grid', 'list', 'map', 'single' ), true );
		$this->tokens( $rule->allowed_breakpoints, null, true );
		$this->tokens( $rule->allowed_interactions, array( 'normal', 'hover' ), true );
		$this->tokens( $rule->requires_contexts );
		$this->tokens( $rule->requires_dependencies );
		if ( ! is_bool( $rule->opaque_context_safe ) || ! is_array( $rule->conditional_controls ) || count( $rule->conditional_controls ) > 100 ) {
			throw new ContractException( 'unsupported_contract', 'Component semantic rule metadata is invalid.' );
		}
		foreach ( $rule->conditional_controls as $condition ) {
			if ( ! $condition instanceof \stdClass ) {
				throw new ContractException( 'unsupported_contract', 'Conditional control rule is invalid.' );
			}
			$this->closed( $condition, array( 'control_path', 'when_path', 'operator', 'expected' ) );
			$this->path( $condition->control_path );
			$this->path( $condition->when_path );
			if ( ! in_array( $condition->operator, array( 'equals', 'present', 'in' ), true ) || ( 'present' === $condition->operator && ! is_bool( $condition->expected ) ) || ( 'in' === $condition->operator && ! is_array( $condition->expected ) ) ) {
				throw new ContractException( 'unsupported_contract', 'Conditional control operator is invalid.' );
			}
			( new JsonDecoder( 65536, 16 ) )->check( $condition->expected );
		}
		$key = $rule->provider_id . '|' . $rule->native_id;
		if ( isset( $this->components[ $rule->id ] ) || isset( $this->component_keys[ $key ] ) ) {
			throw new ContractException( 'scope_conflict', 'Component semantic rule identity conflicts.' );
		}
		$copy = $this->copy( $rule );
		$this->components[ $rule->id ] = $copy;
		$this->component_keys[ $key ]  = $rule->id;
	}

	/** Register one exact edge rule from installed trusted code. */
	public function register_relationship( object $rule ): void {
		$this->mutable();
		if ( count( $this->relationships ) >= self::MAX_RELATIONSHIPS ) {
			throw new ContractException( 'needs_expansion', 'Semantic relationship rule limit is exceeded.' );
		}
		( new JsonDecoder( 65536, 20 ) )->check( $rule );
		$this->closed( $rule, array( 'id', 'revision', 'kind', 'source_rule_id', 'target_rule_id', 'context_id', 'field_types', 'dependency_ids', 'reference_mode', 'scope_policy', 'resolver_id', 'source_ref' ) );
		foreach ( array( 'id', 'revision', 'source_rule_id', 'target_rule_id' ) as $field ) {
			GraphVersion::identifier( $rule->{$field} );
		}
		if ( ! in_array( $rule->kind, array( 'contains', 'context', 'field', 'template', 'style', 'dependency' ), true ) || ! in_array( $rule->reference_mode, array( 'none', 'link', 'render' ), true ) || ! in_array( $rule->scope_policy, array( 'exact', 'responsive', 'target' ), true ) || ( null !== $rule->context_id && ! is_string( $rule->context_id ) ) || ! is_string( $rule->resolver_id ) || ! $rule->source_ref instanceof \stdClass ) {
			throw new ContractException( 'unsupported_contract', 'Relationship semantic rule metadata is invalid.' );
		}
		GraphVersion::identifier( $rule->resolver_id );
		$this->closed( $rule->source_ref, array( 'kind', 'id' ) );
		if ( ! in_array( $rule->source_ref->kind, array( 'source', 'artifact', 'run', 'operation', 'receipt' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Relationship semantic provenance is invalid.' );
		}
		GraphVersion::identifier( $rule->source_ref->id );
		if ( null !== $rule->context_id ) {
			GraphVersion::identifier( $rule->context_id );
		}
		$this->tokens( $rule->field_types );
		$this->tokens( $rule->dependency_ids );
		if ( ( 'context' === $rule->kind ) !== ( null !== $rule->context_id ) || ( 'field' === $rule->kind ) !== ! empty( $rule->field_types ) || ( 'dependency' === $rule->kind ) !== ! empty( $rule->dependency_ids ) || ( 'template' === $rule->kind ) !== in_array( $rule->reference_mode, array( 'link', 'render' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Relationship rule constraints do not match its kind.' );
		}
		if ( 'responsive' === $rule->scope_policy && ! in_array( $rule->kind, array( 'context', 'style' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Responsive inheritance is unsupported for this relationship kind.' );
		}
		if ( 'target' === $rule->scope_policy && ! in_array( $rule->kind, array( 'contains', 'context' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Target scope transitions are unsupported for this relationship kind.' );
		}
		if ( isset( $this->relationships[ $rule->id ] ) ) {
			throw new ContractException( 'scope_conflict', 'Relationship semantic rule identity conflicts.' );
		}
		$this->relationships[ $rule->id ] = $this->copy( $rule );
	}

	/** Freeze registration before any request or model-controlled value is handled. */
	public function freeze(): void {
		foreach ( $this->relationships as $rule ) {
			if ( ! isset( $this->components[ $rule->source_rule_id ] ) || ! isset( $this->components[ $rule->target_rule_id ] ) ) {
				throw new ContractException( 'unsupported_dependency', 'Relationship rule references an unavailable component rule.' );
			}
		}
		$this->frozen = true;
	}

	public function component_for( string $provider_id, string $native_id ): ?object {
		$this->readable();
		$id = $this->component_keys[ $provider_id . '|' . $native_id ] ?? null;
		return null === $id ? null : $this->copy( $this->components[ $id ] );
	}

	public function component( string $id ): ?object {
		$this->readable();
		return isset( $this->components[ $id ] ) ? $this->copy( $this->components[ $id ] ) : null;
	}

	public function relationship( string $id ): ?object {
		$this->readable();
		return isset( $this->relationships[ $id ] ) ? $this->copy( $this->relationships[ $id ] ) : null;
	}

	/** Whether any frozen rule allows this exact semantic edge tuple. */
	public function allows( string $kind, string $source_rule_id, string $target_rule_id ): bool {
		return null !== $this->matching_relationship( $kind, $source_rule_id, $target_rule_id );
	}

	/** Return the sole trusted rule for an exact tuple, rejecting declaration ambiguity. */
	public function matching_relationship( string $kind, string $source_rule_id, string $target_rule_id ): ?object {
		$this->readable();
		$key = serialize( array( $kind, $source_rule_id, $target_rule_id ) );
		if ( array_key_exists( $key, $this->relationship_lookups ) ) {
			$id = $this->relationship_lookups[ $key ];
			return null === $id ? null : $this->copy( $this->relationships[ $id ] );
		}
		$matched = null;
		foreach ( $this->relationships as $rule ) {
			if ( $rule->kind === $kind && $rule->source_rule_id === $source_rule_id && $rule->target_rule_id === $target_rule_id ) {
				if ( null !== $matched ) {
					throw new ContractException( 'scope_conflict', 'Builder semantic relationship tuple is ambiguous.' );
				}
				$matched = $rule;
			}
		}
		if ( strlen( $key ) <= 1024 && count( $this->relationship_lookups ) < self::MAX_RELATIONSHIP_LOOKUPS ) {
			$this->relationship_lookups[ $key ] = null === $matched ? null : $matched->id;
		}
		return null === $matched ? null : $this->copy( $matched );
	}

	/** Return every declared tuple variant for read-only model guidance. */
	public function matching_relationships( string $kind, string $source_rule_id, string $target_rule_id ): array {
		$this->readable();
		$matched = array();
		foreach ( $this->relationships as $rule ) {
			if ( $rule->kind === $kind && $rule->source_rule_id === $source_rule_id && $rule->target_rule_id === $target_rule_id ) {
				$matched[] = $this->copy( $rule );
			}
		}
		usort( $matched, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
		return $matched;
	}

	/** Exact installed targets of this component's declared dependency edges. */
	public function dependency_targets( string $provider_id, string $native_id ): array {
		$this->readable();
		$source = $this->component_for( $provider_id, $native_id );
		$targets = array();
		foreach ( $this->relationships as $rule ) {
			if ( null !== $source && 'dependency' === $rule->kind && $source->id === $rule->source_rule_id ) {
				$targets[] = (object) array( 'component' => $this->copy( $this->components[ $rule->target_rule_id ] ), 'rule' => $this->copy( $rule ) );
			}
		}
		usort( $targets, static fn( object $left, object $right ): int => strcmp( $left->rule->id, $right->rule->id ) );
		return $targets;
	}

	/** Hash exact trusted declarations independently of registration order. */
	public function revision(): string {
		$this->readable();
		if ( null !== $this->revision_hash ) {
			return $this->revision_hash;
		}
		$components    = $this->components;
		$relationships = $this->relationships;
		ksort( $components, SORT_STRING );
		ksort( $relationships, SORT_STRING );
		$this->revision_hash = $this->canonical->digest( (object) array( 'components' => array_values( $components ), 'relationships' => array_values( $relationships ) ) );
		return $this->revision_hash;
	}

	private function mutable(): void {
		if ( $this->frozen || null !== $this->loader ) {
			throw new ContractException( 'scope_conflict', 'Semantic rule registry is frozen.' );
		}
	}

	private function readable(): void {
		if ( ! $this->frozen ) {
			throw new ContractException( 'unsupported_contract', 'Semantic rules must be frozen before validation.' );
		}
		if ( $this->loading || $this->load_failed ) {
			throw new ContractException( 'unsupported_dependency', 'Native builder semantic declarations are unavailable.' );
		}
		if ( null !== $this->loader ) {
			$this->loading = true;
			try {
				// Populate a separate registry so a failed factory can never expose
				// partial declarations or reopen this frozen registration surface.
				$loaded = new self();
				( $this->loader )( $loaded );
				$loaded->freeze();
				$this->components = $loaded->components;
				$this->component_keys = $loaded->component_keys;
				$this->relationships = $loaded->relationships;
				$this->loader = null;
			} catch ( \Throwable $error ) {
				$this->load_failed = true;
				$this->loader = null;
				throw $error instanceof ContractException ? $error : new ContractException( 'unsupported_dependency', 'Native builder semantic declarations could not be loaded.' );
			} finally {
				$this->loading = false;
			}
		}
	}

	private function closed( object $value, array $expected ): void {
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'unsupported_contract', 'Semantic rule shape is invalid.' );
		}
	}

	private function tokens( $values, ?array $allowed = null, bool $nullable = false ): void {
		if ( ! is_array( $values ) || count( $values ) > 100 ) {
			throw new ContractException( 'unsupported_contract', 'Semantic rule token list is invalid.' );
		}
		$seen = array();
		foreach ( $values as $value ) {
			if ( null === $value && $nullable ) {
				if ( isset( $seen['null'] ) ) {
					throw new ContractException( 'unsupported_contract', 'Semantic rule token is duplicated.' );
				}
				$seen['null'] = true;
				continue;
			}
			GraphVersion::identifier( $value );
			if ( isset( $seen[ 'token:' . $value ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Semantic rule token is duplicated.' );
			}
			$seen[ 'token:' . $value ] = true;
			if ( null !== $allowed && ! in_array( $value, $allowed, true ) ) {
				throw new ContractException( 'unsupported_contract', 'Semantic rule token is unsupported.' );
			}
		}
	}

	private function path( $path ): void {
		if ( ! is_string( $path ) || ! preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,15}$/D', $path ) ) {
			throw new ContractException( 'unsupported_contract', 'Conditional attribute path is invalid.' );
		}
	}

	private function copy( object $value ): object {
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), false, 32, JSON_THROW_ON_ERROR );
	}
}
