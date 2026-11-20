<?php
/**
 * Where a step sits on the way to a goal.
 *
 * A workflow is a tree: root steps ordered by `order`, and condition steps
 * whose children hang off them on a Yes or No branch. A contact who is
 * waiting on step W can jump ahead to goal step S only if W comes before S
 * on the route a contact travels to reach S:
 *
 * - every earlier step in the same container (same parent and branch),
 *   including everything inside earlier conditions (either branch), and
 * - the same, recursively, for each condition S sits inside, plus that
 *   condition itself.
 *
 * Steps after S, and steps on a sibling branch of a condition S sits inside,
 * are not before S: those contacts never pass S, so they do not jump to it.
 *
 * Pure functions over plain step rows, so the rules can be tested without a
 * database. A row needs `id`, `parent_id`, `condition` and `order`.
 *
 * @package DoubleScale\Modules\Automations\Engine
 */

namespace DoubleScale\Modules\Automations\Engine;

defined( 'ABSPATH' ) || exit;

final class GoalPath {

	/**
	 * Whether a contact waiting on `$waiting_id` passes `$goal_id` later on.
	 *
	 * @param array<int, array<string, mixed>> $steps      Step rows of one automation.
	 * @param int                              $waiting_id Step the contact is waiting on.
	 * @param int                              $goal_id    Goal step.
	 * @return bool
	 */
	public static function precedes( array $steps, int $waiting_id, int $goal_id ): bool {
		if ( $waiting_id === $goal_id ) {
			return false;
		}
		$by_id = self::index( $steps );
		if ( ! isset( $by_id[ $waiting_id ], $by_id[ $goal_id ] ) ) {
			return false;
		}

		return isset( self::before( $by_id, $goal_id )[ $waiting_id ] );
	}

	/**
	 * Ids of every step a contact passes before reaching `$goal_id`.
	 *
	 * @param array<int, array<string, mixed>> $steps   Step rows of one automation.
	 * @param int                              $goal_id Goal step.
	 * @return array<int, int>
	 */
	public static function steps_before( array $steps, int $goal_id ): array {
		$by_id = self::index( $steps );
		if ( ! isset( $by_id[ $goal_id ] ) ) {
			return array();
		}

		return array_values( self::before( $by_id, $goal_id ) );
	}

	/**
	 * Position of each step when the workflow is read top to bottom: a
	 * condition, then its Yes branch, then its No branch, then what follows.
	 * Of two goals on the same route, the later one has the higher position.
	 *
	 * @param array<int, array<string, mixed>> $steps Step rows of one automation.
	 * @return array<int, int> step id => position.
	 */
	public static function positions( array $steps ): array {
		$by_id     = self::index( $steps );
		$positions = array();
		$next      = 0;
		self::walk( $by_id, 0, '', $positions, $next );

		return $positions;
	}

	/**
	 * @param array<int, array<string, mixed>> $by_id   Steps keyed by id.
	 * @param int                              $goal_id Goal step.
	 * @return array<int, int> id => id.
	 */
	private static function before( array $by_id, int $goal_id ): array {
		$out  = array();
		$step = $by_id[ $goal_id ];
		$seen = array();

		while ( true ) {
			$parent_id = (int) $step['parent_id'];
			$branch    = $parent_id > 0 ? self::branch( $step ) : '';
			foreach ( self::children( $by_id, $parent_id, $branch ) as $sibling ) {
				if ( (float) $sibling['order'] < (float) $step['order'] ) {
					$out[ (int) $sibling['id'] ] = (int) $sibling['id'];
					foreach ( self::descendants( $by_id, (int) $sibling['id'] ) as $id ) {
						$out[ $id ] = $id;
					}
				}
			}

			if ( $parent_id <= 0 || ! isset( $by_id[ $parent_id ] ) || isset( $seen[ $parent_id ] ) ) {
				break;
			}
			$seen[ $parent_id ] = true;
			$out[ $parent_id ]  = $parent_id;
			$step               = $by_id[ $parent_id ];
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $by_id Steps keyed by id.
	 * @param int                              $id    Step id.
	 * @return array<int, int>
	 */
	private static function descendants( array $by_id, int $id ): array {
		$out   = array();
		$queue = array( $id );
		while ( $queue ) {
			$current = array_shift( $queue );
			foreach ( $by_id as $row ) {
				if ( (int) $row['parent_id'] === $current && ! isset( $out[ (int) $row['id'] ] ) ) {
					$out[ (int) $row['id'] ] = (int) $row['id'];
					$queue[]                 = (int) $row['id'];
				}
			}
		}

		return array_values( $out );
	}

	/**
	 * Children of one container, in workflow order.
	 *
	 * @param array<int, array<string, mixed>> $by_id     Steps keyed by id.
	 * @param int                              $parent_id Parent condition id, 0 for the root.
	 * @param string                           $branch    'yes' or 'no' inside a condition, '' at the root.
	 * @return array<int, array<string, mixed>>
	 */
	private static function children( array $by_id, int $parent_id, string $branch ): array {
		$rows = array_filter(
			$by_id,
			static function ( $row ) use ( $parent_id, $branch ) {
				if ( (int) $row['parent_id'] !== $parent_id ) {
					return false;
				}
				return $parent_id <= 0 || self::branch( $row ) === $branch;
			}
		);
		usort(
			$rows,
			static function ( $a, $b ) {
				return ( (float) $a['order'] <=> (float) $b['order'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] );
			}
		);

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $by_id     Steps keyed by id.
	 * @param int                              $parent_id Container parent.
	 * @param string                           $branch    Container branch.
	 * @param array<int, int>                  $positions Output.
	 * @param int                              $next      Next position.
	 * @return void
	 */
	private static function walk( array $by_id, int $parent_id, string $branch, array &$positions, int &$next ): void {
		foreach ( self::children( $by_id, $parent_id, $branch ) as $row ) {
			$id = (int) $row['id'];
			if ( isset( $positions[ $id ] ) ) {
				continue;
			}
			$positions[ $id ] = $next++;
			self::walk( $by_id, $id, 'yes', $positions, $next );
			self::walk( $by_id, $id, 'no', $positions, $next );
		}
	}

	/**
	 * @param array<string, mixed> $row Step row.
	 * @return string 'yes' | 'no' | ''.
	 */
	private static function branch( array $row ): string {
		$condition = strtolower( (string) ( $row['condition'] ?? '' ) );

		return in_array( $condition, array( 'yes', 'no' ), true ) ? $condition : '';
	}

	/**
	 * @param array<int, mixed> $steps Step rows (arrays or objects with toArray()).
	 * @return array<int, array<string, mixed>>
	 */
	private static function index( array $steps ): array {
		$by_id = array();
		foreach ( $steps as $row ) {
			if ( is_object( $row ) ) {
				$row = method_exists( $row, 'toArray' ) ? $row->toArray() : (array) $row;
			}
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}
			$by_id[ (int) $row['id'] ] = array(
				'id'        => (int) $row['id'],
				'parent_id' => (int) ( $row['parent_id'] ?? 0 ),
				'condition' => (string) ( $row['condition'] ?? '' ),
				'order'     => $row['order'] ?? 0,
			);
		}

		return $by_id;
	}
}
