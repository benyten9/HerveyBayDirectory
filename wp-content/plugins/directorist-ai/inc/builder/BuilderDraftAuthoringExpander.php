<?php
/**
 * Optional installed native recipe expansion before ordinary draft validation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

/** Expansion is pure; the full returned changes still require every planner check. */
interface BuilderDraftAuthoringExpander {

	/**
	 * Expand only declared native recipes against this freshly inspected graph.
	 *
	 * @param object $graph Fresh saved native graph.
	 * @param array  $changes Untrusted semantic changes.
	 */
	public function expand_authoring_changes( object $graph, array $changes ): array;
}
