<?php
/**
 * Trusted strict schemas for builder change planning.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed declarative policy data.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Installed adapters register schemas; requests can only validate against the frozen result. */
final class BuilderPlanningPolicyRegistry {
	private const MAX_COMPONENTS = 2000;
	/** Aggregate installed declarations include every saved style/device family. */
	private const MAX_NATIVE_POLICY_BYTES = 1048576;
	/** Model/user values retain their independent, smaller transport allowance. */
	private const MAX_VALUE_BYTES = 524288;
	private const MAX_RUNTIME_VALIDATORS = 16;
	private const MAX_RUNTIME_SCHEMA_BYTES = 1048576;
	/** Private schema/parser instances only. No values or validation decisions. */
	private array $runtime_validators = array();
	private int $runtime_schema_bytes = 0;
	/** @var array<string,object> */
	private array $components = array();
	/** @var array<string,array{identity:object,factory:callable}> Trusted lazy native definitions. */
	private array $deferred = array();
	/** @var array<string,bool> Reject recursive factory resolution. */
	private array $resolving = array();
	private bool $frozen = false;

	/** Freeze a server-owned identity without loading unrelated editor control stacks. */
	public function register_deferred( object $identity, callable $factory ): void {
		if ( $this->frozen ) {
			throw new ContractException( 'scope_conflict', 'Builder planning policies are frozen.' ); }
		$this->closed( $identity, array( 'provider_id', 'native_id', 'revision' ) );
		foreach ( array( 'provider_id', 'native_id', 'revision' ) as $field ) {
			GraphVersion::identifier( $identity->{$field} ); }
		$key = $identity->provider_id . '|' . $identity->native_id;
		if ( isset( $this->components[ $key ] ) || isset( $this->deferred[ $key ] ) ) {
			throw new ContractException( 'scope_conflict', 'Builder planning policy identity conflicts.' ); }
		if ( count( $this->components ) + count( $this->deferred ) >= self::MAX_COMPONENTS ) {
			throw new ContractException( 'needs_expansion', 'Builder planning policy limit is exceeded.' ); }
		$this->deferred[ $key ] = array( 'identity' => clone $identity, 'factory' => $factory );
	}

	public function register( object $policy ): void {
		if ( $this->frozen ) {
			throw new ContractException( 'scope_conflict', 'Builder planning policies are frozen.' );
		}
		if ( count( $this->components ) + count( $this->deferred ) >= self::MAX_COMPONENTS ) {
			throw new ContractException( 'needs_expansion', 'Builder planning policy limit is exceeded.' );
		}
		try {
			( new JsonDecoder( self::MAX_NATIVE_POLICY_BYTES, 32 ) )->check( $policy );
		} catch ( ContractException $error ) {
			throw new ContractException( 'unsupported_contract', 'Installed component planning metadata exceeds its supported bounds.' );
		}
		$this->closed( $policy, array( 'provider_id', 'native_id', 'revision', 'insert_attributes_schema', 'controls', 'existing_variants', 'allow_new_variants', 'variant_plan_schema' ) );
		foreach ( array( 'provider_id', 'native_id', 'revision' ) as $field ) {
			GraphVersion::identifier( $policy->{$field} );
		}
		if ( ! $policy->insert_attributes_schema instanceof \stdClass || ! $policy->variant_plan_schema instanceof \stdClass || ! is_array( $policy->controls ) || count( $policy->controls ) > 200 || ! is_array( $policy->existing_variants ) || count( $policy->existing_variants ) > 100 || ! is_bool( $policy->allow_new_variants ) ) {
			throw new ContractException( 'unsupported_contract', 'Builder planning policy shape is invalid.' );
		}
		$this->schema_check( $policy->insert_attributes_schema, 'insert_attributes' );
		$this->schema_check( $policy->variant_plan_schema, 'variant_plan' );
		$controls = array();
		foreach ( $policy->controls as $control ) {
			if ( ! $control instanceof \stdClass ) {
				throw new ContractException( 'unsupported_contract', 'Builder control policy is invalid.' );
			}
			$this->closed( $control, array( 'path', 'value_schema', 'field_types', 'private' ) );
			$this->path( $control->path );
			if ( isset( $controls[ $control->path ] ) || ! $control->value_schema instanceof \stdClass || ! is_array( $control->field_types ) || count( $control->field_types ) > 50 || ! is_bool( $control->private ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder control policy conflicts.' );
			}
			foreach ( $controls as $existing ) {
				if ( ( $existing->private && ! $control->private && str_starts_with( $control->path, $existing->path . '.' ) ) || ( $control->private && ! $existing->private && str_starts_with( $existing->path, $control->path . '.' ) ) ) {
					throw new ContractException( 'unsupported_contract', 'Private builder control cannot have a public descendant.' );
				}
			}
			$this->schema_check( $control->value_schema, 'control_' . substr( hash( 'sha256', $control->path ), 0, 16 ) );
			foreach ( $control->field_types as $type ) {
				GraphVersion::identifier( $type );
			}
			if ( count( array_unique( $control->field_types, SORT_STRING ) ) !== count( $control->field_types ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder control field types are duplicated.' );
			}
			$controls[ $control->path ] = $control;
		}
		foreach ( $policy->existing_variants as $variant ) {
			GraphVersion::identifier( $variant );
		}
		if ( count( array_unique( $policy->existing_variants, SORT_STRING ) ) !== count( $policy->existing_variants ) ) {
			throw new ContractException( 'unsupported_contract', 'Builder variants are duplicated.' );
		}
		$key = $policy->provider_id . '|' . $policy->native_id;
		if ( isset( $this->components[ $key ] ) || isset( $this->deferred[ $key ] ) ) {
			throw new ContractException( 'scope_conflict', 'Builder planning policy identity conflicts.' );
		}
		$this->components[ $key ] = json_decode( json_encode( $policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), false, 32, JSON_THROW_ON_ERROR );
	}

	public function freeze(): void {
		$this->frozen = true;
	}

	public function component( string $provider_id, string $native_id ): ?object {
		if ( ! $this->frozen ) {
			throw new ContractException( 'unsupported_contract', 'Builder planning policies must be frozen.' );
		}
		$key = $provider_id . '|' . $native_id;
		if ( ! isset( $this->components[ $key ] ) && isset( $this->deferred[ $key ] ) ) {
			if ( isset( $this->resolving[ $key ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Recursive native planning policy is unavailable.' ); }
			$this->resolving[ $key ] = true;
			try {
				$registration = $this->deferred[ $key ];
				$policy = ( $registration['factory'] )();
				if ( ! $policy instanceof \stdClass || ( $policy->provider_id ?? null ) !== $registration['identity']->provider_id || ( $policy->native_id ?? null ) !== $registration['identity']->native_id || ( $policy->revision ?? null ) !== $registration['identity']->revision ) {
					throw new ContractException( 'unsupported_contract', 'Deferred native planning identity changed.' );
				}
				// Reuse the identical strict bounded registration path. A factory is
				// installed PHP, never a request-supplied schema or permission grant.
				$validation = new self();
				$validation->register( $policy );
				$validation->freeze();
				// Registration already made the private validated deep copy. Transfer
				// that copy without overlapping another complete policy allocation.
				$this->components[ $key ] = $validation->components[ $key ];
				unset( $this->deferred[ $key ] );
				unset( $policy, $validation, $registration );
			} finally {
				unset( $this->resolving[ $key ] );
			}
		}
		return isset( $this->components[ $key ] ) ? json_decode( json_encode( $this->components[ $key ], JSON_THROW_ON_ERROR ) ) : null;
	}

	public function validate_insert( object $policy, object $attributes ): void {
		$this->validate( $policy->insert_attributes_schema, $attributes, 'Inserted component attributes' );
	}

	public function validate_control( object $policy, string $path, $value ): object {
		foreach ( $policy->controls as $control ) {
			if ( $control->path === $path ) {
				if ( $control->private ) {
					throw new ContractException( 'unauthorized', 'Private builder control is unavailable.' );
				}
				$this->validate( $control->value_schema, $value, 'Builder control value' );
				return $control;
			}
		}
		throw new ContractException( 'unsupported_operation', 'Builder control is not declared.' );
	}

	public function validate_binding( object $policy, string $path, string $field_type, object $binding ): void {
		foreach ( $policy->controls as $control ) {
			if ( $control->path === $path && ! $control->private && in_array( $field_type, $control->field_types, true ) ) {
				$this->validate( $control->value_schema, $binding, 'Builder field binding' );
				return;
			}
		}
		throw new ContractException( 'unsupported_operation', 'Builder control does not allow this field type.' );
	}

	public function validate_variant( object $policy, string $variant_id, string $mode, object $plan ): void {
		if ( 'select_existing' === $mode ) {
			if ( ! in_array( $variant_id, $policy->existing_variants, true ) || get_object_vars( $plan ) ) {
				throw new ContractException( 'unsupported_operation', 'Builder variant is unavailable.' );
			}
			return;
		}
		if ( 'create' !== $mode || ! $policy->allow_new_variants || in_array( $variant_id, $policy->existing_variants, true ) ) {
			throw new ContractException( 'unsupported_operation', 'New builder variant is unavailable.' );
		}
		$this->validate( $policy->variant_plan_schema, $plan, 'Builder variant plan' );
	}

	private function validate( object $schema, $value, string $label ): void {
		$json = json_encode( $schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		// Exact bytes plus the fixed decoder/engine mode identify trusted setup,
		// never an owner, native snapshot, permission or previous value result.
		$key = hash( 'sha256', "draft07:no-io:524288:32\0" . $json );
		$cached = $this->frozen && isset( $this->runtime_validators[ $key ] ) && $this->runtime_validators[ $key ]['json'] === $json ? $this->runtime_validators[ $key ] : null;
		$schemas = null;
		if ( null === $cached ) {
			$schemas = new SchemaRegistry();
			$schemas->register( 'builder.policy.runtime', $json );
		}
		try {
			$validator = $cached['validator'] ?? new ContractValidator( $schemas, new JsonDecoder( self::MAX_VALUE_BYTES, 32 ) );
			$validator->validate( $value, 'builder.policy.runtime' );
		} catch ( ContractException $error ) {
			throw new ContractException( 'invalid_input', $label . ' does not match its trusted schema.', array( 'planning_schema_mismatch' ) );
		}
		if ( ! $this->frozen ) { return; }
		if ( null !== $cached ) {
			unset( $this->runtime_validators[ $key ] );
			$this->runtime_validators[ $key ] = $cached;
			return;
		}
		$bytes = strlen( $json ) + $schemas->encoded_size( 'builder.policy.runtime' );
		if ( $bytes > self::MAX_RUNTIME_SCHEMA_BYTES ) { return; }
		while ( $this->runtime_validators && ( count( $this->runtime_validators ) >= self::MAX_RUNTIME_VALIDATORS || $this->runtime_schema_bytes + $bytes > self::MAX_RUNTIME_SCHEMA_BYTES ) ) {
			$old = array_key_first( $this->runtime_validators );
			$this->runtime_schema_bytes -= $this->runtime_validators[ $old ]['bytes'];
			unset( $this->runtime_validators[ $old ] );
		}
		$this->runtime_validators[ $key ] = array( 'json' => $json, 'validator' => $validator, 'bytes' => $bytes );
		$this->runtime_schema_bytes += $bytes;
	}

	private function schema_check( object $schema, string $suffix ): void {
		SchemaRegistry::assert_valid_schema( json_encode( $schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}

	private function closed( object $value, array $expected ): void {
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'unsupported_contract', 'Builder planning policy shape is invalid.' );
		}
	}

	private function path( $path ): void {
		if ( ! is_string( $path ) || ! preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,15}$/D', $path ) ) {
			throw new ContractException( 'unsupported_contract', 'Builder planning control path is invalid.' );
		}
	}
}
