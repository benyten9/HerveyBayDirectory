<?php
/**
 * Common capability boundary for UI, workers, native abilities and MCP.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Capabilities;

use DirectoristAIPro\Storage\Readiness;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;

/** Validate, authorize, execute and validate output under the actual actor. */
final class Gateway {
	/**
	 * Trusted operations.
	 *
	 * @var Registry
	 */
	private Registry $registry;
	/**
	 * Strict contract validator.
	 *
	 * @var ContractValidator
	 */
	private ContractValidator $validator;
	/**
	 * Write gate; missing means all non-read calls are unavailable.
	 *
	 * @var EffectExecutor|null
	 */
	private ?EffectExecutor $effects;
	/**
	 * Production runtime storage boundary.
	 *
	 * @var Readiness|null
	 */
	private ?Readiness $readiness;

	/**
	 * Bind one registry and the optional guarded effect layer.
	 *
	 * @param Registry            $registry Trusted operation registry.
	 * @param ContractValidator   $validator Strict input/output validator.
	 * @param EffectExecutor|null $effects Effect authorization and receipts.
	 * @param Readiness|null      $readiness Required in the installed runtime; optional for isolated contract consumers.
	 */
	public function __construct( Registry $registry, ContractValidator $validator, ?EffectExecutor $effects = null, ?Readiness $readiness = null ) {
		$this->registry  = $registry;
		$this->validator = $validator;
		$this->effects   = $effects;
		$this->readiness = $readiness;
	}

	/**
	 * Validate a nested in-process handoff using its registered native contract.
	 *
	 * @param string $id Registered operation.
	 * @param string $json Original JSON input.
	 * @return object Strict input; validation grants no execution authority.
	 */
	public function validate_input( string $id, string $json ): object {
		return $this->validator->decode( $json, $this->registry->get( $id )->metadata()['input_schema'] );
	}

	/**
	 * Validate a server-produced response using its registered native contract.
	 *
	 * @param string $id Registered operation.
	 * @param object $result Server-produced result.
	 * @return object Validated response.
	 * @throws ContractException Invalid server output.
	 */
	public function validate_output( string $id, object $result ): object {
		try {
			return $this->validator->validate( $result, $this->registry->get( $id )->metadata()['output_schema'] );
		} catch ( ContractException $error ) {
			throw new ContractException( 'invalid_output', 'Operation result could not be verified.' );
		}
	}

	/**
	 * Expose only currently eligible tools; this is never an execution grant.
	 *
	 * @param Context $context Current server context.
	 * @return array Safe public metadata.
	 */
	public function describe( Context $context ): array {
		return $this->as_actor(
			$context,
			function () use ( $context ): array {
				$result = array();
				$ready  = null === $this->readiness || $this->readiness->status()['effects_enabled'];
				foreach ( $this->registry->all() as $operation ) {
					$metadata = $operation->metadata();
					if ( ( ! $ready && 'runtime.status' !== $metadata['id'] ) || ( 'write' === $metadata['effect'] && null === $this->effects ) || ! $operation->discoverable( $context ) ) {
						continue;
					}
					$result[] = array(
						'id'           => $metadata['id'],
						'revision'     => $metadata['revision'],
						'description'  => $metadata['description'],
						'effect'       => $metadata['effect'],
						'input_schema' => $this->registry->schema( $metadata['input_schema'] ),
					);
				}
				$context->assert_execution_identity();
				return $result;
			}
		);
	}

	/**
	 * Validate exact original JSON before any native/domain callback.
	 *
	 * @param string  $id Registered operation.
	 * @param string  $json Original JSON input, not normalized REST params.
	 * @param Context $context Trusted server authority.
	 * @return mixed Strictly validated result.
	 * @throws ContractException Invalid input, denied authority or unknown effect.
	 */
	public function execute( string $id, string $json, Context $context ) {
		$operation = $this->registry->get( $id );
		$metadata  = $operation->metadata();
		$input     = $this->validator->decode( $json, $metadata['input_schema'] );
		return $this->as_actor(
			$context,
			function () use ( $operation, $metadata, $input, $context ) {
				if ( ! $operation->available( $context ) ) {
					throw new ContractException( 'unsupported_dependency', 'Required operation dependencies are unavailable.' );
				}
				if ( ! $operation->permitted( $input, $context ) ) {
					throw new ContractException( 'unauthorized', 'Operation is not permitted in this context.' );
				}
				$context->assert_execution_identity();
				if ( 'write' === $metadata['effect'] && null === $this->effects ) {
					throw new ContractException( 'approval_required', 'Guarded effect execution is not available.' );
				}
				if ( null !== $this->readiness && 'runtime.status' !== $metadata['id'] ) {
					$this->readiness->assert_ready();
				}
				try {
					$result = 'write' === $metadata['effect'] ? $this->effects->execute( $operation, $input, $context ) : $operation->invoke( $input, $context );
				} catch ( ContractException $error ) {
					throw $error;
				} catch ( \Throwable $error ) {
					throw new ContractException( 'read' === $metadata['effect'] ? 'unsupported_dependency' : 'unknown_outcome', 'Operation could not complete. Its effect must be verified before retry.' );
				}
				try {
					$context->actor()->refresh();
					$context->assert_execution_identity();
					$this->set_current_actor( $context->actor()->user_id() );
					if ( ! $operation->permitted( $input, $context ) ) {
						throw new ContractException( 'unauthorized', 'Authority changed before result delivery.' );
					}
				} catch ( ContractException $error ) {
					if ( 'read' !== $metadata['effect'] ) {
						throw new ContractException( 'unknown_outcome', 'Authority changed after execution. Inspect the authorized receipt before retry.' );
					}
					throw $error;
				}
				try {
					return $this->validator->validate( $result, $metadata['output_schema'] );
				} catch ( ContractException $error ) {
					throw new ContractException( 'read' === $metadata['effect'] ? 'invalid_output' : 'unknown_outcome', 'Operation result could not be verified.' );
				}
			}
		);
	}

	/**
	 * Native helpers see the initiating actor, with previous identity restored.
	 *
	 * @param Context  $context Server authority.
	 * @param callable $callback Guarded work.
	 * @return mixed Callback output.
	 */
	private function as_actor( Context $context, callable $callback ) {
		$context->actor()->refresh();
		$context->assert_current();
		$previous = get_current_user_id();
		$this->set_current_actor( $context->actor()->user_id() );
		try {
			return $callback();
		} finally {
			$this->set_current_actor( $previous );
		}
	}
	/**
	 * Refresh WordPress's global user cache without an intermediate guest action.
	 *
	 * The wp_set_current_user function returns its cached object when the ID is unchanged;
	 * clearing that cache first ensures native helpers see fresh capabilities.
	 * No login/logout cookie or session operation occurs here.
	 *
	 * @param int $user_id Verified execution or previous identity.
	 * @return void
	 */
	private function set_current_actor( int $user_id ): void {
		if ( $user_id ) {
			clean_user_cache( $user_id );
		}
		unset( $GLOBALS['current_user'] );
		wp_set_current_user( $user_id );
	}
}
