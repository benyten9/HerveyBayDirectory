<?php
/**
 * Untrusted parameters for one registered builder recipe.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact closed request value.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** A request can select a recipe and local scope; it cannot register rules or global styles. */
final class BuilderRecipeRequest {
	private object $value;

	public function __construct( object $value ) {
		( new JsonDecoder( 262144, 20 ) )->check( $value );
		$value = json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 20, JSON_THROW_ON_ERROR );
		$this->closed( $value, array( 'recipe_id', 'parameters', 'targets', 'token_overrides', 'scope', 'style_scope' ) );
		GraphVersion::identifier( $value->recipe_id );
		if ( ! $value->parameters instanceof \stdClass || ! $value->targets instanceof \stdClass || ! is_array( $value->token_overrides ) || count( $value->token_overrides ) > 20 || 'local' !== $value->style_scope ) {
			throw new ContractException( 'invalid_input', 'Builder recipe request must remain local and bounded.' );
		}
		$this->closed( $value->scope, array( 'directory_id', 'view', 'breakpoint_id', 'interaction' ) );
		if ( ( null !== $value->scope->directory_id && ( ! is_string( $value->scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $value->scope->directory_id ) ) ) || ! in_array( $value->scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || ! in_array( $value->scope->interaction, array( null, 'normal', 'hover' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Builder recipe scope is invalid.' );
		}
		if ( null !== $value->scope->breakpoint_id ) {
			GraphVersion::identifier( $value->scope->breakpoint_id );
		}
		if ( ! get_object_vars( $value->targets ) || count( get_object_vars( $value->targets ) ) > 16 ) {
			throw new ContractException( 'invalid_input', 'Builder recipe targets are invalid.' );
		}
		foreach ( get_object_vars( $value->targets ) as $alias => $locator ) {
			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $alias ) || ! $locator instanceof \stdClass ) {
				throw new ContractException( 'invalid_input', 'Builder recipe target locator is invalid.' );
			}
		}
		$seen = array();
		foreach ( $value->token_overrides as $override ) {
			$this->closed( $override, array( 'id', 'kind', 'value', 'breakpoint_id' ) );
			GraphVersion::identifier( $override->id );
			if ( isset( $seen[ $override->id ] ) || $override->breakpoint_id !== $value->scope->breakpoint_id || ! BuilderTaskContextFacts::safe_token_value( $override->kind, $override->value ) ) {
				throw new ContractException( 'invalid_input', 'Builder recipe local token override is invalid.' );
			}
			$seen[ $override->id ] = true;
		}
		$this->value = $value;
	}

	public function recipe_id(): string {
		return $this->value->recipe_id;
	}

	public function parameters(): object {
		return json_decode( json_encode( $this->value->parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public function targets(): object {
		return json_decode( json_encode( $this->value->targets, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public function token_overrides(): array {
		return json_decode( json_encode( $this->value->token_overrides, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public function scope(): object {
		return clone $this->value->scope;
	}

	private function closed( $value, array $expected ): void {
		$actual = $value instanceof \stdClass ? array_keys( get_object_vars( $value ) ) : array();
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'invalid_input', 'Builder recipe request shape is invalid.' );
		}
	}
}
