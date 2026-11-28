<?php
/**
 * Hash-bound provenance carried into a reviewed builder proposal.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact immutable provenance.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Selection records why a recipe was used; it never grants an operation. */
final class BuilderRecipeSelection {
	private object $value;
	private JsonCanonicalizer $canonical;

	public function __construct( object $value ) {
		( new JsonDecoder( 32768, 12 ) )->check( $value );
		$fields   = array_keys( get_object_vars( $value ) );
		$required = array( 'recipe_id', 'recipe_revision', 'recipe_hash', 'catalog_revision', 'catalog_binding_revision', 'catalog_provider_fingerprint', 'relationship_fact_revision', 'design_fact_revision', 'graph_version_hash', 'graph_generation', 'graph_hash', 'document_key', 'scope_hash', 'changes_hash', 'token_sources', 'field_requirements' );
		sort( $fields, SORT_STRING );
		sort( $required, SORT_STRING );
		if ( $fields !== $required || ! is_array( $value->token_sources ) || count( $value->token_sources ) > 20 || ! is_array( $value->field_requirements ) || count( $value->field_requirements ) > 12 ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe selection is invalid.' );
		}
		foreach ( array( 'recipe_id', 'recipe_revision', 'document_key' ) as $field ) {
			GraphVersion::identifier( $value->{$field} );
		}
		foreach ( array( 'recipe_hash', 'catalog_revision', 'catalog_binding_revision', 'catalog_provider_fingerprint', 'relationship_fact_revision', 'design_fact_revision', 'graph_version_hash', 'graph_hash', 'scope_hash', 'changes_hash' ) as $field ) {
			if ( ! is_string( $value->{$field} ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value->{$field} ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe selection revision is invalid.' );
			}
		}
		if ( ! is_int( $value->graph_generation ) || $value->graph_generation < 1 ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe graph generation is invalid.' );
		}
		foreach ( $value->field_requirements as $field ) {
			if ( ! $field instanceof \stdClass || array_keys( get_object_vars( $field ) ) !== array( 'id', 'type' ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe field provenance is invalid.' );
			}
			GraphVersion::identifier( $field->id );
			GraphVersion::identifier( $field->type );
		}
		foreach ( $value->token_sources as $token ) {
			$keys = $token instanceof \stdClass ? array_keys( get_object_vars( $token ) ) : array();
			sort( $keys, SORT_STRING );
			if ( array( 'id', 'kind', 'local_override', 'scope', 'source_id', 'source_revision' ) !== $keys || ! in_array( $token->scope, array( 'local', 'inherited' ), true ) || ! is_bool( $token->local_override ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe token provenance is invalid.' );
			}
			foreach ( array( 'id', 'kind', 'source_id', 'source_revision' ) as $key ) {
				GraphVersion::identifier( $token->{$key} );
			}
		}
		$this->canonical = new JsonCanonicalizer();
		$this->value     = json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public function assert_matches( string $document_key, GraphVersion $version, object $scope, array $changes ): void {
		if ( $document_key !== $this->value->document_key || ! hash_equals( $version->hash(), $this->value->graph_version_hash ) || ! hash_equals( hash( 'sha256', $this->canonical->encode( $scope ) ), $this->value->scope_hash ) || ! hash_equals( hash( 'sha256', $this->canonical->encode( $changes ) ), $this->value->changes_hash ) ) {
			throw new ContractException( 'evidence_stale', 'Builder recipe provenance differs from the proposed changes.' );
		}
	}

	public function value(): object {
		return json_decode( json_encode( $this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
}
