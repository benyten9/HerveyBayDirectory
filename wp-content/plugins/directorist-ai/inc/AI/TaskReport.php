<?php
/**
 * Bounded safe reporting from independently retained logical-task receipts.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

use DirectoristAIPro\Storage\Database;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.FunctionComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed reporting projection.

/** Task totals and model attempts are different measures; no balance is inferred. */
final class TaskReport {
	public static function read( Database $db, int $days, string $source, string $feature ): array {
		$where = 'created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY)';
		$args  = array( $days );
		foreach ( array( 'source' => $source, 'feature' => $feature ) as $column => $value ) {
			if ( 'all' !== $value ) {
				$where .= " AND $column=%s";
				$args[] = $value; }
		}
		$rows   = $db->rows( 'SELECT task_id,run_id,parent_task_id,logical_task,feature,source,state,outcome,receipt_json,created_at FROM ' . $db->table( 'task_receipts' ) . " WHERE $where ORDER BY created_at DESC,task_id DESC LIMIT 1001", $args );
		$report = array(
			'totals'        => array( 'tasks' => 0, 'completed' => 0, 'released' => 0, 'uncertain' => 0, 'active' => 0, 'model_calls' => 0, 'repair_calls' => 0, 'quoted_units' => 0, 'settled_units' => 0, 'unpriced_tasks' => 0 ),
			'activity'      => array(),
			'truncated'     => count( $rows ) > 1000,
			'coverage'      => 'Latest 1,000 independently retained task receipts admitted in the selected window. Latest 250 task receipts shown. Clear history does not delete these records. Token capacity limits and native fees are not service credits.',
			'authoritative' => false,
			'provider_cost' => null,
		);
		$valid_receipts = 0;
		foreach ( array_slice( $rows, 0, 1000 ) as $row ) {
			$data = json_decode( $row['receipt_json'], true );
			if ( ! is_array( $data ) || ! isset( $data['policy'], $data['calls'] ) ) {
				continue; }
			++$valid_receipts;
			$continuation = 'visitor' === $row['feature'] && 'chat_turn' === $row['logical_task'] && null !== $row['parent_task_id'] && str_starts_with( $row['task_id'], 'visitor-evidence-' ) && 'visitor_evidence_v1' === ( $data['classification']['continuation'] ?? null );
			if ( ! $continuation ) { ++$report['totals']['tasks']; }
			$key = in_array( $row['state'], array( 'completed', 'released', 'uncertain' ), true ) ? $row['state'] : 'active';
			if ( ! $continuation ) { ++$report['totals'][ $key ]; }
			$report['totals']['model_calls']  += count( $data['calls'] );
			$repairs                           = count( array_filter( $data['calls'], static fn( array $call ): bool => true === ( $call['repair'] ?? false ) ) );
			$report['totals']['repair_calls'] += $repairs;
			if ( ! $continuation && null === $data['policy']['quoted_units'] ) {
				++$report['totals']['unpriced_tasks']; }
			$report['totals']['quoted_units']  += $data['policy']['quoted_units'] ?? 0;
			$report['totals']['settled_units'] += $data['settled_units'] ?? 0;
			$calls                              = array();
			foreach ( $data['calls'] as $call_id => $call ) {
				$calls[] = array( 'id' => $call_id, 'state' => $call['state'], 'repair' => $call['repair'] ) + ( $call['metadata'] ?? array( 'usage_reported' => false, 'input_tokens' => null, 'output_tokens' => null, 'reported_model' => null ) );
			}
			if ( 'managed' === $row['source'] ) {
				$calls = ManagedService::customer_response( $calls );
			}
			if ( count( $report['activity'] ) < 250 ) {
				$report['activity'][] = array_intersect_key( $row, array_flip( array( 'task_id', 'run_id', 'parent_task_id', 'logical_task', 'feature', 'source', 'state', 'outcome', 'created_at' ) ) ) + array( 'mode' => $data['policy']['mode'], 'rate_version' => $data['policy']['version'], 'tier' => $data['policy']['tier'], 'quoted_units' => $data['policy']['quoted_units'], 'reserved_units' => $data['reserved_units'], 'settled_units' => $data['settled_units'], 'reconciliation' => $data['reconciliation'], 'images' => $data['classification']['images'], 'calls' => $calls, 'repair_calls' => $repairs, 'provider_cost' => null );
			}
		}
		if ( $report['totals']['unpriced_tasks'] ) {
			$report['totals']['quoted_units']  = null;
			$report['totals']['settled_units'] = null; }
		$report['activity_truncated'] = $valid_receipts > count( $report['activity'] );
		return $report;
	}
}
