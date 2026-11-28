<?php
/**
 * Frozen installed adapters for separately reviewed native page publication.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Trusted bootstrap registry.

use DirectoristAIPro\Validation\ContractException;

/** A request or model cannot supply an adapter or select its implementation. */
final class BuilderPagePublicationRegistry {

	private array $adapters = array();
	private bool $frozen    = false;
	private const MARKERS   = array(
		'gutenberg' => '_directorist_ai_draft_operation',
		'elementor' => '_directorist_ai_elementor_draft_operation',
		'divi'      => '_directorist_ai_divi_draft_operation',
	);

	public static function installed(): self {
		$registry = new self();
		do_action( 'directorist_ai_pro_register_builder_page_publication_adapters', $registry );
		$registry->freeze();
		return $registry;
	}

	public function register( BuilderPagePublicationAdapter $adapter ): void {
		$builder = $adapter->builder();
		if ( $this->frozen || ! isset( self::MARKERS[ $builder ] ) || isset( $this->adapters[ $builder ] ) ) {
			throw new ContractException( 'scope_conflict', 'Native page publication adapter registration conflicts.' );
		}
		$this->adapters[ $builder ] = $adapter;
	}

	public function freeze(): void {
		ksort( $this->adapters, SORT_STRING );
		$this->frozen = true;
	}

	public function available(): bool {
		return $this->frozen && ! empty( $this->adapters );
	}

	/** Exactly one stage marker and its installed adapter determine the builder. */
	public function qualify( int $page_id, string $operation ): string {
		$present = array();
		foreach ( self::MARKERS as $builder => $key ) {
			$value = get_post_meta( $page_id, $key, true );
			if ( '' !== $value ) {
				$present[ $builder ] = $value;
			}
		}
		if ( 1 !== count( $present ) || reset( $present ) !== $operation ) {
			throw new ContractException( 'evidence_stale', 'Native page stage identity is missing, changed or ambiguous.' );
		}
		$builder = array_key_first( $present );
		$this->assert_ready( $builder, $page_id, $operation );
		return $builder;
	}

	public function assert_ready( string $builder, int $page_id, string $operation ): void {
		if ( ! $this->frozen || ! isset( $this->adapters[ $builder ] ) || 'page' !== get_post_type( $page_id ) ) {
			throw new ContractException( 'unsupported_dependency', 'The native page publication adapter is unavailable.' );
		}
		$this->adapters[ $builder ]->assert_ready( $page_id, $operation );
	}

	public function dependency_version( string $builder, int $page_id ): string {
		if ( ! $this->frozen || ! isset( $this->adapters[ $builder ] ) ) {
			throw new ContractException( 'unsupported_dependency', 'The native page publication adapter is unavailable.' );
		}
		$version = $this->adapters[ $builder ]->dependency_version( $page_id );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $version ) ) {
			throw new ContractException( 'unsupported_contract', 'The native publication dependency revision is invalid.' );
		}
		return $version;
	}
}
