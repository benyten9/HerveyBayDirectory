<?php
/**
 * Strict server-side selection request for a bounded builder task context.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact immutable DTO.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Completeness and authority claims are deliberately absent from this input. */
final class BuilderTaskContextRequest {
	private const OPERATIONS = array( 'inspect', 'insert_component', 'set_control', 'move_component', 'remove_component', 'bind_field', 'set_template_variant' );
	private object $value;

	public function __construct( object $request ) {
		( new JsonDecoder( 65536, 12 ) )->check( $request );
		$request = json_decode( json_encode( $request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 12, JSON_THROW_ON_ERROR );
		$this->closed( $request, array( 'document_key', 'selection', 'candidate_components', 'control_paths', 'allowed_operations', 'scope', 'limits' ) );
		GraphVersion::identifier( $request->document_key );
		if ( ! is_array( $request->selection ) || ! $request->selection || count( $request->selection ) > 50 || ! is_array( $request->candidate_components ) || count( $request->candidate_components ) > 30 || ! is_array( $request->control_paths ) || count( $request->control_paths ) > 50 || ! is_array( $request->allowed_operations ) || ! $request->allowed_operations || count( $request->allowed_operations ) > 20 ) {
			throw new ContractException( 'invalid_input', 'Task-context selection is invalid.' );
		}
		$request->selection          = $this->tokens( $request->selection, null, 50 );
		$request->control_paths      = $this->paths( $request->control_paths );
		$request->allowed_operations = $this->tokens( $request->allowed_operations, self::OPERATIONS, 20 );
		$components                  = array();
		foreach ( $request->candidate_components as $component ) {
			$this->closed( $component, array( 'provider_id', 'native_id' ) );
			GraphVersion::identifier( $component->provider_id );
			GraphVersion::identifier( $component->native_id );
			$components[ $component->provider_id . '|' . $component->native_id ] = $component;
		}
		ksort( $components, SORT_STRING );
		$request->candidate_components = array_values( $components );
		$this->validate_scope( $request->scope );
		$this->closed( $request->limits, array( 'max_nodes', 'max_edges', 'max_depth', 'max_bytes', 'max_tokens' ) );
		if ( ! is_int( $request->limits->max_nodes ) || $request->limits->max_nodes < 1 || $request->limits->max_nodes > 200 || ! is_int( $request->limits->max_edges ) || $request->limits->max_edges < 1 || $request->limits->max_edges > 1000 || ! is_int( $request->limits->max_depth ) || $request->limits->max_depth < 1 || $request->limits->max_depth > 8 || ! is_int( $request->limits->max_bytes ) || $request->limits->max_bytes < 4096 || $request->limits->max_bytes > 65536 || ! is_int( $request->limits->max_tokens ) || $request->limits->max_tokens < 1024 || $request->limits->max_tokens > 16000 ) {
			throw new ContractException( 'invalid_input', 'Task-context bounds are invalid.' );
		}
		$this->value = $request;
	}

	public function document_key(): string {
		return $this->value->document_key;
	}
	public function selection(): array {
		return $this->value->selection;
	}
	public function candidate_components(): array {
		return $this->value->candidate_components;
	}
	public function control_paths(): array {
		return $this->value->control_paths;
	}
	public function allowed_operations(): array {
		return $this->value->allowed_operations;
	}
	public function scope(): object {
		return clone $this->value->scope;
	}
	public function limits(): object {
		return clone $this->value->limits;
	}

	private function tokens( array $values, ?array $allowed, int $maximum ): array {
		$result = array();
		foreach ( $values as $value ) {
			GraphVersion::identifier( $value );
			if ( null !== $allowed && ! in_array( $value, $allowed, true ) ) {
				throw new ContractException( 'invalid_input', 'Task-context operation is unsupported.' );
			}
			$result[ $value ] = true;
		}
		if ( count( $result ) !== count( $values ) || count( $result ) > $maximum ) {
			throw new ContractException( 'invalid_input', 'Task-context token list is invalid.' );
		}
		$result = array_keys( $result );
		sort( $result, SORT_STRING );
		return $result;
	}

	private function paths( array $paths ): array {
		$result = array();
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || ! preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,7}$/D', $path ) ) {
				throw new ContractException( 'invalid_input', 'Task-context control path is invalid.' );
			}
			$result[ $path ] = true;
		}
		if ( count( $result ) !== count( $paths ) ) {
			throw new ContractException( 'invalid_input', 'Task-context control paths are duplicated.' );
		}
		$result = array_keys( $result );
		sort( $result, SORT_STRING );
		return $result;
	}

	private function validate_scope( $scope ): void {
		$this->closed( $scope, array( 'directory_id', 'view', 'breakpoint_id', 'interaction' ) );
		if ( ( null !== $scope->directory_id && ( ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $scope->directory_id ) ) ) || ! in_array( $scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || ! in_array( $scope->interaction, array( null, 'normal', 'hover' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Task-context scope is invalid.' );
		}
		if ( null !== $scope->breakpoint_id ) {
			GraphVersion::identifier( $scope->breakpoint_id );
		}
	}

	private function closed( $value, array $expected ): void {
		if ( ! $value instanceof \stdClass ) {
			throw new ContractException( 'invalid_input', 'Task-context object is invalid.' );
		}
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'invalid_input', 'Task-context object shape is invalid.' );
		}
	}
}
