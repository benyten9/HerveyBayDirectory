<?php
/**
 * Strict authority and selection request for native graph inspection.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Closed request invariants remain beside validation.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** Scope and locators are checked again against the freshly extracted authority graph. */
final class BuilderGraphInspectionRequest {
	private Context $context;
	private object $document;
	private object $scope;
	private array $selection;
	private ?object $directory_facts;
	private ?EditorBinding $editor;
	private CatalogEnvironmentReader $environment;
	private string $environment_hash;

	public function __construct( Context $context, object $document, object $scope, array $selection, ?object $directory_facts, ?EditorBinding $editor, CatalogEnvironmentReader $environment ) {
		$this->exact( $document, array( 'builder', 'native_kind', 'native_id' ) );
		$this->exact( $scope, array( 'directory_id', 'view', 'breakpoint_id', 'interaction' ) );
		if ( ! in_array( $document->builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! is_string( $document->native_kind ) || ! is_string( $document->native_id ) || count( $selection ) > 100 ) {
			throw new ContractException( 'invalid_input', 'Builder graph inspection target is invalid.' );
		}
		GraphVersion::identifier( $document->native_kind );
		GraphVersion::identifier( $document->native_id );
		if ( ( null !== $scope->directory_id && ( ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $scope->directory_id ) ) ) || ! in_array( $scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || ! in_array( $scope->interaction, array( null, 'normal', 'hover' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Builder graph inspection scope is invalid.' );
		}
		if ( null !== $scope->breakpoint_id ) {
			GraphVersion::identifier( $scope->breakpoint_id );
		}
		$fact_directory = null === $directory_facts ? null : (string) $directory_facts->directory_id;
		if ( $scope->directory_id !== $fact_directory ) {
			throw new ContractException( 'scope_conflict', 'Requested graph scope differs from current directory authority.' );
		}
		foreach ( $selection as $locator ) {
			$this->locator( $locator, $document );
		}
		$this->context          = $context;
		$this->document         = clone $document;
		$this->scope            = clone $scope;
		$this->selection        = array_values( $selection );
		$this->directory_facts  = $directory_facts;
		$this->editor           = $editor;
		$this->environment      = $environment;
		$this->environment_hash = $environment->read()->fingerprint();
	}

	public function context(): Context {
		return $this->context; }
	public function document(): object {
		return clone $this->document; }
	public function scope(): object {
		return clone $this->scope; }
	/** Return detached locator values for exact request-local reuse identity. */
	public function selection(): array {
		return json_decode( json_encode( $this->selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
	}
	public function directory_facts(): ?object {
		return $this->directory_facts; }
	public function editor(): ?EditorBinding {
		return $this->editor; }
	public function environment_hash(): string {
		return $this->environment_hash; }
	public function assert_environment_current(): void {
		if ( ! hash_equals( $this->environment_hash, $this->environment->read()->fingerprint() ) ) {
			throw new ContractException( 'stale_version', 'Builder catalog environment changed during graph inspection.' );
		}
	}

	/** Reject stale locators and a requested scoped region absent from current authority. */
	public function assert_result( BuilderGraphResult $result ): void {
		$nodes       = $result->selection_index();
		$canonical   = new JsonCanonicalizer();
		$scope_found = false;
		$locators    = array();
		foreach ( $nodes as $node ) {
			$scope_found                                      = $scope_found || $canonical->encode( $node->scope ) === $canonical->encode( $this->scope );
			$locators[ $canonical->encode( $node->locator ) ] = $canonical->encode( $node->scope );
		}
		if ( ! $scope_found && null === BuilderElementorLoopCreationProof::for_saved_page( $this, $result ) ) {
			throw new ContractException( 'scope_conflict', 'Requested graph scope is unavailable in the current document.' );
		}
		foreach ( $this->selection as $locator ) {
			$key = $canonical->encode( $locator );
			if ( ! isset( $locators[ $key ] ) ) {
				throw new ContractException( 'stale_version', 'Selected graph locator is stale or outside the current document.' );
			}
			if ( $locators[ $key ] !== $canonical->encode( $this->scope ) ) {
				throw new ContractException( 'scope_conflict', 'Selected graph locator belongs to another scope.' );
			}
		}
	}

	private function locator( $locator, object $document ): void {
		if ( ! $locator instanceof \stdClass ) {
			throw new ContractException( 'invalid_input', 'Graph selection locator is invalid.' );
		}
		$this->exact( $locator, array( 'document', 'snapshot_hash', 'slot_path', 'native_id', 'editor_epoch' ) );
		$this->exact( $locator->document, array( 'builder', 'native_kind', 'native_id' ) );
		$canonical = new JsonCanonicalizer();
		if ( $canonical->encode( $locator->document ) !== $canonical->encode( $document ) || ! is_string( $locator->snapshot_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $locator->snapshot_hash ) || ! is_array( $locator->slot_path ) || ! $locator->slot_path || count( $locator->slot_path ) > 32 ) {
			throw new ContractException( 'invalid_input', 'Graph selection locator is invalid.' );
		}
		foreach ( $locator->slot_path as $part ) {
			GraphVersion::identifier( $part ); }
		if ( null !== $locator->native_id ) {
			GraphVersion::identifier( $locator->native_id ); }
		if ( null !== $locator->editor_epoch ) {
			GraphVersion::identifier( $locator->editor_epoch ); }
	}

	private function exact( $value, array $keys ): void {
		if ( ! $value instanceof \stdClass ) {
			throw new ContractException( 'invalid_input', 'Builder graph request object is invalid.' );
		}
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		if ( $actual !== $keys ) {
			throw new ContractException( 'invalid_input', 'Builder graph request shape is invalid.' ); }
	}
}
