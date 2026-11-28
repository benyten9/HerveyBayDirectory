<?php
/**
 * Route one reviewed native draft effect to its installed builder adapter.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Internal exact artifact routing.

use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Model input never selects a handler; the reviewed private artifact does. */
final class BuilderDraftEffectDispatcher implements EffectHandler {
	private BuilderDraftStageArtifactReader $stages;
	/**
	 * Trusted installed handlers keyed by builder.
	 *
	 * @var array<string,BuilderDraftEffectHandler>
	 */
	private array $handlers;

	/**
	 * Bind only matching installed handler tuples.
	 *
	 * @param Database                                $db Native storage.
	 * @param array<string,BuilderDraftEffectHandler> $handlers Installed native handlers.
	 * @throws ContractException When registration disagrees.
	 */
	public function __construct( Database $db, array $handlers ) {
		if ( ! $handlers ) {
			throw new ContractException( 'unsupported_dependency', 'No native draft handler is installed.' );
		}
		foreach ( $handlers as $builder => $handler ) {
			if ( ! is_string( $builder ) || ! in_array( $builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! $handler instanceof BuilderDraftEffectHandler || $handler->builder() !== $builder ) {
				throw new ContractException( 'unsupported_dependency', 'Native draft handler registration disagrees.' );
			}
		}
		$this->stages   = new BuilderDraftStageArtifactReader( $db );
		$this->handlers = $handlers;
	}

	public function permitted( EffectPlan $plan, Context $context ): bool {
		try {
			return $this->handler( $plan )->permitted( $plan, $context );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public function environment_hash( EffectPlan $plan ): string {
		return $this->handler( $plan )->environment_hash( $plan );
	}

	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		try {
			return $this->handler( $plan )->commit( $plan, $target, $fence );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		}
	}

	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		try {
			return $this->handler( $plan )->reconcile( $plan, $target, $fence );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), $error->error_code() );
		}
	}

	private function handler( EffectPlan $plan ): BuilderDraftEffectHandler {
		$payload = $this->stages->for_plan( $plan )->payload;
		$builder = $payload->source_document->builder ?? null;
		if ( ! is_string( $builder ) || ! isset( $this->handlers[ $builder ] ) ) {
			throw new ContractException( 'unsupported_dependency', 'Reviewed native draft builder is unavailable.' );
		}
		return $this->handlers[ $builder ];
	}
}
