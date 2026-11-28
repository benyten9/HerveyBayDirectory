<?php
/**
 * Closed field-edge reconciliation for already inspected native nodes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** The optional adapter can reconcile declared bindings, never graph authority. */
final class BuilderFieldBindingReconciliation {
	private const MAX_BINDINGS = 20;
	/**
	 * Current native semantic rules.
	 *
	 * @var RelationshipValidator
	 */
	private RelationshipValidator $relationships;
	/**
	 * Current public binding policies.
	 *
	 * @var BuilderPlanningPolicyRegistry
	 */
	private BuilderPlanningPolicyRegistry $policies;
	/**
	 * Semantic value comparison.
	 *
	 * @var JsonCanonicalizer
	 */
	private JsonCanonicalizer $canonical;

	/**
	 * Bind current rules and public control policies.
	 *
	 * @param RelationshipValidator         $relationships Native semantic rules.
	 * @param BuilderPlanningPolicyRegistry $policies Native control policies.
	 */
	public function __construct( RelationshipValidator $relationships, BuilderPlanningPolicyRegistry $policies ) {
		$this->relationships = $relationships;
		$this->policies      = $policies;
		$this->canonical     = new JsonCanonicalizer();
	}

	/**
	 * Validate a native-only field-edge delta; full candidate validation follows.
	 *
	 * @param CandidateFieldBindingRelationshipReconciler $adapter Trusted native adapter.
	 * @param Owner                                       $owner Current owner.
	 * @param string                                      $document_key Exact saved document key.
	 * @param GraphVersion                                $version Current source version.
	 * @param object                                      $candidate Planned graph with source locators.
	 * @param array                                       $bindings Normalized binding changes.
	 * @param RelationshipFacts                           $facts Fresh native field authority.
	 * @return object Graph with only the declared field edges reconciled.
	 */
	public function reconcile( CandidateFieldBindingRelationshipReconciler $adapter, Owner $owner, string $document_key, GraphVersion $version, object $candidate, array $bindings, RelationshipFacts $facts ): object {
		if ( ! $bindings || count( $bindings ) > self::MAX_BINDINGS || $facts->owner_ref() !== $owner->reference() || $facts->document_key() !== $document_key || ! hash_equals( $facts->graph_version_hash(), $version->hash() ) ) {
			$this->reject( 'Field reconciliation requires bounded current native authority.' );
		}
		$base  = json_decode( $this->bytes( $candidate ), false, 64, JSON_THROW_ON_ERROR );
		$nodes = array();
		foreach ( $base->nodes as $node ) {
			$nodes[ $node->id ] = $node; }
		$targets = array();
		foreach ( $bindings as $binding ) {
			$keys = $binding instanceof \stdClass ? array_keys( get_object_vars( $binding ) ) : array();
			sort( $keys, SORT_STRING );
			if ( array( 'before', 'control_path', 'field_id', 'op', 'scope', 'sequence', 'target_id' ) !== $keys || 'bind_field' !== ( $binding->op ?? null ) || ! is_int( $binding->sequence ?? null ) || ! is_string( $binding->target_id ?? null ) || isset( $targets[ $binding->target_id ] ) || ! isset( $nodes[ $binding->target_id ] ) || ! is_string( $binding->control_path ?? null ) || ! is_string( $binding->field_id ?? null ) || ! ( $binding->scope ?? null ) instanceof \stdClass ) {
				$this->reject( 'Field reconciliation accepts unique normalized binding targets only.' );
			}
			$source = $nodes[ $binding->target_id ];
			if ( $this->canonical->encode( $source->scope ) !== $this->canonical->encode( $binding->scope ) || null !== ( $source->opaque_ref ?? null ) ) {
				$this->reject( 'Field reconciliation changed its native scope.' ); }
			$field  = $facts->field( $binding->field_id, $binding->scope->directory_id );
			$policy = $this->policies->component( $source->provider_id, $source->native_id );
			if ( null === $field || ! $field->eligible || null === $policy ) {
				$this->reject( 'Field reconciliation target is unavailable.' ); }
			$after = (object) array( 'field_id' => $binding->field_id );
			$this->policies->validate_binding( $policy, $binding->control_path, $field->type, $after );
			// Native reconciliation rereads source; do not retain the validated policy copy.
			unset( $policy );
			$value = $source->attributes;
			foreach ( explode( '.', $binding->control_path ) as $part ) {
				if ( ! $value instanceof \stdClass || ! property_exists( $value, $part ) ) {
					$this->reject( 'Planned field selector is absent.' ); }
				$value = $value->{$part};
			}
			if ( $this->canonical->encode( $value ) !== $this->canonical->encode( $after ) ) {
				$this->reject( 'Planned field selector differs from its normalized binding.' ); }
			if ( $this->canonical->encode( $binding->before ) === $this->canonical->encode( $after ) ) {
				$this->reject( 'Native field reconciliation cannot authorize a no-op binding.' ); }
			$targets[ $source->id ] = json_decode( $this->bytes( $binding ), false, 64, JSON_THROW_ON_ERROR );
		}
		$result = $adapter->reconcile_field_bindings( $owner, $document_key, $version, $candidate, json_decode( $this->bytes( $bindings ), false, 64, JSON_THROW_ON_ERROR ) );
		if ( ! $result instanceof \stdClass || ! is_array( $result->edges ?? null ) || count( $result->edges ) > 5000 || count( $result->edges ) > count( $base->edges ) + count( $bindings ) ) {
			$this->reject( 'Native field reconciliation exceeded its edge bound.' ); }
		$base_other   = clone $base;
		$result_other = clone $result;
		unset( $base_other->edges, $result_other->edges );
		if ( $this->bytes( $base_other ) !== $this->bytes( $result_other ) ) {
			$this->reject( 'Native field reconciliation changed graph authority or nodes.' ); }
		$untouched = static fn( object $edge ): bool => ! ( 'field' === ( $edge->kind ?? null ) && isset( $targets[ $edge->source ?? '' ] ) );
		if ( $this->bytes( array_values( array_filter( $base->edges, $untouched ) ) ) !== $this->bytes( array_values( array_filter( $result->edges, $untouched ) ) ) ) {
			$this->reject( 'Native field reconciliation changed an unrelated relationship.' ); }
		$counts = array_fill_keys( array_keys( $targets ), 0 );
		foreach ( $result->edges as $edge ) {
			if ( $untouched( $edge ) ) {
				continue; }
			$binding = $targets[ $edge->source ];
			$source  = $nodes[ $edge->source ];
			$target  = $nodes[ $edge->target ?? '' ] ?? null;
			if ( null === $target || $binding->field_id !== $target->native_id || null !== ( $target->opaque_ref ?? null ) || $this->canonical->encode( $target->scope ) !== $this->canonical->encode( $binding->scope ) || $this->canonical->encode( $edge->scope ?? null ) !== $this->canonical->encode( $binding->scope ) || null !== ( $edge->target_document_key ?? null ) || null !== ( $edge->order ?? null ) || 1 !== ++$counts[ $edge->source ] ) {
				$this->reject( 'Native field reconciliation added an undeclared field relationship.' ); }
			$valid = false;
			foreach ( $this->relationships->relationship_rules( 'field', $source->provider_id, $source->native_id, $target->provider_id, $target->native_id ) as $rule ) {
				$field    = $facts->field( $target->native_id, $target->scope->directory_id );
				$expected = (object) array(
					'kind'                => 'field',
					'source'              => $source->id,
					'target'              => $target->id,
					'target_document_key' => null,
					'scope'               => $binding->scope,
					'order'               => null,
					'provenance'          => (object) array(
						'source_ref'        => (object) array(
							'kind'    => $rule->source_ref->kind,
							'id'      => $rule->source_ref->id,
							'version' => $rule->revision,
						),
						'rule_id'           => $rule->id,
						'resolver_revision' => $rule->revision,
					),
				);
				if ( null !== $field && $field->eligible && in_array( $field->type, $rule->field_types, true ) && $this->canonical->encode( $expected ) === $this->canonical->encode( $edge ) ) {
					$valid = true;
					break; }
			}
			if ( ! $valid ) {
				$this->reject( 'Native field reconciliation lacks current registered provenance.' ); }
		}
		if ( in_array( 0, $counts, true ) ) {
			$this->reject( 'Native field reconciliation omitted a declared binding edge.' ); }
		return $result;
	}

	/**
	 * Preserve source ordering and numeric types for immutable-member checks.
	 *
	 * @param mixed $value Trusted graph value.
	 * @return string Lossless JSON bytes.
	 */
	private function bytes( $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ); }
	/**
	 * Reject a delta outside the declared native binding boundary.
	 *
	 * @param string $message Specific invariant failure.
	 * @return void
	 * @throws ContractException Invalid native field relationship.
	 */
	private function reject( string $message ): void {
		throw new ContractException( 'relationship_invalid', $message ); }
}
