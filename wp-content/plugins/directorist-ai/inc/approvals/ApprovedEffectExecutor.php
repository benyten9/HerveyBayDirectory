<?php
/**
 * Gateway write executor requiring a reserved exact approval.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Approvals;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\EffectExecutor;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Effects\EffectLedger;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;

/** No model/client Boolean or confirmation text can create this permit. */
final class ApprovedEffectExecutor implements EffectExecutor {
	/**
	 * Human approval lifecycle.
	 *
	 * @var ApprovalService
	 */
	private ApprovalService $approvals;
	/**
	 * Trusted native handler registry.
	 *
	 * @var EffectHandlerRegistry
	 */
	private EffectHandlerRegistry $handlers;
	/**
	 * Durable idempotent effect ledger.
	 *
	 * @var EffectLedger
	 */
	private EffectLedger $ledger;

	/**
	 * Bind the review lifecycle, handlers and ledger.
	 *
	 * @param ApprovalService       $approvals Human approval lifecycle.
	 * @param EffectHandlerRegistry $handlers Trusted native handlers.
	 * @param EffectLedger          $ledger Durable effect ledger.
	 */
	public function __construct( ApprovalService $approvals, EffectHandlerRegistry $handlers, EffectLedger $ledger ) {
		$this->approvals = $approvals;
		$this->handlers  = $handlers;
		$this->ledger    = $ledger;
	}

	/**
	 * Execute only an exact server-approved write.
	 *
	 * @param Operation $operation Registered write operation.
	 * @param object    $input Validated operation input.
	 * @param Context   $context Server-resolved execution context.
	 * @return object Durable receipt.
	 * @throws ContractException Approval or handler contract is unavailable.
	 */
	public function execute( Operation $operation, object $input, Context $context ) {
		if ( get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
			throw new ContractException( 'provider_unavailable', 'AI effects are paused by the site administrator.' );
		}
		if ( ! isset( $input->approval_id, $input->operation_id, $input->review_hash ) || ! is_string( $input->approval_id ) || ! is_string( $input->operation_id ) || ! is_string( $input->review_hash ) ) {
			throw new ContractException( 'approval_required', 'Exact approval and operation references are required.' );
		}
		$metadata = $operation->metadata();
		$handler  = $this->handlers->get( $metadata['id'] );
		// Load once to reconstruct the plan, then compare handler-derived environment.
		$review = $this->approvals->get( $context->actor(), $input->approval_id );
		if ( $review['review_hash'] !== $input->review_hash ) {
			throw new ContractException( 'approval_mismatch', 'Approval review hash does not match.' );
		}
		$review_plan = $review['review']->plan;
		if ( $review_plan->capability !== $metadata['id'] || $review_plan->capability_revision !== $metadata['revision'] ) {
			throw new ContractException( 'approval_mismatch', 'Approval capability revision does not match the handler.' );
		}
		if ( in_array( $review['state'], array( 'reserved', 'consumed' ), true ) && strtotime( $review['expires_at'] ) <= time() ) {
			try {
				$existing = $this->ledger->receipt( $input->operation_id, $context->actor()->owner()->reference() );
			} catch ( StorageException $error ) {
				throw new ContractException( 'approval_expired', 'Expired reservation has no prior effect to inspect.' );
			}
			if ( 'pending' === $existing['state'] ) {
				throw new ContractException( 'approval_expired', 'Expired reservation cannot start a new effect.' );
			}
		}
		$targets = array_map( static fn( object $target ): \DirectoristAIPro\Effects\EffectTarget => new \DirectoristAIPro\Effects\EffectTarget( $target->key, $target->expected_version, $target->effect_hash ), $review_plan->targets );
		$preview = new \DirectoristAIPro\Effects\EffectPlan( $input->operation_id, $context->actor()->owner()->reference(), $metadata['id'], $metadata['revision'], $review['review']->artifact->hash, $targets, $review_plan->run_id, null );
		if ( ! $handler->permitted( $preview, $context ) ) {
			throw new ContractException( 'unauthorized', 'Native target authority changed after review.' );
		}
		$environment = $handler->environment_hash( $preview );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $environment ) ) {
			throw new ContractException( 'unsupported_dependency', 'Effect handler returned an invalid environment identity.' );
		}
		$permit = $this->approvals->reserve( $context->actor(), $input->approval_id, $input->review_hash, $input->operation_id, $environment );
		if ( $permit->plan->capability !== $metadata['id'] || $permit->plan->capability_revision !== $metadata['revision'] ) {
			throw new ContractException( 'approval_mismatch', 'Reserved approval does not match this operation.' );
		}
		if ( ! $handler->permitted( $permit->plan, $context ) ) {
			throw new ContractException( 'unauthorized', 'Native target authority changed before execution.' );
		}
		$receipt = $this->ledger->execute( $permit->plan, $handler );
		$this->approvals->complete( $permit, $receipt );
		return json_decode( json_encode( $receipt, JSON_THROW_ON_ERROR ), false, 64, JSON_THROW_ON_ERROR );
	}
}
