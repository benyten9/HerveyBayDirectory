<?php
/**
 * Current safe design facts for a bounded builder task.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact immutable DTO.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Raw CSS, markup, URLs, credentials and opaque values are not valid design tokens. */
final class BuilderTaskContextFacts {
	private object $value;
	private string $revision;

	public function __construct( object $facts ) {
		( new JsonDecoder( 262144, 16 ) )->check( $facts );
		$facts = json_decode( json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 16, JSON_THROW_ON_ERROR );
		$this->closed( $facts, array( 'site_id', 'owner_ref', 'run_id', 'conversation_id', 'document_key', 'graph_version_hash', 'catalog_revision', 'design_tokens' ) );
		if ( ! is_int( $facts->site_id ) || $facts->site_id < 1 || ! is_array( $facts->design_tokens ) || count( $facts->design_tokens ) > 200 ) {
			throw new ContractException( 'unsupported_contract', 'Task-context fact authority is invalid.' );
		}
		foreach ( array( 'owner_ref', 'run_id', 'conversation_id', 'document_key' ) as $field ) {
			GraphVersion::identifier( $facts->{$field} );
		}
		$this->hash( $facts->graph_version_hash );
		$this->hash( $facts->catalog_revision );
		$seen = array();
		foreach ( $facts->design_tokens as $token ) {
			$this->closed( $token, array( 'id', 'kind', 'value', 'scope', 'source_id', 'source_revision' ) );
			foreach ( array( 'id', 'source_id', 'source_revision' ) as $field ) {
				GraphVersion::identifier( $token->{$field} );
			}
			if ( isset( $seen[ $token->id ] ) || ! in_array( $token->scope, array( 'local', 'inherited' ), true ) || ! self::safe_token_value( $token->kind, $token->value ) ) {
				throw new ContractException( 'unsupported_contract', 'Task-context design token is invalid.' );
			}
			$seen[ $token->id ] = true;
		}
		usort( $facts->design_tokens, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
		$this->value    = $facts;
		$this->revision = hash( 'sha256', ( new JsonCanonicalizer() )->encode( $facts ) );
	}

	public function site_id(): int {
		return $this->value->site_id;
	}
	public function owner_ref(): string {
		return $this->value->owner_ref;
	}
	public function run_id(): string {
		return $this->value->run_id;
	}
	public function conversation_id(): string {
		return $this->value->conversation_id;
	}
	public function document_key(): string {
		return $this->value->document_key;
	}
	public function graph_version_hash(): string {
		return $this->value->graph_version_hash;
	}
	public function catalog_revision(): string {
		return $this->value->catalog_revision;
	}
	public function design_tokens(): array {
		return json_decode( json_encode( $this->value->design_tokens, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
	public function revision(): string {
		return $this->revision;
	}

	public static function safe_token_value( string $kind, $value ): bool {
		switch ( $kind ) {
			case 'color':
				return is_string( $value ) && (bool) preg_match( '/^(#[a-fA-F0-9]{3,8}|rgba?\([0-9., %]+\)|hsla?\([0-9., %]+\))$/D', $value );
			case 'length':
				return is_string( $value ) && (bool) preg_match( '/^-?(?:0|[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh)$/D', $value );
			case 'number':
				return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value );
			case 'font_weight':
				return is_int( $value ) && $value >= 100 && $value <= 900 && 0 === $value % 100;
			case 'font_family':
				return is_string( $value ) && strlen( $value ) <= 80 && (bool) preg_match( '/^[A-Za-z0-9 ,_-]+$/D', $value );
		}
		return false;
	}

	private function closed( object $value, array $expected ): void {
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'unsupported_contract', 'Task-context fact shape is invalid.' );
		}
	}

	private function hash( $value ): void {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new ContractException( 'unsupported_contract', 'Task-context fact revision is invalid.' );
		}
	}
}
