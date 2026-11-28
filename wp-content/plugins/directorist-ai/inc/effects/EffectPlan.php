<?php
/**
 * Exact operation identity and reviewed target set.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Effects;

use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Validation\ContractException;

/** A plan is server-created from a reviewed proposal; it is not model input. */
final class EffectPlan {
	/**
	 * Stable operation identity.
	 *
	 * @var string
	 */
	public string $operation_id;
	/**
	 * Server-resolved actor reference.
	 *
	 * @var string
	 */
	public string $actor_ref;
	/**
	 * Registered capability identity.
	 *
	 * @var string
	 */
	public string $capability;
	/**
	 * Registered implementation revision.
	 *
	 * @var string
	 */
	public string $capability_revision;
	/**
	 * Exact reviewed proposal hash.
	 *
	 * @var string
	 */
	public string $proposal_hash;
	/**
	 * Exact ordered target set.
	 *
	 * @var EffectTarget[]
	 */
	public array $targets;
	/**
	 * Owning run database key.
	 *
	 * @var int|null
	 */
	public ?int $run_id;
	/**
	 * Reserved approval database key.
	 *
	 * @var int|null
	 */
	public ?int $approval_id;

	/**
	 * Initialize a bounded, deterministically ordered plan.
	 *
	 * @param string         $operation_id Stable operation identity.
	 * @param string         $actor_ref Server-resolved actor reference.
	 * @param string         $capability Registered capability identity.
	 * @param string         $capability_revision Implementation revision.
	 * @param string         $proposal_hash Exact reviewed proposal hash.
	 * @param EffectTarget[] $targets Exact target set.
	 * @param int|null       $run_id Owning run database key.
	 * @param int|null       $approval_id Reserved approval database key.
	 * @throws ContractException Invalid plan.
	 */
	public function __construct( string $operation_id, string $actor_ref, string $capability, string $capability_revision, string $proposal_hash, array $targets, ?int $run_id = null, ?int $approval_id = null ) {
		ConversationStore::identifier( $operation_id );
		if ( ! preg_match( '/^(?:user:[1-9][0-9]*|guest:[a-f0-9]{64})$/D', $actor_ref ) || ! preg_match( '/^[a-z][a-z0-9]*\.[a-z][a-z0-9_]*$/D', $capability ) || '' === $capability_revision || strlen( $capability_revision ) > 32 || ! preg_match( '/^[a-f0-9]{64}$/D', $proposal_hash ) || ! $targets || count( $targets ) > 20 ) {
			throw new ContractException( 'invalid_input', 'Invalid effect plan.' );
		}
		$indexed = array();
		foreach ( $targets as $target ) {
			if ( ! $target instanceof EffectTarget || isset( $indexed[ $target->key ] ) ) {
				throw new ContractException( 'invalid_input', 'Invalid or duplicate effect target.' );
			}
			$indexed[ $target->key ] = $target;
		}
		ksort( $indexed, SORT_STRING );
		$this->operation_id        = $operation_id;
		$this->actor_ref           = $actor_ref;
		$this->capability          = $capability;
		$this->capability_revision = $capability_revision;
		$this->proposal_hash       = $proposal_hash;
		$this->targets             = array_values( $indexed );
		$this->run_id              = $run_id;
		$this->approval_id         = $approval_id;
	}

	/** Canonical plan data persisted before effects. */
	public function data(): array {
		return array(
			'operation_id'        => $this->operation_id,
			'actor_ref'           => $this->actor_ref,
			'capability'          => $this->capability,
			'capability_revision' => $this->capability_revision,
			'proposal_hash'       => $this->proposal_hash,
			'run_id'              => $this->run_id,
			'approval_id'         => $this->approval_id,
			'targets'             => array_map( static fn( EffectTarget $target ): array => $target->data(), $this->targets ),
		);
	}

	/** Stable identity used to reject changed-body operation reuse. */
	public function hash(): string {
		return hash( 'sha256', json_encode( $this->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}

	/** Hash only the exact ordered target/effect set. */
	public function target_set_hash(): string {
		return hash( 'sha256', json_encode( $this->data()['targets'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}
}
