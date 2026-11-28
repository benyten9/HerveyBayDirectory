<?php
/**
 * Durable token/round reservation made before a provider call.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Reservation values are server-produced and never accepted from input. */
final class UsageReservation {
	/**
	 * Reservation identity.
	 *
	 * @var string
	 */
	public string $id;
	/**
	 * Internal run key.
	 *
	 * @var int
	 */
	public int $run_id;
	/**
	 * Reserved window scopes.
	 *
	 * @var string[]
	 */
	public array $scope_keys;
	/**
	 * UTC daily window.
	 *
	 * @var string
	 */
	public string $window_start;
	/**
	 * Reserved input tokens.
	 *
	 * @var int
	 */
	public int $input_tokens;
	/**
	 * Reserved output tokens.
	 *
	 * @var int
	 */
	public int $output_tokens;

	/**
	 * Bind the counters that must later be settled.
	 *
	 * @param string   $id Reservation identity.
	 * @param int      $run_id Internal run key.
	 * @param string[] $scope_keys Reserved window scopes.
	 * @param string   $window_start UTC daily window.
	 * @param int      $input_tokens Reserved input tokens.
	 * @param int      $output_tokens Reserved output tokens.
	 */
	public function __construct( string $id, int $run_id, array $scope_keys, string $window_start, int $input_tokens, int $output_tokens ) {
		$this->id            = $id;
		$this->run_id        = $run_id;
		$this->scope_keys    = $scope_keys;
		$this->window_start  = $window_start;
		$this->input_tokens  = $input_tokens;
		$this->output_tokens = $output_tokens;
	}
}
