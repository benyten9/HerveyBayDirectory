<?php
/**
 * Prepared, authority-bound grounded response plan.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

/**
 * Internal handoff between retrieval and final live delivery.
 *
 * The initial result is evidence for change detection only. Delivery always
 * executes the plan again and never treats this snapshot as current fact.
 */
final class PreparedGroundedResponse {
	/**
	 * Validated typed retrieval plan.
	 *
	 * @var object
	 */
	public object $plan;
	/**
	 * Initial evidence used only for trace comparison.
	 *
	 * @var object
	 */
	public object $initial_result;
	/**
	 * Preparing actor/profile/conversation digest.
	 *
	 * @var string
	 */
	public string $scope_hash;
	/**
	 * Preparing WordPress site identity.
	 *
	 * @var int
	 */
	public int $site_id;
	/**
	 * Canonical typed-plan digest.
	 *
	 * @var string
	 */
	public string $plan_hash;
	/**
	 * Original question digest without retaining prose.
	 *
	 * @var string
	 */
	public string $question_hash;

	/**
	 * Bind a validated plan to its preparing principal and site.
	 *
	 * @param object $plan Typed retrieval plan.
	 * @param object $initial_result Initial evidence snapshot.
	 * @param string $scope_hash Preparing actor/task digest.
	 * @param int    $site_id Preparing WordPress site.
	 * @param string $plan_hash Canonical plan digest.
	 * @param string $question_hash Original question digest.
	 */
	public function __construct( object $plan, object $initial_result, string $scope_hash, int $site_id, string $plan_hash, string $question_hash ) {
		$this->plan           = $plan;
		$this->initial_result = $initial_result;
		$this->scope_hash     = $scope_hash;
		$this->site_id        = $site_id;
		$this->plan_hash      = $plan_hash;
		$this->question_hash  = $question_hash;
	}
}
