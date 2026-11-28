<?php
/**
 * Frozen installed adapters for separately reviewed native template assignment.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Trusted bootstrap registry.

use DirectoristAIPro\Validation\ContractException;

/** A request or model cannot register an adapter or choose its implementation. */
final class BuilderTemplateAssignmentRegistry {
	private array $adapters = array();
	private bool $frozen    = false;
	private const MARKERS   = array(
		'gutenberg' => '_directorist_ai_draft_operation',
		'elementor' => '_directorist_ai_elementor_draft_operation',
		'divi'      => '_directorist_ai_divi_draft_operation',
	);

	public static function installed(): self {
		$registry = new self();
		do_action( 'directorist_ai_pro_register_builder_template_assignment_adapters', $registry );
		$registry->freeze();
		return $registry;
	}

	public function register( BuilderTemplateAssignmentAdapter $adapter ): void {
		$builder = $adapter->builder();
		if ( $this->frozen || ! isset( self::MARKERS[ $builder ] ) || isset( $this->adapters[ $builder ] ) ) {
			throw new ContractException( 'scope_conflict', 'Native template assignment adapter registration conflicts.' );
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

	public function qualify( int $draft_id, string $stage_operation ): string {
		$present = array();
		foreach ( self::MARKERS as $builder => $key ) {
			$value = get_post_meta( $draft_id, $key, true );
			if ( '' !== $value ) {
				$present[ $builder ] = $value;
			}
		}
		if ( ! $this->frozen || 1 !== count( $present ) || reset( $present ) !== $stage_operation ) {
			throw new ContractException( 'evidence_stale', 'Native template stage identity is missing, changed or ambiguous.' );
		}
		$builder = array_key_first( $present );
		if ( ! isset( $this->adapters[ $builder ] ) ) {
			throw new ContractException( 'unsupported_dependency', 'The native template assignment adapter is unavailable.' );
		}
		return $builder;
	}

	public function adapter( string $builder ): BuilderTemplateAssignmentAdapter {
		if ( ! $this->frozen || ! isset( $this->adapters[ $builder ] ) ) {
			throw new ContractException( 'unsupported_dependency', 'The native template assignment adapter is unavailable.' );
		}
		return $this->adapters[ $builder ];
	}
}
