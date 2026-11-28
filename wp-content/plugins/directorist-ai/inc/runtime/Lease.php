<?php
/**
 * Fenced worker claim.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

/** Only this run/worker/fence tuple may checkpoint. */
final class Lease {
	/**
	 * Opaque run ID.
	 *
	 * @var string
	 */
	public string $run_id;
	/**
	 * Internal database key.
	 *
	 * @var int
	 */
	public int $internal_id;
	/**
	 * Unique worker attempt ID.
	 *
	 * @var string
	 */
	public string $worker_id;
	/**
	 * Monotonic fencing token.
	 *
	 * @var int
	 */
	public int $fence;
	/**
	 * State version observed at claim.
	 *
	 * @var int
	 */
	public int $state_version;

	/**
	 * Initialize a server-issued claim.
	 *
	 * @param string $run_id Opaque run ID.
	 * @param int    $internal_id Internal database key.
	 * @param string $worker_id Unique worker attempt ID.
	 * @param int    $fence Monotonic fencing token.
	 * @param int    $state_version State version observed at claim.
	 */
	public function __construct( string $run_id, int $internal_id, string $worker_id, int $fence, int $state_version ) {
		$this->run_id        = $run_id;
		$this->internal_id   = $internal_id;
		$this->worker_id     = $worker_id;
		$this->fence         = $fence;
		$this->state_version = $state_version;
	}
}
