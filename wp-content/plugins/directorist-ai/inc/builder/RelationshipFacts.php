<?php
/**
 * Current trusted native facts used by deterministic graph validation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed fact indexes are intentionally direct.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Facts come from authorized native resolvers, never from a model response. */
final class RelationshipFacts {
	private const MAX_FIELDS           = 2000;
	private const MAX_DEPENDENCIES     = 500;
	private const MAX_BREAKPOINTS      = 100;
	private const MAX_CONTEXTS         = 5000;
	private const MAX_REFERENCES       = 1000;
	private const MAX_REFERENCE_EDGES  = 5000;
	private object $value;
	/** @var array<string,object> */
	private array $fields = array();
	/** @var array<string,array<string,object>> */
	private array $directory_fields = array();
	/** @var array<string,string> */
	private array $directory_revisions = array();
	/** @var array<string,object> */
	private array $dependencies = array();
	/** @var array<string,object> */
	private array $breakpoints = array();
	/** @var array<string,object> */
	private array $contexts = array();
	/** @var array<string,object> */
	private array $references = array();
	private string $revision;

	public function __construct( object $facts ) {
		( new JsonDecoder( 5242880, 32 ) )->check( $facts );
		$facts = json_decode( json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), false, 32, JSON_THROW_ON_ERROR );
		$base_keys = array( 'site_id', 'owner_ref', 'document_key', 'graph_version_hash', 'environment_hash', 'directory_id', 'directory_revision', 'fields', 'dependencies', 'breakpoints', 'contexts', 'references' );
		if ( property_exists( $facts, 'directories' ) ) {
			$base_keys[] = 'directories';
		}
		if ( property_exists( $facts, 'candidate_directory_revisions' ) ) {
			$base_keys[] = 'candidate_directory_revisions';
		}
		$this->closed( $facts, $base_keys );
		if ( ! is_int( $facts->site_id ) || $facts->site_id < 1 || ! is_string( $facts->owner_ref ) ) {
			throw new ContractException( 'unsupported_contract', 'Relationship fact authority is invalid.' );
		}
		GraphVersion::identifier( $facts->owner_ref );
		GraphVersion::identifier( $facts->document_key );
		$this->hash( $facts->graph_version_hash );
		$this->hash( $facts->environment_hash );
		if ( null !== $facts->directory_id ) {
			$this->decimal_id( $facts->directory_id );
		}
		$this->hash( $facts->directory_revision );
		if ( ! is_array( $facts->fields ) || ! is_array( $facts->dependencies ) || ! is_array( $facts->breakpoints ) || ! is_array( $facts->contexts ) || ! is_array( $facts->references ) ) {
			throw new ContractException( 'unsupported_contract', 'Relationship fact shape is invalid.' );
		}
		if ( count( $facts->fields ) > self::MAX_FIELDS || count( $facts->dependencies ) > self::MAX_DEPENDENCIES || count( $facts->breakpoints ) > self::MAX_BREAKPOINTS || count( $facts->contexts ) > self::MAX_CONTEXTS || count( $facts->references ) > self::MAX_REFERENCES ) {
			throw new ContractException( 'needs_expansion', 'Relationship facts exceed their supported bounds.' );
		}
		foreach ( $facts->fields as $field ) {
			$this->closed( $field, array( 'id', 'type', 'eligible' ) );
			GraphVersion::identifier( $field->id );
			GraphVersion::identifier( $field->type );
			if ( ! is_bool( $field->eligible ) ) {
				throw new ContractException( 'unsupported_contract', 'Directory field fact is invalid.' );
			}
			$this->unique( $this->fields, $field->id, $field, 'Directory field fact' );
		}
		if ( property_exists( $facts, 'directories' ) ) {
			if ( ! is_array( $facts->directories ) || ! $facts->directories || count( $facts->directories ) > 16 ) {
				throw new ContractException( 'needs_expansion', 'Directory fact set exceeds its supported bound.' );
			}
			$field_count = 0;
			foreach ( $facts->directories as $directory ) {
				$this->closed( $directory, array( 'directory_id', 'revision', 'fields' ) );
				$this->decimal_id( $directory->directory_id );
				$this->hash( $directory->revision );
				if ( isset( $this->directory_revisions[ $directory->directory_id ] ) || ! is_array( $directory->fields ) ) {
					throw new ContractException( 'unsupported_contract', 'Directory fact set is invalid.' );
				}
				$this->directory_revisions[ $directory->directory_id ] = $directory->revision;
				$this->directory_fields[ $directory->directory_id ] = array();
				foreach ( $directory->fields as $field ) {
					$this->closed( $field, array( 'id', 'type', 'eligible' ) );
					GraphVersion::identifier( $field->id );
					GraphVersion::identifier( $field->type );
					if ( ! is_bool( $field->eligible ) || ++$field_count > self::MAX_FIELDS ) {
						throw new ContractException( 'needs_expansion', 'Directory field facts exceed their supported bound.' );
					}
					$this->unique( $this->directory_fields[ $directory->directory_id ], $field->id, $field, 'Directory field fact' );
				}
				usort( $directory->fields, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
			}
			if ( null !== $facts->directory_id && ! isset( $this->directory_revisions[ $facts->directory_id ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Primary directory fact is unavailable.' );
			}
			// A whole document can contain several explicitly bound directories
			// without choosing one for its root. Such a root has no implicit
			// fields; consumers still require an exact verified directory ID.
			$primary_fields = null === $facts->directory_id ? array() : array_values( $this->directory_fields[ $facts->directory_id ] );
			usort( $primary_fields, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
			$legacy_fields = array_values( $this->fields );
			usort( $legacy_fields, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
			if ( ( new JsonCanonicalizer() )->encode( $primary_fields ) !== ( new JsonCanonicalizer() )->encode( $legacy_fields ) ) {
				throw new ContractException( 'unsupported_contract', 'Primary directory fields disagree with legacy facts.' );
			}
			ksort( $this->directory_revisions, SORT_STRING );
			usort( $facts->directories, static fn( object $left, object $right ): int => strcmp( $left->directory_id, $right->directory_id ) );
			$bindings = array_map( static fn( object $directory ): object => (object) array( 'directory_id' => $directory->directory_id, 'revision' => $directory->revision ), $facts->directories );
			if ( ! hash_equals( $facts->directory_revision, hash( 'sha256', ( new JsonCanonicalizer() )->encode( $bindings ) ) ) ) {
				throw new ContractException( 'stale_version', 'Directory fact revisions disagree with the graph version.' );
			}
		}
		// A candidate may introduce a directory absent from the saved document.
		// These bounded revision bindings affect only the candidate fact hash;
		// they do not grant directory fields or alter the native graph version.
		if ( property_exists( $facts, 'candidate_directory_revisions' ) ) {
			if ( ! is_array( $facts->candidate_directory_revisions ) || ! $facts->candidate_directory_revisions || count( $facts->candidate_directory_revisions ) > 16 ) {
				throw new ContractException( 'needs_expansion', 'Candidate directory revision bindings exceed their supported bound.' );
			}
			$seen_candidate_directories = array();
			foreach ( $facts->candidate_directory_revisions as $binding ) {
				$this->closed( $binding, array( 'directory_id', 'revision' ) );
				$this->decimal_id( $binding->directory_id );
				$this->hash( $binding->revision );
				if ( isset( $seen_candidate_directories[ $binding->directory_id ] ) || $binding->directory_id === $facts->directory_id || isset( $this->directory_revisions[ $binding->directory_id ] ) ) {
					throw new ContractException( 'unsupported_contract', 'Candidate directory revision binding duplicates native authority.' );
				}
				$seen_candidate_directories[ $binding->directory_id ] = true;
			}
			usort( $facts->candidate_directory_revisions, static fn( object $left, object $right ): int => strcmp( $left->directory_id, $right->directory_id ) );
		}
		foreach ( $facts->dependencies as $dependency ) {
			$this->closed( $dependency, array( 'id', 'status', 'version' ) );
			GraphVersion::identifier( $dependency->id );
			if ( ! in_array( $dependency->status, array( 'available', 'missing', 'unsupported' ), true ) || ( null !== $dependency->version && ! is_string( $dependency->version ) ) ) {
				throw new ContractException( 'unsupported_contract', 'Dependency fact is invalid.' );
			}
			if ( null !== $dependency->version ) {
				GraphVersion::identifier( $dependency->version );
			}
			$this->unique( $this->dependencies, $dependency->id, $dependency, 'Dependency fact' );
		}
		foreach ( $facts->breakpoints as $breakpoint ) {
			$this->closed( $breakpoint, array( 'id', 'parent_id' ) );
			GraphVersion::identifier( $breakpoint->id );
			if ( null !== $breakpoint->parent_id ) {
				GraphVersion::identifier( $breakpoint->parent_id );
			}
			$this->unique( $this->breakpoints, $breakpoint->id, $breakpoint, 'Breakpoint fact' );
		}
		$this->breakpoint_acyclic();
		foreach ( $facts->contexts as $context ) {
			$this->closed( $context, array( 'consumer_node_id', 'context_id', 'status', 'provider_node_id', 'resolver_id', 'resolver_revision' ) );
			GraphVersion::identifier( $context->consumer_node_id );
			GraphVersion::identifier( $context->context_id );
			GraphVersion::identifier( $context->resolver_id );
			GraphVersion::identifier( $context->resolver_revision );
			if ( ! in_array( $context->status, array( 'resolved', 'missing', 'ambiguous', 'inaccessible', 'stale' ), true ) || ( 'resolved' === $context->status ) !== is_string( $context->provider_node_id ) ) {
				throw new ContractException( 'unsupported_contract', 'Context resolution fact is invalid.' );
			}
			if ( null !== $context->provider_node_id ) {
				GraphVersion::identifier( $context->provider_node_id );
			}
			$this->unique( $this->contexts, $context->consumer_node_id . '|' . $context->context_id, $context, 'Context resolution fact' );
		}
		$reference_edges = 0;
		foreach ( $facts->references as $reference ) {
			$this->closed( $reference, array( 'document_key', 'status', 'version_hash', 'render_targets' ) );
			GraphVersion::identifier( $reference->document_key );
			if ( ! in_array( $reference->status, array( 'current', 'missing', 'inaccessible', 'stale' ), true ) || ( 'current' === $reference->status ) !== is_string( $reference->version_hash ) || ! is_array( $reference->render_targets ) || count( $reference->render_targets ) > 500 ) {
				throw new ContractException( 'unsupported_contract', 'Reference fact is invalid.' );
			}
			if ( null !== $reference->version_hash ) {
				$this->hash( $reference->version_hash );
			}
			foreach ( $reference->render_targets as $target ) {
				GraphVersion::identifier( $target );
			}
			if ( count( array_unique( $reference->render_targets, SORT_STRING ) ) !== count( $reference->render_targets ) ) {
				throw new ContractException( 'unsupported_contract', 'Reference targets are duplicated.' );
			}
			$reference_edges += count( $reference->render_targets );
			if ( $reference_edges > self::MAX_REFERENCE_EDGES ) {
				throw new ContractException( 'needs_expansion', 'Reference relationship facts exceed their supported bound.' );
			}
			$this->unique( $this->references, $reference->document_key, $reference, 'Reference fact' );
		}
		usort( $facts->fields, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
		usort( $facts->dependencies, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
		usort( $facts->breakpoints, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
		usort( $facts->contexts, static fn( object $left, object $right ): int => array( $left->consumer_node_id, $left->context_id ) <=> array( $right->consumer_node_id, $right->context_id ) );
		usort( $facts->references, static fn( object $left, object $right ): int => strcmp( $left->document_key, $right->document_key ) );
		$this->value    = $facts;
		$this->revision = hash( 'sha256', ( new JsonCanonicalizer() )->encode( $this->value ) );
	}

	public function revision(): string {
		return $this->revision;
	}

	public function site_id(): int {
		return $this->value->site_id;
	}

	public function owner_ref(): string {
		return $this->value->owner_ref;
	}

	public function document_key(): string {
		return $this->value->document_key;
	}

	public function graph_version_hash(): string {
		return $this->value->graph_version_hash;
	}

	public function environment_hash(): string {
		return $this->value->environment_hash;
	}

	public function directory_id(): ?string {
		return $this->value->directory_id;
	}

	public function directory_revision(): string {
		return $this->value->directory_revision;
	}

	public function field( string $id, ?string $directory_id = null ): ?object {
		if ( $this->directory_fields && null === $directory_id ) {
			return null;
		}
		if ( null !== $directory_id && $this->directory_fields ) {
			return isset( $this->directory_fields[ $directory_id ][ $id ] ) ? clone $this->directory_fields[ $directory_id ][ $id ] : null;
		}
		return isset( $this->fields[ $id ] ) ? clone $this->fields[ $id ] : null;
	}

	public function has_directory( ?string $directory_id ): bool {
		if ( null === $directory_id ) {
			return null === $this->value->directory_id;
		}
		return $this->directory_revisions ? null !== $directory_id && isset( $this->directory_revisions[ $directory_id ] ) : $directory_id === $this->value->directory_id;
	}

	/** Verify a selected catalog revision against one member of the full graph binding. */
	public function directory_revision_for( ?string $directory_id ): ?string {
		if ( $this->directory_revisions ) {
			return null !== $directory_id ? ( $this->directory_revisions[ $directory_id ] ?? null ) : null;
		}
		return $directory_id === $this->value->directory_id ? $this->value->directory_revision : null;
	}

	public function dependency( string $id ): ?object {
		return isset( $this->dependencies[ $id ] ) ? clone $this->dependencies[ $id ] : null;
	}

	public function breakpoint( string $id ): ?object {
		return isset( $this->breakpoints[ $id ] ) ? clone $this->breakpoints[ $id ] : null;
	}

	public function context( string $consumer_node_id, string $context_id ): ?object {
		$key = $consumer_node_id . '|' . $context_id;
		return isset( $this->contexts[ $key ] ) ? clone $this->contexts[ $key ] : null;
	}

	/** Return only the closed context facts, in their canonical order. */
	public function contexts(): array {
		return array_map( static fn( object $fact ): object => clone $fact, $this->value->contexts );
	}

	/** Return a detached closed fact snapshot for trusted native candidate resolution. */
	public function snapshot(): object {
		return json_decode( json_encode( $this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), false, 32, JSON_THROW_ON_ERROR );
	}

	public function reference( string $document_key ): ?object {
		return isset( $this->references[ $document_key ] ) ? clone $this->references[ $document_key ] : null;
	}

	/** True when ancestor is the same or a declared native parent of descendant. */
	public function breakpoint_contains( ?string $ancestor, ?string $descendant ): bool {
		if ( $ancestor === $descendant ) {
			return true;
		}
		if ( null === $ancestor || null === $descendant ) {
			return false;
		}
		$cursor = $descendant;
		for ( $depth = 0; $depth <= count( $this->breakpoints ); ++$depth ) {
			$current = $this->breakpoints[ $cursor ] ?? null;
			if ( null === $current || null === $current->parent_id ) {
				return false;
			}
			if ( $current->parent_id === $ancestor ) {
				return true;
			}
			$cursor = $current->parent_id;
		}
		return false;
	}

	private function breakpoint_acyclic(): void {
		$complete = array();
		foreach ( array_keys( $this->breakpoints ) as $id ) {
			if ( isset( $complete[ $id ] ) ) {
				continue;
			}
			$path   = array();
			$cursor = $id;
			while ( isset( $this->breakpoints[ $cursor ] ) && null !== $this->breakpoints[ $cursor ]->parent_id ) {
				if ( ! isset( $this->breakpoints[ $this->breakpoints[ $cursor ]->parent_id ] ) ) {
					throw new ContractException( 'unsupported_contract', 'Breakpoint parent is unavailable.' );
				}
				if ( isset( $path[ $cursor ] ) ) {
					throw new ContractException( 'unsupported_contract', 'Breakpoint inheritance cycle is invalid.' );
				}
				if ( isset( $complete[ $cursor ] ) ) {
					break;
				}
				$path[ $cursor ] = true;
				$cursor          = $this->breakpoints[ $cursor ]->parent_id;
			}
			foreach ( array_keys( $path ) as $visited ) {
				$complete[ $visited ] = true;
			}
		}
	}

	private function closed( $value, array $expected ): void {
		if ( ! $value instanceof \stdClass ) {
			throw new ContractException( 'unsupported_contract', 'Relationship fact object is invalid.' );
		}
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'unsupported_contract', 'Relationship fact shape is invalid.' );
		}
	}

	private function unique( array &$target, string $key, object $value, string $label ): void {
		if ( isset( $target[ $key ] ) ) {
			throw new ContractException( 'unsupported_contract', $label . ' is duplicated.' );
		}
		$target[ $key ] = clone $value;
	}

	private function hash( $value ): void {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new ContractException( 'unsupported_contract', 'Relationship fact hash is invalid.' );
		}
	}

	private function decimal_id( $value ): void {
		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $value ) ) {
			throw new ContractException( 'unsupported_contract', 'Relationship fact directory ID is invalid.' );
		}
	}
}
